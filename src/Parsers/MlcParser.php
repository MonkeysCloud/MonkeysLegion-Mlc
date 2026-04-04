<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers;

use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;
use MonkeysLegion\Mlc\Exception\CircularDependencyException;

use JsonException;
use MonkeysLegion\Env\Contracts\EnvBootstrapperInterface;
use MonkeysLegion\Env\Contracts\EnvRepositoryInterface;
use MonkeysLegion\Mlc\Parsers\Traits\FileSecurityTrait;
use MonkeysLegion\Mlc\Contracts\ParserInterface;

/**
 * Production-grade MLC Parser
 *
 * Parses .mlc configuration files into a nested PHP array.
 * Supports both `key = value` and `key  value` syntaxes,
 * as well as multi-line JSON arrays.
 *
 * Features:
 * - Detailed error messages with line numbers
 * - Security checks (path traversal, file permissions)
 * - Circular reference detection
 * - Performance optimizations
 */
final class MlcParser implements ParserInterface
{
    // ── Configuration ──────────────────────────────────────────

    use FileSecurityTrait;

    private const int MAX_DEPTH = 50;

    // ── State ──────────────────────────────────────────────────

    private int $currentLine = 0;

    private string $currentFile = '';

    /**
     * Stack of files currently being parsed to detect circular inclusions.
     * @var string[]
     */
    private array $includeStack = [];

    /**
     * List of all files parsed during the last operation (including recursive includes).
     * @var string[]
     */
    private array $allParsedFiles = [];

    /**
     * Optional delegate parser to handle includes of other formats (JSON, YAML, etc.).
     */
    private ?ParserInterface $delegate = null;

    /**
     * Environment repository to resolve environment variables.
     */
    private EnvRepositoryInterface $env;

    // ── Constructor ──────────────────────────────────────────────

    public function __construct(
        private EnvBootstrapperInterface $envBootstrapper,
        string $root,
    ) {
        if (!$envBootstrapper->isBooted()) {
            $envBootstrapper->boot($root);
        }
        $this->env = $envBootstrapper->getRepository();
    }

    // ── Public API ──────────────────────────────────────────────

    /**
     * Set a delegate parser to handle cross-format includes.
     *
     * @param ParserInterface $delegate
     * @return $this
     */
    public function setDelegate(ParserInterface $delegate): self
    {
        $this->delegate = $delegate;
        return $this;
    }

    /**
     * Enable or disable strict security checks.
     *
     * @param bool $strict Whether to enable strict security
     */
    public function enableStrictSecurity(bool $strict = true): self
    {
        $this->strictSecurity = $strict;
        return $this;
    }

    /**
     * Parse a .mlc configuration file.
     *
     * @param string $file Path to the .mlc file
     * @return array<string, mixed> Parsed configuration data
     * @throws ParserException|SecurityException
     */
    public function parseFile(string $file): array
    {
        $this->allParsedFiles = [];
        $this->includeStack = [$file];
        $data = $this->parseFileInternal($file);
        $this->includeStack = [];

        // Final resolution pass for cross-file references
        $this->resolveReferences($data, $data);

        return $data;
    }

    /**
     * Internal parse method that doesn't trigger final reference resolution.
     *
     * @param string $file
     * @return array<string, mixed>
     */
    private function parseFileInternal(string $file): array
    {
        $oldFile = $this->currentFile;
        $oldLine = $this->currentLine;

        try {
            $this->currentFile = $file;
            $this->currentLine = 0;

            // Security checks
            $this->validateFileSecurity($file);

            // Add to the list of all parsed files for cache tracking
            $realPath = realpath($file);
            if ($realPath && !in_array($realPath, $this->allParsedFiles, true)) {
                $this->allParsedFiles[] = $realPath;
            }

            $content = @file_get_contents($file);
            if ($content === false) {
                throw new ParserException(
                    "Failed to read config file: {$file}",
                    0,
                    $file
                );
            }

            return $this->parseContent($content, $file, false);
        } finally {
            $this->currentFile = $oldFile;
            $this->currentLine = $oldLine;
        }
    }

    /**
     * Parse MLC content from a string.
     *
     * @param string $content MLC content
     * @param string $filename Optional filename for error messages
     * @param bool   $resolveReferences Whether to resolve variable references
     * @return array<string, mixed> Parsed configuration data
     * @throws ParserException
     */
    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array
    {
        // Reset allParsedFiles if this is the start of a parsing operation from a string
        if ($filename === '<string>' && empty($this->includeStack)) {
            $this->allParsedFiles = [];
        }
        $this->currentFile = $filename;
        $this->currentLine = 0;

        $lines = explode("\n", $content);
        $data  = [];
        $stack = [&$data];
        $depth = 0;

        // State for multi-line array parsing
        $inArray  = false;
        $arrayKey = null;
        $arrayRaw = '';
        $arrayStartLine = 0;

        foreach ($lines as $lineNum => $raw) {
            $this->currentLine = $lineNum + 1;
            $line = trim($raw);

            // If we're in the middle of a multi-line array, accumulate
            if ($inArray) {
                $arrayRaw .= ' ' . $line;
                if (str_ends_with($line, ']')) {
                    try {
                        $current = &$stack[count($stack) - 1];
                        $current[$arrayKey] = $this->parseValue($arrayRaw);
                    } catch (\Throwable $e) {
                        throw new ParserException(
                            "Invalid array starting at line {$arrayStartLine}: {$e->getMessage()}",
                            $this->currentLine,
                            $this->currentFile,
                            $e
                        );
                    }
                    // Reset state
                    $inArray  = false;
                    $arrayKey = null;
                    $arrayRaw = '';
                    $arrayStartLine = 0;
                }
                continue;
            }

            // Skip empty lines & comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Recursive include: @include "other.mlc", @include <other.mlc>, @include other.mlc
            if (preg_match('/^@include\s+(?P<f>".+?"|\'.+?\'|<.+?>|[^\s"\'<>{}]+)$/', $line, $m)) {
                $rawFile = $m['f'];

                // Remove wrappers if present (" ", ' ', < >)
                $first = $rawFile[0] ?? '';
                $last = substr($rawFile, -1);

                if (($first === '"' && $last === '"') ||
                    ($first === "'" && $last === "'") ||
                    ($first === '<' && $last === '>')
                ) {
                    $includeFile = substr($rawFile, 1, -1);
                } else {
                    $includeFile = $rawFile;
                }
                $fullPath = $this->resolveIncludePath($includeFile);

                if (in_array($fullPath, $this->includeStack, true)) {
                    throw new ParserException(
                        "Circular include detected: " . implode(' -> ', $this->includeStack) . " -> {$fullPath}",
                        $this->currentLine,
                        $this->currentFile
                    );
                }

                $this->includeStack[] = $fullPath;

                if ($this->delegate !== null) {
                    $includedData = $this->delegate->parseFile($fullPath);
                    // Standardize: merge files from delegate
                    foreach ($this->delegate->getParsedFiles() as $pf) {
                        if (!in_array($pf, $this->allParsedFiles, true)) {
                            $this->allParsedFiles[] = $pf;
                        }
                    }
                } else {
                    $includedData = $this->parseFileInternal($fullPath);
                }

                array_pop($this->includeStack);

                $current = &$stack[count($stack) - 1];
                $current = array_replace_recursive($current, $includedData);
                continue;
            }

            // Section start   e.g.:  database {
            if (preg_match('/^([A-Za-z0-9_]+)\s*\{$/', $line, $m)) {
                $section = $m[1];

                // Check depth limit
                $depth++;
                if ($depth > self::MAX_DEPTH) {
                    throw new ParserException(
                        "Maximum nesting depth exceeded (limit: " . self::MAX_DEPTH . ")",
                        $this->currentLine,
                        $this->currentFile
                    );
                }

                $current = &$stack[count($stack) - 1];

                // Prevent overwriting existing non-array values
                if (isset($current[$section]) && !is_array($current[$section])) {
                    throw new ParserException(
                        "Cannot redefine key '{$section}' as section",
                        $this->currentLine,
                        $this->currentFile
                    );
                }

                if (!isset($current[$section])) {
                    $current[$section] = [];
                }

                $stack[] = &$current[$section];
                continue;
            }

            // Section end   "}"
            if ($line === '}') {
                if (count($stack) <= 1) {
                    throw new ParserException(
                        "Unexpected closing brace '}' without matching opening brace",
                        $this->currentLine,
                        $this->currentFile
                    );
                }
                array_pop($stack);
                $depth--;
                continue;
            }

            // key = value  (equals sign)
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.+)$/', $line, $m)) {
                [$_, $key, $rawVal] = $m;
                $this->processKeyValue($stack, $key, $rawVal, $inArray, $arrayKey, $arrayRaw, $arrayStartLine);
                continue;
            }

            // key value (whitespace separator)
            // Ensure the value part doesn't start with '=' to avoid confusion with the equals syntax
            if (preg_match('/^([A-Za-z0-9_]+)\s+(?!=)(.+)$/', $line, $m)) {
                [$_, $key, $rawVal] = $m;
                $this->processKeyValue($stack, $key, $rawVal, $inArray, $arrayKey, $arrayRaw, $arrayStartLine);
                continue;
            }

            throw new ParserException(
                "Syntax error: {$line}",
                $this->currentLine,
                $this->currentFile
            );
        }

        // Check for unclosed sections
        if (count($stack) > 1) {
            throw new ParserException(
                "Unclosed section: missing '}' at end of file",
                $this->currentLine,
                $this->currentFile
            );
        }

        // Check for unclosed array
        if ($inArray) {
            throw new ParserException(
                "Unclosed array starting at line {$arrayStartLine}",
                $this->currentLine,
                $this->currentFile
            );
        }

        if ($resolveReferences) {
            $this->resolveReferences($data, $data);
        }

        return $data;
    }

    /**
     * Get all files parsed during the last operation.
     *
     * @return string[]
     */
    public function getParsedFiles(): array
    {
        return $this->allParsedFiles;
    }

    // ── Internal Parser ─────────────────────────────────────────

    /**
     * Process a key-value pair.
     *
     * @param array<int, array<string, mixed>> $stack
     * @param string $key
     * @param string $rawVal
     * @param bool $inArray
     * @param string|null $arrayKey
     * @param string $arrayRaw
     * @param int $arrayStartLine
     * @return void
     * @throws ParserException
     */
    private function processKeyValue(
        array &$stack,
        string $key,
        string $rawVal,
        bool &$inArray,
        ?string &$arrayKey,
        string &$arrayRaw,
        int &$arrayStartLine
    ): void {
        $trimmed = trim($rawVal);

        // Begin multi-line array?
        if (str_starts_with($trimmed, '[') && !str_ends_with($trimmed, ']')) {
            $inArray = true;
            $arrayKey = $key;
            $arrayRaw = $trimmed;
            $arrayStartLine = $this->currentLine;
            return;
        }

        try {
            $value = $this->parseValue($rawVal);
        } catch (\Throwable $e) {
            throw new ParserException(
                "Invalid value for key '{$key}': {$e->getMessage()}",
                $this->currentLine,
                $this->currentFile,
                $e
            );
        }

        $current = &$stack[count($stack) - 1];

        // Warn about duplicate keys (overwrite with warning)
        if (array_key_exists($key, $current)) {
            trigger_error(
                "Duplicate key '{$key}' at line {$this->currentLine} in {$this->currentFile}, " .
                    "previous value will be overwritten",
                E_USER_WARNING
            );
        }

        $current[$key] = $value;
    }

    /**
     * Parse a single raw value token.
     *
     * @param string $raw
     * @return mixed
     * @throws JsonException|ParserException
     */
    private function parseValue(string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new ParserException(
                "Empty value not allowed",
                $this->currentLine,
                $this->currentFile
            );
        }


        // Null value
        if (strcasecmp($raw, 'null') === 0) {
            return null;
        }

        // Booleans
        if (strcasecmp($raw, 'true') === 0)  return true;
        if (strcasecmp($raw, 'false') === 0) return false;

        // Numeric values
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float)$raw : (int)$raw;
        }

        // JSON-style array
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            try {
                return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new ParserException(
                    "Invalid JSON array: {$e->getMessage()}",
                    $this->currentLine,
                    $this->currentFile,
                    $e
                );
            }
        }

        // JSON-style object
        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            try {
                return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new ParserException(
                    "Invalid JSON object: {$e->getMessage()}",
                    $this->currentLine,
                    $this->currentFile,
                    $e
                );
            }
        }

        // String value - remove quotes if present
        if ((str_starts_with($raw, '"') && str_ends_with($raw, '"')) ||
            (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            return substr($raw, 1, -1);
        }

        // Unquoted string
        return $raw;
    }

    /**
     * Resolve an include path relative to the current file.
     */
    private function resolveIncludePath(string $includeFile): string
    {
        if ($this->currentFile === '<string>') {
            return $includeFile;
        }

        $baseDir = dirname($this->currentFile);
        $path = $baseDir . DIRECTORY_SEPARATOR . $includeFile;

        // Try to get realpath to normalize it
        $real = realpath($path);
        return $real ?: $path;
    }


    // ── Reference Resolution ───────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rootData
     */
    private function resolveReferences(array &$data, array &$rootData): void
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $this->resolveReferences($value, $rootData);
            } elseif (is_string($value)) {
                $this->resolveNode($value, $rootData, []);
            }
        }
    }

    /**
     * @param mixed $node
     * @param array<string, mixed> $rootData
     * @param array<int, string> $resolvingPath
     */
    private function resolveNode(mixed &$node, array &$rootData, array $resolvingPath): void
    {
        if (!is_string($node)) {
            return;
        }

        // 1. Standalone expansion (can change type)
        if (preg_match('/^\$\{(?P<var>[a-zA-Z_0-9\.]+)(?::-(?P<default>.*))?\}$/', $node, $m)) {
            $var = $m['var'];
            $default = $m['default'] ?? null;

            $resolvedValue = $this->resolveVariable($var, $rootData, $resolvingPath);
            if ($resolvedValue === null) {
                $resolvedValue = $default;
            }

            if (is_string($resolvedValue)) {
                if ($resolvedValue === '') {
                    $node = '';
                } else {
                    $node = $this->parseValue($resolvedValue);
                }
            } else {
                $node = $resolvedValue;
            }
            return;
        }

        // 2. Mixed expansion (always string result)
        if (str_contains($node, '${')) {
            $node = (string) preg_replace_callback(
                '/\$\{(?P<var>[a-zA-Z_0-9\.]+)(?::-(?P<default>.*))?\}/',
                function (array $m) use (&$rootData, $resolvingPath) {
                    $var = $m['var'];
                    $default = $m['default'] ?? null;

                    $val = $this->resolveVariable($var, $rootData, $resolvingPath);
                    if ($val === null) {
                        $val = $default;
                    }

                    return match (true) {
                        $val === true => 'true',
                        $val === false => 'false',
                        $val === null => 'null',
                        is_scalar($val) => (string) $val,
                        is_array($val) => json_encode($val) ?: '[]',
                        default => 'unknown',
                    };
                },
                $node
            );
        }
    }

    /**
     * @param array<string, mixed> $rootData
     * @param array<int, string> $resolvingPath
     */
    private function resolveVariable(string $path, array &$rootData, array $resolvingPath): mixed
    {
        if (in_array($path, $resolvingPath, true)) {
            throw new CircularDependencyException("Circular dependency detected involving '{$path}'");
        }

        $resolvingPath[] = $path;

        // Try getting from config data
        $node = &$rootData;
        $keys = explode('.', $path);
        $found = true;
        foreach ($keys as $k) {
            if (!is_array($node) || !array_key_exists($k, $node)) {
                $found = false;
                break;
            }
            $node = &$node[$k];
        }

        if ($found) {
            if (is_string($node)) {
                $this->resolveNode($node, $rootData, $resolvingPath);
            }
            return $node;
        }

        $value = $this->env->get($path, '');
        return ($value === '') ? null : $value;
    }
}
