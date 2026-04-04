<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers;

use MonkeysLegion\Mlc\Parsers\Traits\FileSecurityTrait;
use MonkeysLegion\Mlc\Contracts\ParserInterface;
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;

/**
 * Lightweight, native YAML parser for MLC.
 * Supports basic indentation-based key-value pairs.
 */
final class YamlParser implements ParserInterface
{
    use FileSecurityTrait;

    public function parseFile(string $file): array
    {
        $this->validateFileSecurity($file);

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new ParserException("Failed to read YAML file: {$file}", 0, $file);
        }

        return $this->parseContent($content, $file);
    }

    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array
    {
        $lines = explode("\n", $content);
        $result = [];
        $stack = [&$result];
        $depths = [-1];

        foreach ($lines as $i => $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '' || str_starts_with($lineTrim, '#')) {
                continue;
            }

            $currentDepth = strlen($line) - strlen(ltrim($line));
            
            // Pop stack until we are at the right depth
            while (count($depths) > 1 && $currentDepth <= $depths[count($depths) - 1]) {
                array_pop($stack);
                array_pop($depths);
            }

            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $lineTrim, $m)) {
                $key = $m[1];
                $val = trim($m[2]);

                if ($val === '') {
                    // Start of a section
                    $current = &$stack[count($stack) - 1];
                    $current[$key] = [];
                    $stack[] = &$current[$key];
                    $depths[] = $currentDepth;
                } else {
                    $current = &$stack[count($stack) - 1];
                    $current[$key] = $this->parseVal($val);
                }
            }
        }

        return $result;
    }

    private function parseVal(string $val): mixed
    {
        if (strcasecmp($val, 'true') === 0) return true;
        if (strcasecmp($val, 'false') === 0) return false;
        if (strcasecmp($val, 'null') === 0) return null;
        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float)$val : (int)$val;
        }
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            return substr($val, 1, -1);
        }
        return $val;
    }

    public function getParsedFiles(): array
    {
        return [];
    }
}
