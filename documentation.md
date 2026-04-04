# MonkeysLegion MLC — Developer Documentation

**MonkeysLegion MLC** is a high-performance, production-ready configuration engine for PHP 8.4+ applications. It is the official configuration format for the MonkeysLegion ecosystem, designed around a single core principle: **parse once, serve from bytecode forever**.

---

## Table of Contents

1. [Core Philosophy](#-core-philosophy)
2. [Installation](#-installation)
3. [The `.mlc` Format](#️-the-mlc-format)
4. [Loading Configuration](#-loading-configuration)
5. [Reading Values](#-reading-values)
6. [OPcache Pre-compilation](#-opcache-pre-compilation-recommended-for-production)
7. [Standard PSR-16 Caching](#-standard-psr-16-caching)
8. [Dual-Layer Runtime Overrides](#-dual-layer-runtime-overrides)
9. [Locking & Immutability](#-locking--immutability)
10. [Atomic Snapshots](#-atomic-snapshots)
11. [Schema Validation](#-schema-validation)
12. [Security Features](#-security-features)
13. [Multi-Format Support](#-multi-format-support)
14. [CLI Tooling (`mlc-check`)](#-cli-tooling-mlc-check)
15. [API Reference](#-api-reference)

---

## 🚀 Core Philosophy

MLC is built on four pillars:

1. **Zero-Overhead Production Mode** — Configuration is compiled to static PHP arrays and served directly from OPcache shared memory. No file parsing, no deserialization, no I/O on every request.
2. **Security First** — Hardened against path traversal, world-writable files, circular references, and oversized inputs.
3. **Strict Typing** — Typed getters enforce value types at the boundary, catching misconfigurations early.
4. **Layered Mutability** — An immutable compiled base with an opt-in, non-destructive override layer for runtime use cases like feature flags and multi-tenancy.

---

## 📦 Installation

```bash
composer require monkeyscloud/monkeyslegion-mlc
```

---

## 🛠️ The `.mlc` Format

MLC files use a clean, readable syntax inspired by `.env` and structured config formats.

### Key-Value Pairs

Both `=` and whitespace are valid separators:

```mlc
app_name = "My Application"
debug    true
port     8080
```

### Sections (Nesting)

```mlc
database {
    host = localhost
    port = 3306

    credentials {
        user = app_user
        pass = ${DB_PASSWORD}
    }
}
```

### Arrays & JSON Objects

```mlc
allowed_ips = ["127.0.0.1", "10.0.0.1"]

# Multi-line arrays are supported
features = [
    "caching",
    "validation",
    "security"
]
```

### Supported Value Types

| MLC syntax           | PHP type |
|----------------------|----------|
| `true` / `false`     | `bool`   |
| `null`               | `null`   |
| `3306`               | `int`    |
| `3.14`               | `float`  |
| `"hello"` / `'hello'`| `string` |
| `[1, 2, 3]`          | `array`  |
| `{"a": 1}`           | `array`  |

### Environment Variable Expansion

```mlc
# Simple lookup — throws if missing (unless a default is given)
db_pass = ${DB_PASSWORD}

# Lookup with fallback default
db_port = ${DB_PORT:-3306}

# Inline interpolation (result is always a string)
api_url = "https://${HOST:-localhost}:${PORT:-8080}/v1"
```

### Cross-Key References

Keys can reference other keys defined in the same file:

```mlc
base_url = "https://api.example.com"
health   = "${base_url}/health"
```

> **Circular reference protection**: MLC uses a Dependency Graph Tracker. If a circular reference is detected (`a = ${b}`, `b = ${a}`), a `CircularDependencyException` is thrown immediately.

### Recursive Includes

You can split your configuration into multiple files and include them using the `@include` statement. Paths are resolved relative to the current file.

#### Supported Syntaxes

At least one space is required between `@include` and the path.

| Syntax             | Example                           | Note                                                         |
|--------------------|-----------------------------------|--------------------------------------------------------------|
| **Unquoted**       | `@include base.mlc`               | Recommended for simple filenames; cannot contain spaces.     |
| **Quotes**         | `@include "extra settings.mlc"`   | Single or double quotes; required if path contains spaces. |
| **Angle Brackets** | `@include <shared.mlc>`           | C-style inclusion; provides an alternative clear grouping. |

```mlc
# app.mlc
app_name = "My Application"

# Top-level inclusion
@include database.mlc

# Scoped inclusion
network {
    @include "network_defaults.mlc"
    port = 8080 # Overrides anything from network_defaults.mlc
}
```

> **Circular include protection**: MLC tracks the inclusion stack. If a file tries to include itself or a file currently being parsed, a `ParserException` is thrown.

---

## 📖 Loading Configuration

The `Loader` is the primary entry point. It requires a `Parser` instance and a base directory containing `.mlc` files.

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;

$loader = new Loader(new MlcParser(), __DIR__ . '/config');

// Load and merge one or more named files (without .mlc extension)
$config = $loader->load(['app', 'database']);

// Shorthand for a single file
$config = $loader->loadOne('app');

// Force a fresh parse, bypassing any cache
$config = $loader->reload(['app', 'database']);
```

Multiple files are merged left-to-right with `array_replace_recursive` — later files win on key conflicts.

---

## 📥 Reading Values

### Dot-Notation Access

```php
$config->get('database.host');              // mixed, null if missing
$config->get('database.port', 3306);       // with default
$config->has('database.host');             // bool
$config->getRequired('database.host');     // throws ConfigException if missing
```

### Typed Getters

All typed getters return `null` (or the provided `$default`) when the path is absent, and throw `ConfigException` when the value exists but is the wrong type.

```php
$host    = $config->getString('database.host', 'localhost');
$port    = $config->getInt('database.port', 3306);
$timeout = $config->getFloat('database.timeout', 5.0);
$debug   = $config->getBool('app.debug', false);
$drivers = $config->getArray('database.drivers', []);
```

### Export

```php
$config->all();      // array — compiled base data
$config->toArray();  // alias for all()
$config->toJson();   // JSON string (pretty-printed, throws JsonException on error)
$config->subset('database'); // new Config scoped to the 'database' section
$config->merge($other);      // new Config with $other merged on top
```

---

## ⚡ OPcache Pre-compilation (Recommended for Production)

`CompiledPhpCache` converts your `.mlc` files into static PHP files (`<?php return [...];`). PHP's OPcache stores the resulting opcode array in **shared memory** — subsequent `load()` calls are a direct memory read with no file I/O, no parsing, and no deserialization.

### Setup

```php
use MonkeysLegion\Mlc\Cache\CompiledPhpCache;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;

$cache  = new CompiledPhpCache('/var/cache/mlc');
$loader = new Loader(new MlcParser(), __DIR__ . '/config', cache: $cache);
```

### Compile once — serve forever

```php
// ── Deployment / warm-up script (run once per deploy) ──────────────────
$loader->compile(['app', 'database', 'cors']);
// ^ Parses .mlc files, writes /var/cache/mlc/*.generated.php

// ── Every HTTP request (zero-overhead) ─────────────────────────────────
$config = $loader->load(['app', 'database', 'cors']);
// ^ require → OPcache → array. No parsing, no disk I/O.
```

### Design contract

| Property | Behaviour |
|---|---|
| **TTL** | Deliberately ignored. Compiled files never self-expire. |
| **Eviction** | Explicit only: `compile()`, `delete()`, or `clear()`. |
| **Re-compile** | Call `compile()` again after a config change (e.g. in your deploy pipeline). |
| **Atomicity** | Writes use a temp file + `rename()` to prevent half-written reads. |
| **OPcache safety** | Existing bytecode is invalidated via `opcache_invalidate()` before each write. |

---

## 🗄️ Standard PSR-16 Caching

For environments without OPcache, or where `mtime`-based auto-invalidation is preferred, pass any PSR-16 implementation:

```php
use MonkeysLegion\Cache\CacheManager;

$cache = (new CacheManager($cacheConfig))->store('redis');
$loader = new Loader(new MlcParser(), __DIR__ . '/config', cache: $cache);

$config = $loader->load(['app']); // auto-invalidates when source files change
```

The standard cache stores a metadata envelope `{data, files, mtimes}` and re-parses automatically when a source file's `mtime` changes.

---

## 🔄 Dual-Layer Runtime Overrides

The dual-layer engine lets you apply **non-destructive runtime overrides** on top of the immutable compiled base. The compiled array is never touched.

### How it works

```
┌──────────────────────────────────────────────────────┐
│ Layer 2: $runtimeOverrides  (dormant until first use) │
│ Layer 1: $data              (compiled base, read-only) │
└──────────────────────────────────────────────────────┘

get() with dormant layer  →  direct lookup in $data (zero overhead)
get() with active layer   →  $runtimeOverrides[$path] ?? $data traversal
```

The override layer is **lazy** — it activates automatically on the first `override()` call. Before that, `get()` reads directly from the compiled base with no extra checks.

### Usage

```php
// Load from OPcache — compiled base, no locks, dual-layer dormant
$config = $loader->load(['app']);

// Apply runtime overrides — activates dual-layer on first call
$config->override('app.debug', true);
$config->override('database.host', 'replica.internal');

// get() checks override layer first
echo $config->get('app.debug');         // true  (override)
echo $config->get('app.name');          // "My Application"  (compiled base)

// all() always returns the compiled base — overrides are NOT included
$base = $config->all();                 // compiled base only

// Inspect active overrides
$overrides = $config->getOverrides();   // ['app.debug' => true, ...]

// Is the dual-layer currently active?
$active = $config->isDualLayerActive(); // true after first override()
```

---

## 🔒 Locking & Immutability

Two explicit locks give you fine-grained control. **No automatic locking** — the Loader returns an unlocked Config; you decide if and when to lock.

### Lock 1 — `lock()` : Sealed, read-only

Prevents **any** `override()` call. Use immediately after `load()` when you want a permanently immutable config.

```php
$config = $loader->load(['app']);
$config->lock(); // sealed — no overrides ever

$config->override('x', 1); // ❌ throws FrozenConfigException("config is sealed")

// Reading and snapshots always work
$config->get('app.name');    // ✅
$config->snapshot();         // ✅
```

### Lock 2 — `lockOverrides()` : Override layer sealed

Prevents **further** `override()` calls after a set of overrides has been applied. Already-applied overrides remain visible.

```php
$config = $loader->load(['app']);
$config->override('feature.dark_mode', true);
$config->override('feature.beta',      false);
$config->lockOverrides(); // no more changes

$config->override('anything', 'x'); // ❌ throws FrozenConfigException("override layer is sealed")

// Existing overrides are still readable
$config->get('feature.dark_mode'); // ✅ true
```

### Immutability contract

| Operation | No locks | `lock()` | `lockOverrides()` |
|---|:---:|:---:|:---:|
| `get()` / typed getters | ✅ | ✅ | ✅ |
| `override()` | ✅ | ❌ | ❌ |
| `snapshot()` | ✅ | ✅ | ✅ |
| `isDualLayerActive()` | ✅ | ✅ | ✅ |

> Both locks prevent `override()`. The difference is **intent and timing**: `lock()` seals from the start (no overrides ever); `lockOverrides()` seals after you have applied your desired overrides.

### Introspection

```php
$config->isLocked();           // bool — was lock() called?
$config->areOverridesLocked(); // bool — was lockOverrides() called?
$config->isDualLayerActive();  // bool — has override() been called at least once?
```

---

## 📸 Atomic Snapshots

`snapshot()` **flattens** the compiled base and the current override layer into a fresh, completely independent `Config` instance. The original is unaffected.

The new instance:
- starts with **no locks** applied
- starts with the **dual-layer dormant** (overrides are baked into the base)
- is **fully isolated** — mutations to the original do not bleed through

This is the recommended pattern for **long-running processes** (RoadRunner, Swoole, ReactPHP) that need per-request state isolation:

```php
// Boot: load once, apply global overrides
$base = $loader->load(['app']);
$base->override('app.env', getenv('APP_ENV') ?: 'production');
$base->lockOverrides(); // sealed — no further global changes

// Per-request worker
$requestConfig = $base->snapshot();           // isolated copy, unlocked
$requestConfig->override('tenant.id', $tenantId);
$requestConfig->override('locale', $request->getLocale());
// $base is completely unaffected
```

```php
// Snapshot with no overrides — efficiently clones the compiled base
$snap = $config->snapshot();
$snap->isDualLayerActive(); // false — starts fresh
```

---

## ✅ Schema Validation

Attach a validator to the Loader to enforce structural constraints before the `Config` object is returned:

```php
use MonkeysLegion\Mlc\Validator\SchemaValidator;

$schema = [
    'app' => [
        'type'     => 'array',
        'children' => [
            'env'  => ['type' => 'string', 'enum' => ['dev', 'staging', 'production']],
            'port' => ['type' => 'int',    'min'  => 1024, 'max' => 65535],
            'name' => ['type' => 'string', 'pattern' => '/^[A-Za-z ]+$/'],
        ],
    ],
    'database' => [
        'type'     => 'array',
        'required' => true,
        'children' => [
            'host' => ['type' => 'string', 'required' => true],
            'port' => ['type' => 'int',    'required' => true],
        ],
    ],
];

$loader->setValidator(new SchemaValidator($schema));
$config = $loader->load(['app', 'database']); // throws LoaderException on failure
```

---

## 🚨 Security Features

MLC is designed to be secure by default:

| Feature | Detail |
|---|---|
| **Path traversal protection** | File paths containing `..` are rejected before any read. |
| **File existence validation** | `realpath()` is used — symlinks are resolved and checked. |
| **Permission auditing** | World-writable files trigger a `E_USER_WARNING` by default. |
| **Strict mode** | Pass `strictSecurity: true` to `Loader` to throw `SecurityException` instead of warning. |
| **File size limit** | Files larger than 10 MB are rejected (`SecurityException`). |
| **Circular reference detection** | Cross-key and env-var cycles throw `CircularDependencyException`. |

```php
// Enable strict security mode
$loader = new Loader(new MlcParser(), __DIR__ . '/config', strictSecurity: true);
```

---

## 📂 Multi-Format Support

MLC supports loading and merging configuration from multiple file formats beyond the native `.mlc` syntax. This is achieved using the `CompositeParser` that delegates to specialized native parsers based on file extensions.

### Supported Formats

| Format   | Extension        | Notes                                   |
|----------|------------------|-----------------------------------------|
| **MLC**  | `.mlc`           | Full support (includes, env vars, etc.) |
| **JSON** | `.json`          | Decoded via `json_decode`.              |
| **YAML** | `.yaml` / `.yml` | Native lightweight parser.              |
| **PHP**  | `.php`           | Executed files that `return` an array.  |

See the [Multi-Format Support Documentation](multi_format_support.md) for a deep dive into using the `CompositeParser`.

---

## 🛠️ CLI Tooling (`mlc-check`)

MLC includes a native, standalone CLI validator for your configuration files. It is designed to be used in development and CI/CD pipelines to catch syntax errors or security risks before deployment.

### Features
- **Zero Dependencies**: Built with native PHP (no `symfony/console`).
- **Deep Validation**: Checks syntax, circular references, and file permissions.
- **Recursive Scan**: Validates all `.mlc`, `.json`, `.yaml`, and `.php` files in a directory.

### Usage
```bash
# Validate a single file
php bin/mlc-check ./config/app.mlc

# Validate all supported files in a directory
php bin/mlc-check ./config
```

---

## 📚 API Reference

### `Loader`

| Method | Signature | Description |
|---|---|---|
| `load` | `(string[] $names, bool $useCache = true): Config` | Load and merge named config files. |
| `loadOne` | `(string $name, bool $useCache = true): Config` | Load a single config file. |
| `reload` | `(string[] $names): Config` | Force fresh parse, bypass cache. |
| `compile` | `(string[] $names): Config` | Compile to OPcache PHP file (requires `CompiledPhpCache`). |
| `hasChanges` | `(string[] $names): bool` | Detect if source files have changed since last cache write. |
| `clearCache` | `(): void` | Clear all cache entries. |
| `setValidator` | `(?ConfigValidatorInterface $v): self` | Attach a schema validator. |

### `Config`

#### Read

| Method | Returns | Description |
|---|---|---|
| `get(path, default)` | `mixed` | Dot-notation lookup. Override layer checked first when active. |
| `has(path)` | `bool` | True if path exists in either layer. |
| `getRequired(path)` | `mixed` | Throws `ConfigException` if missing. |
| `getString(path, default)` | `?string` | Typed getter. |
| `getInt(path, default)` | `?int` | Typed getter. |
| `getFloat(path, default)` | `?float` | Typed getter. |
| `getBool(path, default)` | `?bool` | Typed getter. |
| `getArray(path, default)` | `?array` | Typed getter. |

#### Export

| Method | Returns | Description |
|---|---|---|
| `all()` | `array` | Compiled base data (overrides excluded). |
| `toArray()` | `array` | Alias for `all()`. |
| `toJson(flags)` | `string` | JSON-encoded compiled base. |
| `subset(prefix)` | `Config` | New Config scoped to a sub-section. |
| `merge(Config)` | `Config` | New Config with another merged on top. |

#### Dual-Layer Overrides

| Method | Returns | Description |
|---|---|---|
| `override(path, value)` | `void` | Apply a runtime override. Activates dual-layer on first call. |
| `getOverrides()` | `array` | Map of all current runtime overrides. |
| `isDualLayerActive()` | `bool` | True after first `override()` call. |

#### Locks

| Method | Returns | Description |
|---|---|---|
| `lock()` | `self` | Lock 1: seal config — no overrides allowed. |
| `lockOverrides()` | `self` | Lock 2: seal override layer — no further overrides. |
| `isLocked()` | `bool` | True if `lock()` was called. |
| `areOverridesLocked()` | `bool` | True if `lockOverrides()` was called. |

#### Snapshots

| Method | Returns | Description |
|---|---|---|
| `snapshot()` | `Config` | Flatten compiled base + overrides into a new, independent, unlocked Config. |

#### Cache internals

| Method | Returns | Description |
|---|---|---|
| `clearCache()` | `void` | Purge internal dot-path lookup cache. |
| `getCacheStats()` | `array` | `{size: int, keys: string[]}` — for debugging. |

### `CompiledPhpCache`

Implements PSR-16 `CacheInterface`. TTL is accepted by the interface but **silently ignored** — the cache is immutable until explicitly evicted.

| Method | Description |
|---|---|
| `get(key, default)` | `require` the compiled PHP file; returns `$default` if file not found. |
| `set(key, value, ttl)` | Write compiled PHP file (TTL ignored). Atomic write + OPcache invalidation. |
| `delete(key)` | Delete compiled file and invalidate OPcache entry. |
| `clear()` | Delete all `*.generated.php` files in the cache directory. |
| `has(key)` | `is_file()` check — no extra `require`. |
| `getMultiple / setMultiple / deleteMultiple` | PSR-16 bulk helpers. |

---

## 🛠️ Testing & Quality

```bash
./vendor/bin/phpunit --testdox   # Run full test suite
composer stan                    # PHPStan Level 9
composer ci                      # Full CI pipeline
```
