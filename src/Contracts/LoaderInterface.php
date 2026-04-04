<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Contracts;

use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Exception\LoaderException;

/**
 * Interface for configuration loaders.
 */
interface LoaderInterface
{
    /**
     * Load and merge multiple named config files.
     *
     * @param string[] $names Config file names
     * @param bool $useCache Whether to use cache
     * @return Config
     * @throws LoaderException
     */
    public function load(array $names, bool $useCache = true): Config;

    /**
     * Load a single config file.
     *
     * @param string $name
     * @param bool $useCache
     * @return Config
     */
    public function loadOne(string $name, bool $useCache = true): Config;

    /**
     * Force fresh reload (bypass cache).
     *
     * @param string[] $names
     * @return Config
     */
    public function reload(array $names): Config;

    /**
     * Check if any source files changed since last cache write.
     *
     * @param string[] $names
     * @return bool
     */
    public function hasChanges(array $names): bool;

    /**
     * Set a configuration validator.
     *
     * @param ConfigValidatorInterface|null $validator
     * @return self
     */
    public function setValidator(?ConfigValidatorInterface $validator): self;

    /**
     * Clear all caches managed by this loader.
     */
    public function clearCache(): void;

    /**
     * Trigger fresh parse and write to cache.
     *
     * @param string[] $names
     * @return Config
     */
    public function compile(array $names): Config;
}
