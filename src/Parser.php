<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use JsonException;
use RuntimeException;

final class Parser
{
    /**
     * Parse one .mlc file into a nested PHP array.
     *
     * @param string $file  Path to the .mlc file
     * @return array<string,mixed>
     * @throws RuntimeException|JsonException on syntax error or missing file
     */
    public function parseFile(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("Config file not found: {$file}");
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $data  = [];
        $stack = [&$data];
        foreach ($lines as $raw) {
            $line = trim($raw);

            // skip comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // section start: key {
            if (preg_match('/^([A-Za-z0-9_]+)\s*\{\s*$/', $line, $m)) {
                $section = $m[1];
                $current = &$stack[count($stack)-1];
                $current[$section] = [];
                $stack[] = &$current[$section];
                continue;
            }

            // section end: }
            if ($line === '}') {
                array_pop($stack);
                continue;
            }

            // key = value
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.+)$/', $line, $m)) {
                [$_, $key, $rawVal] = $m;
                $value = $this->parseValue($rawVal);
                $current = &$stack[count($stack)-1];
                $current[$key] = $value;
                continue;
            }

            throw new RuntimeException("Syntax error in {$file} at: {$line}");
        }

        return $data;
    }

    /**
     * Parse a single raw value token.
     */
    private function parseValue(string $raw): mixed
    {
        $raw = trim($raw);

        // boolean
        if (strcasecmp($raw, 'true') === 0)  return true;
        if (strcasecmp($raw, 'false') === 0) return false;

        // numeric
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float)$raw : (int)$raw;
        }

        // array via JSON
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        // quoted string
        return trim($raw, "\"'");
    }
}