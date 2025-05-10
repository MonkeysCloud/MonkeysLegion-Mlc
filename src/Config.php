<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

final class Config
{
    public function __construct(private array $data) {}

    /**
     * Retrieve a value by dot‑notation (e.g. 'database.dsn').
     * Returns $default if path not found.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /**
     * Whether a given dot‑path exists.
     */
    public function has(string $path): bool
    {
        return $this->get($path, null) !== null;
    }

    /**
     * Get all raw data.
     */
    public function all(): array
    {
        return $this->data;
    }
}