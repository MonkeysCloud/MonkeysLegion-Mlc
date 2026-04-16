<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use MonkeysLegion\Cache\CacheStoreInterface;
use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Enums\LoaderHook;
use MonkeysLegion\Mlc\Exception\ConfigException;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Contracts\ConfigValidatorInterface;
use MonkeysLegion\Mlc\Contracts\ParserInterface;
use MonkeysLegion\Mlc\Contracts\LoaderInterface;
use MonkeysLegion\Mlc\Cache\CompiledPhpCache;
use Psr\SimpleCache\CacheInterface;

/**
 * Production-grade configuration loader.
 *
 * Loads and merges multiple MLC configuration files with:
 * - PSR-16 caching support (MonkeysLegion-Cache compatible)
 * - Validation hooks
 * - Environment-specific loading
 * - Hot-reload detection in development
 *
 * Usage:
 *   // Bootstrap environment repository via MonkeysLegion-Env
 *   $env = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
 *   $parser = new MlcParser($env, $rootPath);
 *
 *   $loader = new Loader($parser, '/path/to/config');
 *   $config = $loader->load(['app', 'cors']);
 */
final class Loader implements LoaderInterface
{
    // ── State ──────────────────────────────────────────────────

    /**
     * Whether environment variables have been loaded.
     */
    private ?ConfigValidatorInterface $validator = null;

    /**
     * Hook listeners.
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    // ── Lifecycle ──────────────────────────────────────────────

    /**
     * @param ParserInterface                    $parser         Parser implementation
     * @param string                             $baseDir        Directory containing .mlc files
     * @param CacheInterface|CacheStoreInterface|null $cache    Optional ML Cache 2.0 or PSR-16 cache
     * @param bool                               $strictSecurity Throw exceptions if files are world-writable
     */
    public function __construct(
        private ParserInterface $parser,
        private string $baseDir,
        private CacheInterface|CacheStoreInterface|null $cache = null,
        bool $strictSecurity = false,
    ) {
        if ($strictSecurity) {
            $this->parser->enableStrictSecurity();
        }

        // Validate base directory
        if (!is_dir($this->baseDir)) {
            throw new LoaderException(
                "Config directory not found: {$this->baseDir}",
            );
        }

        if (!is_readable($this->baseDir)) {
            throw new LoaderException(
                "Config directory not readable: {$this->baseDir}",
            );
        }
    }

    // ── Public API ──────────────────────────────────────────────

    /**
     * Load and merge multiple named files.
     *
     * Later files override earlier keys using array_replace_recursive.
     *
     * @param string[] $names Config file names (without .mlc extension)
     * @param bool $useCache Whether to use cache (default: true)
     * @return Config
     * @throws LoaderException
     */
    public function load(array $names, bool $useCache = true): Config
    {
        if (empty($names)) {
            throw new LoaderException("No config files specified");
        }

        // Generate cache key
        $cacheKey = $this->generateCacheKey($names);

        // ── Hooks: onLoading ────────────────────────────────────────────────
        $this->emit(LoaderHook::Loading, $names);

        // ── Fast-path: CompiledPhpCache ─────────────────────────────────────
        // Compiled cache stores raw arrays — no metadata envelope needed.
        // OPcache will serve this from shared memory on warm hits.
        if ($useCache && $this->cache instanceof CompiledPhpCache) {
            $compiled = $this->cache->get($cacheKey);
            if (is_array($compiled)) {
                $config = new Config($compiled);
                $this->emit(LoaderHook::Loaded, $config);
                return $config;
            }
        }

        // ── ML Cache 2.0 fast-path: use remember() ──────────────────────────
        // Cache 2.0 stores support remember() which handles get-or-compute
        // atomically. We still use the envelope format for mtime validation.
        if ($useCache && $this->cache instanceof CacheStoreInterface && !($this->cache instanceof CompiledPhpCache)) {
            try {
                $cached = $this->cache->get($cacheKey);

                if (is_array($cached) && $this->isCacheValid($names, $cached)) {
                    $data = $cached['data'] ?? null;
                    if (is_array($data)) {
                        $config = new Config($data);
                        $this->emit(LoaderHook::Loaded, $config);
                        return $config;
                    }
                }
            } catch (\Throwable) {
                // Cache read failed, continue to load from files
            }
        }

        // ── Standard PSR-16 cache (envelope with mtime validation) ──────────
        if ($useCache && $this->cache !== null
            && !($this->cache instanceof CompiledPhpCache)
            && !($this->cache instanceof CacheStoreInterface)
        ) {
            try {
                $cached = $this->cache->get($cacheKey);

                if (is_array($cached) && $this->isCacheValid($names, $cached)) {
                    $data = $cached['data'] ?? null;
                    if (is_array($data)) {
                        $config = new Config($data);
                        $this->emit(LoaderHook::Loaded, $config);
                        return $config;
                    }
                }
            } catch (\Throwable) {
                // Cache read failed, continue to load from files
            }
        }

        // Load and merge configs
        $merged = [];
        $files = [];

        foreach ($names as $name) {
            $path = $this->resolveConfigPath($name);

            try {
                $data = $this->parser->parseFile($path);

                // Always track the main resolved path for cache invalidation
                $realPath = realpath($path);
                if ($realPath && !in_array($realPath, $files, true)) {
                    $files[] = $realPath;
                }

                // Track ANY sub-files involved (e.g. recursive @includes from MlcParser)
                foreach ($this->parser->getParsedFiles() as $parsedPath) {
                    if (!in_array($parsedPath, $files, true)) {
                        $files[] = $parsedPath;
                    }
                }

                $merged = array_replace_recursive($merged, $data);
            } catch (\Throwable $e) {
                throw new LoaderException(
                    "Failed to load config '{$name}': {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        // Validate if validator is set
        if ($this->validator !== null) {
            $errors = $this->validator->validate($merged);
            if (!empty($errors)) {
                // ── Hooks: onValidationError ────────────────────────────────────────
                $this->emit(LoaderHook::ValidationError, $errors, $merged);

                throw new LoaderException(
                    "Config validation failed:\n" . implode("\n", $errors)
                );
            }
        }

        // Cache the result
        if ($useCache && $this->cache !== null) {
            try {
                if ($this->cache instanceof CompiledPhpCache) {
                    // Compiled cache: store the raw array directly — no envelope.
                    $this->cache->set($cacheKey, $merged);
                } else {
                    // Standard PSR-16: store with mtime metadata for invalidation.
                    $this->cache->set($cacheKey, [
                        'data'      => $merged,
                        'files'     => $files,
                        'mtimes'    => $this->getFileMtimes($files),
                        'timestamp' => time(),
                    ]);
                }
            } catch (\Throwable) {
                // Cache write failed, but continue
            }
        }

        $config = new Config($merged);

        // ── Hooks: onLoaded ──────────────────────────────────────────────────
        $this->emit(LoaderHook::Loaded, $config);

        return $config;
    }

    /**
     * Load a single config file.
     *
     * Convenience method for load($name).
     */
    public function loadOne(string $name, bool $useCache = true): Config
    {
        return $this->load([$name], $useCache);
    }

    /**
     * Reload without cache.
     *
     * Forces fresh file parse.
     *
     * @param array<string> $names
     */
    public function reload(array $names): Config
    {
        return $this->load($names, useCache: false);
    }

    /**
     * Check if any config files have changed.
     *
     * @param array<string> $names
     */
    public function hasChanges(array $names): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $cacheKey = $this->generateCacheKey($names);

        try {
            $cached = $this->cache->get($cacheKey);

            if (!is_array($cached)) {
                return true;
            }

            // CompiledPhpCache stores raw bytecode arrays—it doesn't support drift detection.
            // If the data exists, we assume it's valid.
            if ($this->cache instanceof CompiledPhpCache) {
                return false;
            }

            return !$this->isCacheValid($names, $cached);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Set a validator instance.
     */
    public function setValidator(?ConfigValidatorInterface $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * Clear all cached config files.
     *
     * @return void
     */
    public function clearCache(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->clear();
        } catch (\Throwable $e) {
            throw new LoaderException(
                "Failed to clear cache: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Trigger fresh parse and write to cache.
     */
    public function compile(array $names): Config
    {
        // Force fresh load
        $config = $this->load($names, useCache: false);
        $merged = $config->all();

        // Write to cache
        if ($this->cache !== null) {
            $cacheKey = $this->generateCacheKey($names);
            try {
                if ($this->cache instanceof CompiledPhpCache) {
                    $this->cache->set($cacheKey, $merged);
                } else {
                    $files = [];
                    foreach ($names as $name) {
                        $path = $this->resolveConfigPath($name);
                        $realPath = realpath($path);
                        if ($realPath && !in_array($realPath, $files, true)) {
                            $files[] = $realPath;
                        }
                    }

                    foreach ($this->parser->getParsedFiles() as $pf) {
                        if (!in_array($pf, $files, true)) {
                            $files[] = $pf;
                        }
                    }

                    $this->cache->set($cacheKey, [
                        'data'      => $merged,
                        'files'     => $files,
                        'mtimes'    => $this->getFileMtimes($files),
                        'timestamp' => time(),
                    ]);
                }
            } catch (\Throwable $e) {
                throw new ConfigException("Failed to write cache: {$e->getMessage()}", 0, $e);
            }
        }

        return $config;
    }

    /**
     * Register a hook listener.
     */
    public function on(LoaderHook $hook, callable $callback): self
    {
        $this->listeners[$hook->value][] = $callback;
        return $this;
    }

    /**
     * Register a listener for the 'onLoading' hook.
     */
    public function onLoading(callable $callback): self
    {
        return $this->on(LoaderHook::Loading, $callback);
    }

    /**
     * Register a listener for the 'onLoaded' hook.
     */
    public function onLoaded(callable $callback): self
    {
        return $this->on(LoaderHook::Loaded, $callback);
    }

    /**
     * Register a listener for the 'onValidationError' hook.
     */
    public function onValidationError(callable $callback): self
    {
        return $this->on(LoaderHook::ValidationError, $callback);
    }

    /**
     * Emit a hook event.
     */
    private function emit(LoaderHook $hook, mixed ...$args): void
    {
        foreach ($this->listeners[$hook->value] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    // ── Internals ──────────────────────────────────────────────

    /**
     * Resolve full path to a config file.
     *
     * Tries extensions in order: .mlc, .json, .yaml, .yml, .php
     * if no extension is provided in $name.
     */
    private function resolveConfigPath(string $name): string
    {
        // 1. Check if name already has a supported extension
        $path = $this->baseDir . '/' . $name;
        if (preg_match('/\.(mlc|json|yaml|yml|php)$/', $name) && file_exists($path)) {
            return $path;
        }

        // 2. Try common extensions in priority order
        $extensions = ['mlc', 'json', 'yaml', 'yml', 'php'];
        foreach ($extensions as $ext) {
            $testPath = $this->baseDir . '/' . $name . '.' . $ext;
            if (file_exists($testPath)) {
                return $testPath;
            }
        }

        throw new LoaderException("Config file not found: {$path} (tried extensions: " . implode(', ', $extensions) . ")");
    }

    /**
     * Generate a cache key from config names.
     *
     * @param array<string> $names
     */
    private function generateCacheKey(array $names): string
    {
        return 'mlc_' . md5(implode('|', $names));
    }

    /**
     * Check if cached data is still valid.
     *
     * @param array<string> $names
     * @param array<string, mixed> $cached
     */
    private function isCacheValid(array $names, array $cached): bool
    {
        if (!isset($cached['files'], $cached['mtimes']) || !is_array($cached['files']) || !is_array($cached['mtimes'])) {
            return false;
        }

        /** @var array<int|string, mixed> $files */
        $files = $cached['files'];
        /** @var array<int|string, mixed> $mtimes */
        $mtimes = $cached['mtimes'];

        // Check if any file has been modified
        foreach ($files as $i => $file) {
            if (!is_string($file) || !file_exists($file)) {
                return false;
            }

            $currentMtime = filemtime($file);
            if (!isset($mtimes[$i]) || $currentMtime !== $mtimes[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get modification times for all files.
     *
     * @param array<string> $files
     * @return array<int, int|false>
     */
    private function getFileMtimes(array $files): array
    {
        return array_map(fn($file) => filemtime($file), $files);
    }
}
