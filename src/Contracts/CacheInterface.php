<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Contracts;

use MonkeysLegion\Cache\CacheStoreInterface;

/**
 * Interface for configuration caches.
 *
 * Extends MonkeysLegion Cache 2.0 CacheStoreInterface which itself extends
 * PSR-16 SimpleCache. This ensures compatibility with all ML Cache stores
 * (ArrayStore, RedisStore, FileStore, MemcachedStore) and provides access
 * to v2 methods like remember(), forever(), and tags().
 */
interface CacheInterface extends CacheStoreInterface
{
}
