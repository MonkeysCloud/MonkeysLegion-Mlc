<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Contracts;

use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;

/**
 * Interface for MLC-compatible configuration parsers.
 */
interface ParserInterface
{
    /**
     * Enable or disable strict security checks.
     *
     * @param bool $strict Whether to enable strict security
     * @return self
     */
    public function enableStrictSecurity(bool $strict = true): self;

    /**
     * Parse a configuration file.
     *
     * @param string $file Path to the config file
     * @return array<string, mixed> Parsed configuration data
     * @throws ParserException|SecurityException
     */
    public function parseFile(string $file): array;

    /**
     * Parse configuration content from a string.
     *
     * @param string $content Configuration content
     * @param string $filename Optional filename for error messages/context
     * @param bool   $resolveReferences Whether to resolve variable references
     * @return array<string, mixed> Parsed configuration data
     * @throws ParserException
     */
    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array;

    /**
     * Get all files involved in the last parsing operation (e.g. includes).
     *
     * @return string[]
     */
    public function getParsedFiles(): array;
}
