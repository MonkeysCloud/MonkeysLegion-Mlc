<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers;

use MonkeysLegion\Mlc\Parsers\Traits\FileSecurityTrait;
use MonkeysLegion\Mlc\Contracts\ParserInterface;
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;

/**
 * Native PHP Configuration Parser for MLC.
 */
final class PhpParser implements ParserInterface
{
    use FileSecurityTrait;

    public function parseFile(string $file): array
    {
        $this->validateFileSecurity($file);

        try {
            $data = include $file;
            if (!is_array($data)) {
                return [];
            }
            return $data;
        } catch (\Throwable $e) {
            throw new ParserException("Failed to load PHP config: " . $e->getMessage(), 0, $file, $e);
        }
    }


    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array
    {
        // Not implemented for strings (PHP files are executed)
        throw new ParserException("parseContent is not supported for PhpParser", 0, $filename);
    }

    public function getParsedFiles(): array
    {
        return [];
    }
}
