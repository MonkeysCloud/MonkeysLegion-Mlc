<?php
declare(strict_types=1);

/**
 * Get the value of an environment variable.
 *
 * @param string $key     The environment variable name
 * @param mixed  $default Default value if not found
 * @return mixed
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert common string representations to their proper types
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}