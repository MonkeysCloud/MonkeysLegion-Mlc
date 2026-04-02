# MonkeysLegion MLC - Developer Documentation

Welcome to the official developer documentation for **MonkeysLegion MLC** (MonkeysLegion Config). This package provides a high-performance, production-ready configuration system for PHP applications, specifically designed as the core configuration format for the MonkeysLegion ecosystem.

---

## 🚀 Core Philosophy

MLC is built on three pillars:
1.  **Performance**: Near-instant loading via PSR-16 caching and optimized parsing.
2.  **Security**: Hardened against common file-based attacks, misconfigurations, and circular deadlocks.
3.  **Strictness**: Encourages type-safety and immutability to prevent runtime "magic" and configuration drift.

---

## 📦 Installation

Install via Composer:

```bash
composer require monkeyscloud/monkeyslegion-mlc
```

---

## 🛠️ The `.mlc` Format

MLC files use a simple, readable syntax that combines the best of `.env` and nested structure (like JSON or YAML).

### Basic Key-Value Pairs
You can use either `=` or whitespace as a separator.
```mlc
app_name = "My App"
debug    true
port     8080
```

### Sections (Nesting)
Sections allow for logical grouping of configuration.
```mlc
database {
    host = localhost
    port = 3306
    
    auth {
        user = root
        pass = secret
    }
}
```

### Arrays & Objects
MLC supports JSON-style arrays and objects, including multi-line support.
```mlc
allowed_ips = ["127.0.0.1", "10.0.0.1"]

features = [
    "caching",
    "validation",
    "security"
]
```

### Environment Expansion & Fallbacks
MLC features robust, native environment variable expansion with built-in default fallbacks. It accurately infers data types directly from the loaded environment variables (casting values like `"true"` to boolean `true`).
```mlc
# Simple lookup
db_pass = ${DB_PASSWORD}

# Lookup with default fallback
db_port = ${DB_PORT:-3306}

# Inline string injection
api_url = "http://${HOST:-localhost}:${PORT:-8080}/v1"
```

### Advanced Cross-Key References
MLC intelligently parses cross-key dependencies within your configuration file. You can reference values dynamically based on other keys. If a variable is not found globally in `$_ENV`, the parser will resolve it against the local configuration map via a dynamic Depth-First Search cycle.
```mlc
base_url = "https://example.com"
api_v1 = "${base_url}/v1"
```
> **Note**: To ensure system safety, MLC utilizes a literal **Dependency Graph Tracker**. If you accidentally create a circular reference (`a = ${b}`, `b = ${a}`), the parser halts execution and throws a `CircularDependencyException`.

---

## 📖 Developer Guide

### 1. Basic Loading
The `Loader` is the primary entry point. It requires a `Parser` and a base directory.

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;

$loader = new Loader(new Parser(), __DIR__ . '/config');
$config = $loader->load(['app', 'database']);
```

### 2. Type-Safe Access
Avoid `mixed` types by using explicit getters. These throw a `ConfigException` if the type doesn't match and seamlessly return the provided `$default` argument when configurations are undefined.

```php
$port   = $config->getInt('database.port', 3306);
$debug  = $config->getBool('app.debug', false);
$name   = $config->getString('app.name', 'My App');
$extras = $config->getArray('extra.features', []);
$host   = $config->getRequired('database.host'); // Throws if missing
```

### 3. Caching (Production Recommendation)
Integrate with `MonkeysLegion-Cache` (or any PSR-16 cache) to avoid re-parsing files on every request.

```php
use MonkeysLegion\Cache\CacheManager;

$cache = (new CacheManager($cacheConfig))->store('redis');
$loader = new Loader(new Parser(), __DIR__ . '/config', cache: $cache);
```

### 4. Schema Validation
Define a schema to ensure your configuration remains structurally valid as it grows. The validation engine strictly checks enumerations, array bounds, numeric limits, custom Regex matchers, strict-mode overages, and arbitrary callback validation.

```php
use MonkeysLegion\Mlc\Validator\SchemaValidator;

$schema = [
    'app' => [
        'type' => 'array',
        'children' => [
            'env' => ['type' => 'string', 'enum' => ['dev', 'stage', 'prod']],
            'port' => ['type' => 'int', 'min' => 1024, 'max' => 65535],
        ]
    ]
];
$loader->setValidator(new SchemaValidator($schema));
```

### 5. Helper Methods
The library also features a native `env($key, $default = null)` helper function that unifies queries against `$_ENV`, `$_SERVER`, and `getenv()`, automatically injecting typed literals like `true/false/null` from strings.

---

## 🚨 Security Features

MLC is designed to be "secure by default":
-   **Path Traversal Protection**: Prevents loading files outside the base directory limit scope.
-   **Circular Reference Detection**: Dependency execution traces paths and denies infinite loop deadlocks outright.
-   **File Size Guard**: Rejects files larger than 10MB to prevent memory exhaustion (OOM attacks).
-   **Permission Audit**: Halts/Warns securely if operating configuration files without adequate write protection or visibility.
-   **Immutability**: The `freeze()` method strictly prevents configuration data from being modified after it has loaded into memory.

---

## 🛠️ Testing & Quality

We maintain a high standard of code quality:
```bash
composer test    # Run PHPUnit suite (100% test passing)
composer stan    # Run PHPStan (Level 9 Strict Mode)
composer ci      # Run full CI pipeline
```
