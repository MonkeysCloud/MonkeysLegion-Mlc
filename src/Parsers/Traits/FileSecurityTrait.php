<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers\Traits;

use MonkeysLegion\Mlc\Exception\SecurityException;

/**
 * Trait FileSecurityTrait
 *
 * Provides standardized file security checks for all MLC parsers.
 */
trait FileSecurityTrait
{
    /**
     * Max file size for any config file (10MB).
     */
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Whether strict security mode is enabled.
     */
    private bool $strictSecurity = false;

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
     * Validate a file for common security concerns.
     *
     * @param string $file The path to the file
     * @throws SecurityException
     */
    protected function validateFileSecurity(string $file): void
    {
        $this->validateFilePath($file);
        $this->validateFileAccess($file);
        $this->validateFileSize($file);
    }

    /**
     * Validate file path for security (prevent path traversal).
     *
     * @param string $file
     * @throws SecurityException
     */
    protected function validateFilePath(string $file): void
    {
        // Check for path traversal attempts
        if (str_contains($file, '..')) {
            throw new SecurityException(
                "Path traversal detected in config file path: {$file}"
            );
        }

        // Check for Suspicious patterns if necessary
        $realPath = realpath($file);
        if ($realPath === false) {
             throw new SecurityException("Config file not found or inaccessible: {$file}");
        }
    }

    /**
     * Validate file access permissions.
     *
     * @param string $file
     * @throws SecurityException
     */
    protected function validateFileAccess(string $file): void
    {
        if (!is_file($file)) {
            throw new SecurityException("Config file not found: {$file}");
        }

        if (!is_readable($file)) {
            throw new SecurityException("Config file not readable: {$file}");
        }

        // Check file permissions (warn if too permissive)
        $perms = fileperms($file);
        if ($perms !== false) {
            // Check if world-writable
            if (($perms & 0002) !== 0) {
                $message = sprintf(
                    "Config file '%s' is world-writable (perms: %04o). This is a severe security risk.",
                    $file,
                    $perms & 0777
                );

                if ($this->strictSecurity) {
                    throw new SecurityException($message);
                }

                trigger_error($message, E_USER_WARNING);
            }
        }
    }

    /**
     * Validate file size.
     *
     * @param string $file
     * @throws SecurityException
     */
    protected function validateFileSize(string $file): void
    {
        $size = @filesize($file);

        if ($size === false) {
            throw new SecurityException("Could not determine size of config file: {$file}");
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new SecurityException(
                sprintf(
                    "Config file too large: %d bytes (max: %d bytes)",
                    $size,
                    self::MAX_FILE_SIZE
                )
            );
        }

        if ($size === 0) {
            trigger_error("Config file '{$file}' is empty", E_USER_NOTICE);
        }
    }
}
