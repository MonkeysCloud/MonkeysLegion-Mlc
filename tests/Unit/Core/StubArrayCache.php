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

    // --- CacheStoreInterface Methods ---
    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed {
        if ($this->has($key)) return $this->get($key);
        $val = $callback(); $this->set($key, $val); return $val;
    }
    public function rememberForever(string $key, \Closure $callback): mixed { return $this->remember($key, null, $callback); }
    public function forever(string $key, mixed $value): bool { return $this->set($key, $value); }
    public function pull(string $key, mixed $default = null): mixed { $val = $this->get($key, $default); $this->delete($key); return $val; }
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool {
        if ($this->has($key)) return false;
        return $this->set($key, $value, $ttl);
    }
    public function touch(string $key, \DateInterval|int $ttl): bool { return $this->has($key); }
    public function increment(string $key, int $value = 1): int|false {
        $v = (int)$this->get($key, 0) + $value; $this->set($key, $v); return $v;
    }
    public function decrement(string $key, int $value = 1): int|false { return $this->increment($key, -$value); }
    public function integer(string $key, int $default = 0): int { return (int)$this->get($key, $default); }
    public function boolean(string $key, bool $default = false): bool { return (bool)$this->get($key, $default); }
    public function float(string $key, float $default = 0.0): float { return (float)$this->get($key, $default); }
    public function string(string $key, string $default = ''): string { return (string)$this->get($key, $default); }
    public function array(string $key, array $default = []): array { return (array)$this->get($key, $default); }
    public function flexible(string $key, array $ttl, \Closure $callback, float $beta = 1.0): mixed { return $this->remember($key, null, $callback); }
    public function tags(string|array $names): \MonkeysLegion\Cache\TaggedCache { throw new \LogicException("Not implemented"); }
    public function lock(string $name, int $seconds = 0, ?string $owner = null): \MonkeysLegion\Cache\Lock\LockInterface { throw new \LogicException("Not implemented"); }
    public function getPrefix(): string { return ''; }
    public function getStats(): \MonkeysLegion\Cache\CacheStats { throw new \LogicException("Not implemented"); }
}
