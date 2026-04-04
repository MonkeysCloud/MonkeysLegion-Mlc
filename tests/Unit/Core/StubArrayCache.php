<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Mlc\Contracts\CacheInterface;

/**
 * Stub PSR-16 cache for testing standard envelope storage.
 */
class StubArrayCache implements CacheInterface
{
    private array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool { $this->data[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool { return true; }
    public function deleteMultiple(iterable $keys): bool { return true; }
    public function has(string $key): bool { return isset($this->data[$key]); }
    public function all(): array { return $this->data; }
}
