<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers;

use MonkeysLegion\Mlc\Parsers\Traits\FileSecurityTrait;
use MonkeysLegion\Mlc\Contracts\ParserInterface;
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;
use JsonException;

/**
 * Native JSON Parser for MLC.
 */
final class JsonParser implements ParserInterface
{
    use FileSecurityTrait;

    public function parseFile(string $file): array
    {
        $this->validateFileSecurity($file);

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new ParserException("Failed to read JSON file: {$file}", 0, $file);
        }

        return $this->parseContent($content, $file);
    }


    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return [];
            }
            return $data;
        } catch (JsonException $e) {
            throw new ParserException("Invalid JSON: " . $e->getMessage(), 0, $filename, $e);
        }
    }

    public function getParsedFiles(): array
    {
        return [];
    }
}
