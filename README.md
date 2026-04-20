# MonkeysLegion MLC - Configuration Engine

Production-grade `.mlc` configuration engine for PHP 8.4+. High-performance, zero-overhead, and enterprise-secure.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.4-8892bf.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## 🚀 Why MLC?

MLC is designed for one core task: **parse once, serve from bytecode forever**. It moves configuration beyond simple file loading into a high-performance system for modern PHP environments (RoadRunner, Swoole, or standard FPM).

- ⚡ **Zero-Overhead Production Mode**: Compiles MLC to static PHP arrays for OPcache optimization.
- 🌍 **Deep Environment Integration**: Native `${VAR:-default}` expansion powered by `monkeyslegion-env`.
- 🔒 **Enterprise Security**: Strict permission auditing, path traversal hardening, and circular reference detection.
- 🎯 **Type-Safe DX**: Typed getters (`getString`, `getInt`, etc.) and a dual-layer mutation engine.
- 🪝 **Event-Driven**: Lifecycle hooks (`onLoading`, `onLoaded`) with type-safe enums and proxies.
- 📂 **Multi-Format Support**: Native support for `.mlc`, `.json`, `.yaml`, and `.php` arrays via a composite system.

## 📦 Installation

```bash
composer require monkeyscloud/monkeyslegion-mlc
```

## 🛠️ Basic Usage

### Loading Configuration (Production-Ready)

To use the full power of MLC, you need to initialize the environment bootstrapper and the parser.

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;

// 1. Initialize environment (MonkeysLegion-Env)
$bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());

// 2. Initialize MlcParser with the bootstrapper
$parser = new MlcParser($bootstrapper, $rootPath);

// 3. Initialize Loader
$loader = new Loader(
    parser: $parser,
    baseDir: __DIR__ . '/config'
);

// 4. Load and merge files
$config = $loader->load(['app', 'database']);
```

### Accessing Values

```php
// Type-safe getters
$port  = $config->getInt('database.port', 3306);
$debug = $config->getBool('app.debug', false);
$name  = $config->getString('app.name');

// Dot-notation access
$dbHost = $config->get('database.host', 'localhost');

// Required values (throws if missing)
$secret = $config->getRequired('app.secret');
```

## ⚡ Zero-Overhead Mode (OPcache)

In production, use the `CompiledPhpCache` to export your configuration to a static PHP file. This allows OPcache to store the configuration in shared memory.

```php
use MonkeysLegion\Mlc\Cache\CompiledPhpCache;

$cache  = new CompiledPhpCache('/var/cache/mlc');
$loader = new Loader($parser, $baseDir, cache: $cache);

// Warm-up cache (run during deployment)
$loader->compile(['app', 'database']);

// Future loads are now instant (bytecode read)
$config = $loader->load(['app', 'database']);
```

## 🔄 Dual-Layer Overrides

Apply non-destructive runtime overrides without touching the compiled base. Perfect for feature flags or multi-tenancy.

```php
$config->override('app.debug', true);
$config->get('app.debug'); // true

// Export base ONLY (overrides excluded)
$baseData = $config->all();

// Flatten base + overrides into a fresh isolated instance
$isolated = $config->snapshot();
```

## 🪝 Component Extensions

The `Loader` emits lifecycle events that you can hook into for logging or metrics.

```php
$loader->onLoading(fn($names) => logger()->info("Loading configs: " . implode(',', $names)));
$loader->onLoaded(fn($config) => logger()->info("Config ready"));
```

## 📂 Multi-Format Support

Use the `CompositeParser` to mix and match different configuration formats.

```php
use MonkeysLegion\Mlc\Parsers\CompositeParser;
use MonkeysLegion\Mlc\Parsers\JsonParser;
use MonkeysLegion\Mlc\Parsers\YamlParser;

$composite = new CompositeParser($mlcParser);
$composite->registerParser('json', new JsonParser());
$composite->registerParser('yaml', new YamlParser());

$loader = new Loader($composite, $baseDir);
// Automatically selects parser based on file extension (.mlc, .json, .yaml)
```

## 📝 MLC Syntax at a Glance

MLC provides a developer-friendly syntax that combines the best of INI, JSON, and PHP.

```mlc
# This is a comment
app_name = "MonkeysCloud"
debug    true
port     8080

# Sections (Nesting)
database {
    host = localhost
    
    # Environment expansion with fallback
    pass = ${DB_PASSWORD:-secret}
    
    # PHP-style arrays (Single or Double quotes)
    users = ['admin', 'manager', "guest"]
}

# Recursively include other files
@include "env/local.mlc"
```

> [!TIP]
> Visit [SYNTAX.md](SYNTAX.md) for the full language specification.

## 🛡️ Security Features

- **Path Traversal Prevention**: Strict validation of all relative paths.
- **Permission Auditing**: In-depth check for world-writable files in production.
- **Strict Mode**: `strictSecurity: true` throws exceptions instead of warnings for insecure files.
- **Reference Tracking**: Prevents circular key references and infinite inclusion loops.

## 🛠️ CLI Tool (`mlc-check`)

Validate your configuration files for syntax, security, and integrity from the terminal.

```bash
php bin/mlc-check ./config
```

## 📚 Documentation

- [MLC Syntax Reference](SYNTAX.md)
- [Full Developer Documentation](documentation.md)
- [Multi-Format Support Guide](multi_format_support.md)
- [Upgrading to v3.0.0](UPGRADE.md)

## 🧪 Testing

```bash
composer test    # Run PHPUnit suite
composer stan    # Run static analysis (Level 9)
composer ci      # Run full quality pipeline
```

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
