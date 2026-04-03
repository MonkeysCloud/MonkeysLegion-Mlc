<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use MonkeysLegion\Mlc\Exception\ConfigException;
use MonkeysLegion\Mlc\Exception\FrozenConfigException;

/**
 * Immutable-by-default configuration container with dot-notation access.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Architecture — Dual-Layer Engine
 * ────────────────────────────────────────────────────────────────────────────
 *
 *  Layer 1 — Compiled base ($data)
 *    Populated from the OPcache-compiled PHP file via require.
 *    Never modified after construction.
 *
 *  Layer 2 — Runtime overrides ($runtimeOverrides)
 *    Dormant by default. Activated automatically on the first override() call.
 *    get() checks this layer first when active; compiled base is the fallback.
 *
 * Zero-overhead read path (dual-layer dormant):
 *   get() → direct array lookup on $data → no override-layer check at all.
 *
 * After the first override():
 *   get() → $runtimeOverrides[$path] ?? $data traversal
 *
 * Two locks:
 *   lock()           — prevents any override() call (Lock 1: sealed post-compile)
 *   lockOverrides()  — prevents further override() calls (Lock 2: overrides sealed)
 *
 * ────────────────────────────────────────────────────────────────────────────
 */
final class Config
{
    /** @var array<string, mixed> Compiled base layer — never mutated */
    private array $data;

    /** @var array<string, mixed> Runtime override layer — lazy-activated */
    private array $runtimeOverrides = [];

    /** True once the first override() is applied — enables the override-layer path in get() */
    private bool $dualLayerActive = false;

    /** Lock 1: no override() allowed at all (sealed, read-only) */
    private bool $compiledLocked = false;

    /** Lock 2: no further override() allowed (override layer sealed) */
    private bool $overridesLocked = false;

    /** @var array<string, mixed> Internal get-result cache for repeated dot-path lookups */
    private array $lookupCache = [];

    // ── Lifecycle ──────────────────────────────────────────────

    /**
     * Config constructor.
     *
     * @param array<string, mixed> $data Compiled base layer
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // ── Read API ───────────────────────────────────────────────

    /**
     * Retrieve a value by dot-notation (e.g. 'database.host').
     *
     * When the dual-layer is dormant (no overrides applied yet) this is a
     * direct array traversal — identical overhead to a plain PHP array read.
     * When the dual-layer is active the override layer is checked first.
     *
     * @param string $path   Dot-notation key
     * @param mixed  $default Returned when path does not exist
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        // Override layer takes priority when active
        if ($this->dualLayerActive && array_key_exists($path, $this->runtimeOverrides)) {
            return $this->runtimeOverrides[$path];
        }

        // Internal lookup cache for repeated reads of the same path
        if (array_key_exists($path, $this->lookupCache)) {
            return $this->lookupCache[$path];
        }

        // Traverse the compiled base
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        $this->lookupCache[$path] = $node;
        return $node;
    }

    /**
     * Whether a dot-notation path exists in either layer.
     */
    public function has(string $path): bool
    {
        if ($this->dualLayerActive && array_key_exists($path, $this->runtimeOverrides)) {
            return true;
        }

        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }
        return true;
    }

    /**
     * Get a required value — throws ConfigException when the path is missing.
     *
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
     * Get a typed string value. Returns $default when the path is absent.
     *
     * @throws ConfigException When the value exists but is not a string or numeric
     */
    public function getString(string $path, ?string $default = null): ?string
    {
        $value = $this->get($path, $default);
        if ($value === null) return $default;
        if (!is_string($value) && !is_numeric($value)) {
            throw new ConfigException("Config value at '{$path}' must be string, got " . gettype($value));
        }
        return (string) $value;
    }

    /**
     * Get a typed integer value. Returns $default when the path is absent.
     *
     * @throws ConfigException When the value exists but is not numeric
     */
    public function getInt(string $path, ?int $default = null): ?int
    {
        $value = $this->get($path, $default);
        if ($value === null) return $default;
        if (!is_int($value) && !is_numeric($value)) {
            throw new ConfigException("Config value at '{$path}' must be integer, got " . gettype($value));
        }
        return (int) $value;
    }

    /**
     * Get a typed float value. Returns $default when the path is absent.
     *
     * @throws ConfigException When the value exists but is not numeric
     */
    public function getFloat(string $path, ?float $default = null): ?float
    {
        $value = $this->get($path, $default);
        if ($value === null) return $default;
        if (!is_float($value) && !is_numeric($value)) {
            throw new ConfigException("Config value at '{$path}' must be float, got " . gettype($value));
        }
        return (float) $value;
    }

    /**
     * Get a typed boolean value. Returns $default when the path is absent.
     *
     * @throws ConfigException When the value exists but is not a boolean
     */
    public function getBool(string $path, ?bool $default = null): ?bool
    {
        $value = $this->get($path, $default);
        if ($value === null) return $default;
        if (!is_bool($value)) {
            throw new ConfigException("Config value at '{$path}' must be boolean, got " . gettype($value));
        }
        return $value;
    }

    /**
     * Get a typed array value. Returns $default when the path is absent.
     *
     * @param array<string, mixed>|null $default
     * @return array<string, mixed>|null
     * @throws ConfigException When the value exists but is not an array
     */
    public function getArray(string $path, ?array $default = null): ?array
    {
        $value = $this->get($path, $default);
        if ($value === null) return $default;
        if (!is_array($value)) {
            throw new ConfigException("Config value at '{$path}' must be array, got " . gettype($value));
        }
        return $value;
    }

    // ── Utilities & Exports ─────────────────────────────────────

    /**
     * Return the compiled base data (does not include runtime overrides).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Return a Config scoped to a sub-section of the compiled base.
     *
     * Note: runtime overrides of the parent Config are NOT inherited by the subset.
     * Call snapshot() first if you need overrides reflected in the subset.
     */
    public function subset(string $prefix): self
    {
        $data = $this->get($prefix, []);
        return new self(is_array($data) ? $data : []);
    }

    /**
     * Merge another Config's compiled base into this one.
     *
     * Returns a new Config instance; neither source is modified.
     */
    public function merge(Config $config): self
    {
        return new self(array_replace_recursive($this->data, $config->all()));
    }

    /**
     * Export the compiled base data as a JSON string.
     *
     * @throws \JsonException
     */
    public function toJson(int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT): string
    {
        return (string) json_encode($this->data, $flags);
    }

    /**
     * Alias for all().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    // ── Dual-Layer Override Engine ─────────────────────────────

    /**
     * Apply a non-destructive runtime override on top of the compiled base.
     *
     * The compiled base is never touched. The override is stored in a separate
     * $runtimeOverrides map and takes priority in get().
     *
     * On the first call the dual-layer engine is activated; subsequent get()
     * calls will check this layer before falling through to the compiled data.
     *
     * @param string $path  Dot-notation path (e.g. 'database.host')
     * @param mixed  $value The override value
     * @throws FrozenConfigException When the config is locked via lock() or lockOverrides()
     */
    public function override(string $path, mixed $value): void
    {
        if ($this->compiledLocked) {
            throw new FrozenConfigException(
                "Cannot override '{$path}': config is sealed (lock() was called). " .
                "This config is read-only; no overrides are allowed."
            );
        }

        if ($this->overridesLocked) {
            throw new FrozenConfigException(
                "Cannot override '{$path}': override layer is sealed (lockOverrides() was called). " .
                "Apply all overrides before calling lockOverrides()."
            );
        }

        // Activate dual-layer on first use
        if (!$this->dualLayerActive) {
            $this->dualLayerActive = true;
        }

        $this->runtimeOverrides[$path] = $value;

        // Bust the lookup-cache for this path and any cached children
        $this->bustLookupCache($path);
    }

    /**
     * Whether the dual-layer override engine is currently active.
     *
     * Returns false until the first override() call; true afterwards.
     * When false, get() reads directly from the compiled base with zero overhead.
     */
    public function isDualLayerActive(): bool
    {
        return $this->dualLayerActive;
    }

    /**
     * Return a copy of all current runtime overrides.
     *
     * @return array<string, mixed>
     */
    public function getOverrides(): array
    {
        return $this->runtimeOverrides;
    }

    // ── Locks ───────────────────────────────────────────────────

    /**
     * Lock 1 — Seal the config entirely (no overrides allowed).
     *
     * Call immediately after load() when you want a permanently read-only config.
     * Any subsequent override() call will throw FrozenConfigException.
     *
     * Compatible with snapshot(), get(), and all typed getters.
     *
     * @return self Fluent
     */
    public function lock(): self
    {
        $this->compiledLocked = true;
        return $this;
    }

    /**
     * Lock 2 — Seal the override layer (no further overrides allowed).
     *
     * Call after you have applied all desired runtime overrides to prevent
     * any further mutations. Already-applied overrides remain visible.
     *
     * Compatible with snapshot(), get(), and all typed getters.
     *
     * @return self Fluent
     */
    public function lockOverrides(): self
    {
        $this->overridesLocked = true;
        return $this;
    }

    /**
     * Whether lock() has been called (no overrides ever allowed).
     */
    public function isLocked(): bool
    {
        return $this->compiledLocked;
    }

    /**
     * Whether lockOverrides() has been called (no further overrides allowed).
     */
    public function areOverridesLocked(): bool
    {
        return $this->overridesLocked;
    }

    // ── Snapshotting ────────────────────────────────────────────

    /**
     * Flatten the compiled base + runtime overrides into a fresh, unlocked Config.
     *
     * The new instance starts with dual-layer dormant and no locks applied,
     * making it safe to use as a per-request isolated copy in long-running
     * processes (RoadRunner, Swoole, ReactPHP).
     *
     * The original Config is not modified.
     *
     * @return self A new, fully independent Config instance
     */
    public function snapshot(): self
    {
        if (!$this->dualLayerActive) {
            // No overrides — just clone the base data
            return new self($this->data);
        }

        // Merge dot-notation override keys into the nested base structure
        $merged = $this->data;
        foreach ($this->runtimeOverrides as $path => $value) {
            $keys = explode('.', $path);
            $node = &$merged;
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
        }

        return new self($merged);
    }

    // ── Internal Cache Management ───────────────────────────────

    /**
     * Clear lookup-cache entries for a path and all cached children.
     *
     * Called after override() to ensure stale cached values are not served.
     */
    private function bustLookupCache(string $path): void
    {
        unset($this->lookupCache[$path]);
        $prefix = $path . '.';
        foreach (array_keys($this->lookupCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->lookupCache[$key]);
            }
        }
    }

    /**
     * Clear the entire lookup cache.
     *
     * Useful in tests or after bulk overrides.
     */
    public function clearCache(): void
    {
        $this->lookupCache = [];
    }

    /**
     * Return internal lookup-cache statistics (for debugging / testing).
     *
     * @return array{size: int, keys: array<int, string>}
     */
    public function getCacheStats(): array
    {
        return [
            'size' => count($this->lookupCache),
            'keys' => array_keys($this->lookupCache),
        ];
    }
}
