<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use RuntimeException;

/**
 * Loads and merges multiple MLC configuration files.
 *
 * Usage:
 *   $loader = new Loader(new Parser(), '/path/to/config');
 *   $config = $loader->load(['app', 'cors']);
 *
 * This will load app.mlc and cors.mlc, merging them into a single Config object.
 */
final class Loader
{

    /**
     * Loader constructor.
     *
     * @param Parser $parser
     * @param string $baseDir
     * @param string|null $envDir
     */
    public function __construct(
        private Parser $parser,
        private string $baseDir,  // directory containing .mlc files
        private ?string $envDir = null
    ) {
        $dir    = $this->envDir ?? $this->baseDir;
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';

        try {
            Dotenv::createImmutable(
                $dir,
                [
                    '.env',
                    '.env.local',
                    ".env.{$appEnv}",
                    ".env.{$appEnv}.local",
                ]
            )->safeLoad();
        } catch (InvalidPathException) {
            // No .env files found — ignore
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "Failed to load environment variables: {$e->getMessage()}"
            );
        }
    }

    /**
     * Load & merge multiple named files: ['app','cors'] → app.mlc, cors.mlc
     *
     * Later files override earlier keys.
     *
     * @param string[] $names
     * @return Config
     */
    public function load(array $names): Config
    {
        $merged = [];
        foreach ($names as $n) {
            $path = "{$this->baseDir}/{$n}.mlc";
            if (! is_file($path)) {
                throw new RuntimeException("Config file missing: {$path}");
            }
            $cfg = $this->parser->parseFile($path);
            $merged = array_replace_recursive($merged, $cfg);
        }
        return new Config($merged);
    }
}