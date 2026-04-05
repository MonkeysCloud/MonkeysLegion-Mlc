<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Contracts;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * Interface for configuration caches.
 *
 * Extends PSR-16 SimpleCache for compatibility with MonkeysLegion-Cache
 * and other standard implementations.
 */
interface CacheInterface extends PsrCacheInterface
{
}
