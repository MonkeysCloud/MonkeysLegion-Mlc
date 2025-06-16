<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use JsonException;
use RuntimeException;

/**
 * MLC Parser
 *
 * Parses .mlc configuration files into a nested PHP array.
 * Supports both `key = value` and `key  value` syntaxes,
 * as well as multi-line JSON arrays.
 */
final class Parser
{
    /**
     * Parse a .mlc configuration file.
     *
     * @param string $file Path to the .mlc file
     * @return array Parsed configuration data
     * @throws JsonException
     */
    public function parseFile(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("Config file not found: {$file}");
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $data  = [];
        $stack = [&$data];

        // state for multi-line array parsing
        $inArray  = false;
        $arrayKey = null;
        $arrayRaw = '';

        foreach ($lines as $raw) {
            $line = trim($raw);

            // If we're in the middle of a multi-line array, accumulate
            if ($inArray) {
                $arrayRaw .= ' ' . $line;
                if (str_ends_with($line, ']')) {
                    $current              = &$stack[count($stack) - 1];
                    $current[$arrayKey]   = $this->parseValue($arrayRaw);
                    // reset state
                    $inArray  = false;
                    $arrayKey = null;
                    $arrayRaw = '';
                }
                continue;
            }

            // Skip empty lines & comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Section start   e.g.:  database {
            if (preg_match('/^([A-Za-z0-9_]+)\s*\{$/', $line, $m)) {
                $section            = $m[1];
                $current            = &$stack[count($stack) - 1];
                $current[$section]  = [];
                $stack[]            = &$current[$section];
                continue;
            }

            // Section end   "}"
            if ($line === '}') {
                array_pop($stack);
                continue;
            }

            // key = value  (equals sign)
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.+)$/', $line, $m)) {
                [$_, $key, $rawVal] = $m;
                $trimmed = trim($rawVal);
                // begin multi-line array?
                if (str_starts_with($trimmed, '[') && !str_ends_with($trimmed, ']')) {
                    $inArray  = true;
                    $arrayKey = $key;
                    $arrayRaw = $trimmed;
                    continue;
                }
                $value   = $this->parseValue($rawVal);
                $current = &$stack[count($stack) - 1];
                $current[$key] = $value;
                continue;
            }

            // key value (whitespace separator)
            if (preg_match('/^([A-Za-z0-9_]+)\s+(.+)$/', $line, $m)) {
                [$_, $key, $rawVal] = $m;
                $trimmed = trim($rawVal);
                // begin a multi-line array?
                if (str_starts_with($trimmed, '[') && !str_ends_with($trimmed, ']')) {
                    $inArray  = true;
                    $arrayKey = $key;
                    $arrayRaw = $trimmed;
                    continue;
                }
                $value   = $this->parseValue($rawVal);
                $current = &$stack[count($stack) - 1];
                $current[$key] = $value;
                continue;
            }

            throw new RuntimeException("Syntax error in {$file} at: {$line}");
        }

        return $data;
    }

    /**
     * Parse a single raw value token.
     *
     * @param string $raw
     * @return mixed
     * @throws JsonException
     */
    private function parseValue(string $raw): mixed
    {
        $raw = trim($raw);

        // booleans
        if (strcasecmp($raw, 'true') === 0)  return true;
        if (strcasecmp($raw, 'false') === 0) return false;

        // numeric
        if (is_numeric($raw)) return str_contains($raw, '.') ? (float)$raw : (int)$raw;

        // JSON-style array
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        // fallback: quoted or bare string
        return trim($raw, '"' . "'");
    }
}