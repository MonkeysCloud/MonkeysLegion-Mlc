<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Validator\ConfigValidator;
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
 *   $loader = new Loader(new Parser(), '/path/to/config');
 *   $config = $loader->load(['app', 'cors']);
 *
 * With caching (using MonkeysLegion-Cache):
 *   use MonkeysLegion\Cache\CacheManager;
 *   
 *   $cacheConfig = [
 *       'default' => 'file',
 *       'stores' => [
 *           'file' => [
 *               'driver' => 'file',
 *               'path' => '/var/cache/mlc',
 *               'prefix' => 'mlc_',
 *           ]
 *       ]
 *   ];
 *   $cacheManager = new CacheManager($cacheConfig);
 *   $cache = $cacheManager->store('file');
 *   
 *   $loader = new Loader(
 *       new Parser(),
 *       '/path/to/config',
 *       cache: $cache
 *   );
 */
final class Loader
{
    /**
     * Whether environment variables have been loaded.
     */
    private bool $envLoaded = false;
    private ?ConfigValidator $validator = null;
    private bool $autoFreeze = true;

    /**
     * Loader constructor.
     *
     * @param Parser $parser Parser instance
     * @param string $baseDir Directory containing .mlc files
     * @param string|null $envDir Directory containing .env files (defaults to baseDir)
     * @param CacheInterface|null $cache Optional PSR-16 cache implementation
     * @param bool $autoLoadEnv Automatically load .env files (default: true)
     */
    public function __construct(
        private Parser $parser,
        private string $baseDir,
        private ?string $envDir = null,
        private ?CacheInterface $cache = null,
        private bool $autoLoadEnv = true
    ) {
        // Validate base directory
        if (!is_dir($this->baseDir)) {
            throw new LoaderException(
                "Config directory not found: {$this->baseDir}"
            );
        }

        if (!is_readable($this->baseDir)) {
            throw new LoaderException(
                "Config directory not readable: {$this->baseDir}"
            );
        }

        // Auto-load environment if enabled
        if ($this->autoLoadEnv) {
            $this->loadEnvironment();
        }
    }

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

        // Try cache first
        if ($useCache && $this->cache !== null) {
            try {
                $cached = $this->cache->get($cacheKey);
                
                if ($cached !== null && $this->isCacheValid($names, $cached)) {
                    $config = new Config($cached['data']);
                    
                    if ($this->autoFreeze) {
                        $config->freeze();
                    }
                    
                    return $config;
                }
            } catch (\Throwable $e) {
                // Cache read failed, continue to load from files
            }
        }

        // Load and merge configs
        $merged = [];
        $files = [];
        
        foreach ($names as $name) {
            $path = $this->resolveConfigPath($name);
            $files[] = $path;
            
            try {
                $data = $this->parser->parseFile($path);
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
                throw new LoaderException(
                    "Config validation failed:\n" . implode("\n", $errors)
                );
            }
        }

        // Cache the result
        if ($useCache && $this->cache !== null) {
            try {
                $this->cache->set($cacheKey, [
                    'data' => $merged,
                    'files' => $files,
                    'mtimes' => $this->getFileMtimes($files),
                    'timestamp' => time(),
                ]);
            } catch (\Throwable $e) {
                // Cache write failed, but continue
            }
        }

        $config = new Config($merged);
        
        if ($this->autoFreeze) {
            $config->freeze();
        }
        
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
            
            if ($cached === null) {
                return true;
            }
            
            return !$this->isCacheValid($names, $cached);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Set a validator instance.
     */
    public function setValidator(?ConfigValidator $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * Enable/disable auto-freeze.
     *
     * When enabled, all loaded configs are frozen automatically.
     */
    public function setAutoFreeze(bool $enabled): self
    {
        $this->autoFreeze = $enabled;
        return $this;
    }

    /**
     * Check if auto-freeze is enabled.
     */
    public function isAutoFreezeEnabled(): bool
    {
        return $this->autoFreeze;
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
                $e
            );
        }
    }

    /**
     * Load environment files.
     *
     * Loads in order:
     * - .env
     * - .env.local
     * - .env.{APP_ENV}
     * - .env.{APP_ENV}.local
     *
     * @return void
     */
    private function loadEnvironment(): void
    {
        if ($this->envLoaded) {
            return;
        }

        $dir = $this->envDir ?? $this->baseDir;

        if (!is_dir($dir)) {
            $this->envLoaded = true;
            return;
        }

        // 1. Resolve APP_ENV (checked in this order: Server/Env vars -> .env.local -> .env)
        $appEnv = $this->resolveAppEnv($dir);

        // 2. Build priority list (Most specific first -> Least specific last)
        $candidates = [];

        if ($appEnv !== null) {
            $candidates[] = ".env.{$appEnv}.local";
            $candidates[] = ".env.{$appEnv}";
        }

        $candidates[] = '.env.local';
        $candidates[] = '.env';

        // 3. Filter for existing files
        $filesToLoad = [];
        foreach ($candidates as $file) {
            if (file_exists($dir . '/' . $file)) {
                $filesToLoad[] = $file;
            }
        }

        // 4. Load valid files (Immutability ensures first loaded value wins)
        if (!empty($filesToLoad)) {
            try {
                // We use load() because we've already vetted file existence
                Dotenv::createImmutable($dir, $filesToLoad)->load();
            } catch (InvalidPathException $e) {
                // Should not happen since we checked file_exists, but safe fallback
            }
        }

        $this->envLoaded = true;
    }

    /**
     * Resolve APP_ENV without fully loading .env files.
     */
    private function resolveAppEnv(string $dir): ?string
    {
        // 1. Check existing environment
        if (isset($_SERVER['APP_ENV'])) {
            return (string)$_SERVER['APP_ENV'];
        }
        if (isset($_ENV['APP_ENV'])) {
            return (string)$_ENV['APP_ENV'];
        }

        // 2. Peek into .env.local and .env
        $filesToCheck = ['.env.local', '.env'];

        foreach ($filesToCheck as $file) {
            $path = $dir . '/' . $file;
            if (!file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Simple regex to find APP_ENV=value
            // Supports: APP_ENV=dev, APP_ENV="dev", APP_ENV='dev'
            if (preg_match('/^\s*APP_ENV=(?:["\']?)([^"\'].+?)(?:["\']?)\s*$/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Resolve full path to a config file.
     */
    private function resolveConfigPath(string $name): string
    {
        $path = $this->baseDir . '/' . $name . '.mlc';

        if (!file_exists($path)) {
            throw new LoaderException("Config file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new LoaderException("Config file not readable: {$path}");
        }

        return $path;
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
        if (!isset($cached['files'], $cached['mtimes'])) {
            return false;
        }

        // Check if number of files matches
        if (count($cached['files']) !== count($names)) {
            return false;
        }

        // Check if any file has been modified
        foreach ($cached['files'] as $i => $file) {
            if (!file_exists($file)) {
                return false;
            }

            $currentMtime = filemtime($file);
            if ($currentMtime !== $cached['mtimes'][$i]) {
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
