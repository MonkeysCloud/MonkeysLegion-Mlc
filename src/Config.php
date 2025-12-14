<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use MonkeysLegion\Mlc\Exception\ConfigException;
use MonkeysLegion\Mlc\Exception\FrozenConfigException;

/**
 * Immutable configuration container with dot-notation access.
 * 
 * Thread-safe and production-optimized.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $data;
    private bool $frozen = false;
    /** @var array<string, mixed> */
    private array $cache = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Retrieve a value by dot-notation (e.g. 'database.dsn').
     * Returns $default if path not found.
     *
     * @param string $path Dot-notation path
     * @param mixed $default Default value if path not found
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        // Check cache first for performance
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $node = $this->data;
        $keys = explode('.', $path);
        
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        // Cache the result
        $this->cache[$path] = $node;
        
        return $node;
    }

    /**
     * Get a string value with type enforcement.
     *
     * @param string $path
     * @param string|null $default
     * @return string|null
     * @throws ConfigException
     */
    public function getString(string $path, ?string $default = null): ?string
    {
        $value = $this->get($path, $default);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_string($value) && !is_numeric($value)) {
            throw new ConfigException(
                "Config value at '{$path}' must be string, got " . gettype($value)
            );
        }
        
        return (string)$value;
    }

    /**
     * Get an integer value with type enforcement.
     *
     * @param string $path
     * @param int|null $default
     * @return int|null
     * @throws ConfigException
     */
    public function getInt(string $path, ?int $default = null): ?int
    {
        $value = $this->get($path, $default);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_int($value) && !is_numeric($value)) {
            throw new ConfigException(
                "Config value at '{$path}' must be integer, got " . gettype($value)
            );
        }
        
        return (int)$value;
    }

    /**
     * Get a float value with type enforcement.
     *
     * @param string $path
     * @param float|null $default
     * @return float|null
     * @throws ConfigException
     */
    public function getFloat(string $path, ?float $default = null): ?float
    {
        $value = $this->get($path, $default);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_float($value) && !is_numeric($value)) {
            throw new ConfigException(
                "Config value at '{$path}' must be float, got " . gettype($value)
            );
        }
        
        return (float)$value;
    }

    /**
     * Get a boolean value with type enforcement.
     *
     * @param string $path
     * @param bool|null $default
     * @return bool|null
     * @throws ConfigException
     */
    public function getBool(string $path, ?bool $default = null): ?bool
    {
        $value = $this->get($path, $default);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_bool($value)) {
            throw new ConfigException(
                "Config value at '{$path}' must be boolean, got " . gettype($value)
            );
        }
        
        return $value;
    }

    /**
     * Get an array value with type enforcement.
     *
     * @param string $path
     * @param array<string, mixed>|null $default
     * @return array<string, mixed>|null
     * @throws ConfigException
     */
    public function getArray(string $path, ?array $default = null): ?array
    {
        $value = $this->get($path, $default);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_array($value)) {
            throw new ConfigException(
                "Config value at '{$path}' must be array, got " . gettype($value)
            );
        }
        
        return $value;
    }

    /**
     * Get a required value (throws if not found).
     *
     * @param string $path
     * @return mixed
     * @throws ConfigException
     */
    public function getRequired(string $path): mixed
    {
        if (!$this->has($path)) {
            throw new ConfigException("Required config key '{$path}' not found");
        }
        
        return $this->get($path);
    }

    /**
     * Whether a given dot-path exists.
     *
     * @param string $path
     * @return bool
     */
    public function has(string $path): bool
    {
        $node = $this->data;
        $keys = explode('.', $path);
        
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }
        
        return true;
    }



    /**
     * Get all raw data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Freeze the configuration to prevent modifications.
     * This is recommended for production environments.
     *
     * @return self
     */
    public function freeze(): self
    {
        $this->frozen = true;
        return $this;
    }

    /**
     * Check if configuration is frozen.
     *
     * @return bool
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Set a value (only if not frozen).
     * Note: This breaks immutability and should only be used in tests.
     *
     * @param string $path
     * @param mixed $value
     * @return void
     * @throws FrozenConfigException
     * @internal
     */
    public function set(string $path, mixed $value): void
    {
        if ($this->frozen) {
            throw new FrozenConfigException(
                "Cannot modify frozen configuration at '{$path}'"
            );
        }

        $keys = explode('.', $path);
        $node = &$this->data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $node[$key] = $value;
            } else {
                if (!isset($node[$key]) || !is_array($node[$key])) {
                    $node[$key] = [];
                }
                $node = &$node[$key];
            }
        }

        // Clear cache for this path and any parent/child paths
        $this->clearCacheForPath($path);
    }

    /**
     * Merge with another configuration.
     *
     * @param Config $config
     * @return self New Config instance
     */
    public function merge(Config $config): self
    {
        $merged = array_replace_recursive($this->data, $config->all());
        return new self($merged);
    }

    /**
     * Export configuration to JSON.
     *
     * @param int $flags JSON encode flags
     * @return string
     * @throws \JsonException
     */
    public function toJson(int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT): string
    {
        return (string) json_encode($this->data, $flags);
    }

    /**
     * Export configuration to array (alias for all()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get a subset of configuration by prefix.
     *
     * @param string $prefix
     * @return self New Config instance with subset
     */
    public function subset(string $prefix): self
    {
        $data = $this->get($prefix, []);
        
        if (!is_array($data)) {
            $data = [];
        }
        
        return new self($data);
    }

    /**
     * Clear cache for a specific path and related paths.
     *
     * @param string $path
     * @return void
     */
    private function clearCacheForPath(string $path): void
    {
        // Clear exact match
        unset($this->cache[$path]);

        // Clear any cached children
        $prefix = $path . '.';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Clear all cached values.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }



    /**
     * Get cache statistics.
     *
     * @return array{size: int, keys: array<int, string>}
     */
    public function getCacheStats(): array
    {
        return [
            'size' => count($this->cache),
            'keys' => array_keys($this->cache),
        ];
    }
}
