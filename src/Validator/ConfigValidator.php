<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Validator;

/**
 * Interface for configuration validators.
 */
interface ConfigValidator
{
    /**
     * Validate configuration data.
     *
     * @param array<string, mixed> $config Configuration data to validate
     * @return array<int, string> Array of error messages (empty if valid)
     */
    public function validate(array $config): array;
}
