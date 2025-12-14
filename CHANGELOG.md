# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-12-14

### Added - Production Features

#### Caching System
- Integration with **MonkeysLegion-Cache** package for PSR-16 compliant caching
- Support for multiple cache drivers (File, Redis, Memcached, Array)
- Automatic cache invalidation based on file modification times
- Cache tagging support via MonkeysLegion-Cache
- Atomic operations and cache statistics
- Production-tested reliability

#### Validation System
- `ConfigValidator` interface for custom validators
- `SchemaValidator` for schema-based validation
- Support for required fields, type checking, enums, patterns
- Min/max validation for numeric values
- Custom validator callbacks
- Nested validation support

#### Enhanced Config Object
- Type-safe getters: `getString()`, `getInt()`, `getFloat()`, `getBool()`, `getArray()`
- `getRequired()` for mandatory configuration values
- `freeze()` to make configuration immutable
- `merge()` to combine configurations
- `subset()` to extract configuration sections
- `toJson()` and `toArray()` for export
- Internal caching for improved performance
- Cache statistics with `getCacheStats()`

#### Security Enhancements
- Path traversal prevention in file paths
- File permission checks with warnings
- File size limits (10MB default, configurable)
- Maximum nesting depth limits (50 levels)
- Validation of file accessibility
- Security warnings for world-writable files

#### Enhanced Parser
- Detailed error messages with file names and line numbers
- Better syntax error reporting
- Support for `null` values
- Support for JSON objects `{}`
- Duplicate key detection with warnings
- Unclosed section/array detection
- Empty value validation
- Multi-line array improvements

#### Enhanced Loader
- Hot-reload detection with `hasChanges()`
- `reload()` method to force reload
- `loadOne()` convenience method
- `setValidator()` for validation
- `setAutoFreeze()` for automatic config freezing
- Configurable environment auto-loading
- Separate environment directory support
- Better error messages

#### Exception System
- `MlcException` base exception
- `ParserException` with line/file information
- `LoaderException` for loading errors
- `ConfigException` for configuration errors
- `SecurityException` for security issues
- `FrozenConfigException` for frozen config modifications

### Changed - Improvements

- Enhanced composer.json with dev dependencies
- Improved PHPDoc documentation throughout
- Better type hints and return types
- More comprehensive error handling
- Performance optimizations in hot paths
- Reduced memory usage for large configs

### Backwards Compatibility

- ✅ 100% backwards compatible with 1.x
- All existing code continues to work unchanged
- New features are opt-in
- Default behavior matches 1.x

## [1.0.0] - 2025-01-01

### Initial Release

- Basic MLC parser
- Loader with multiple file support
- Environment variable loading via Dotenv
- Dot-notation configuration access
- Support for sections, arrays, booleans, numbers
- Both `key = value` and `key value` syntax
- Multi-line array support
- Comment support

## Upgrade Guide

### From 1.x to 2.x

No breaking changes! Your existing code will work as-is.

#### To Use New Features:

```php
// Before (still works)
$loader = new Loader(new Parser(), '/path/to/config');
$config = $loader->load(['app']);
$value = $config->get('key');

// After (with new features)
use MonkeysLegion\Cache\CacheManager;

// Setup cache
$cacheConfig = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/var/cache/mlc',
            'prefix' => 'mlc_',
        ],
    ],
];

$cacheManager = new CacheManager($cacheConfig);
$cache = $cacheManager->store('file');

$loader = new Loader(
    new Parser(),
    '/path/to/config',
    cache: $cache
);

$config = $loader->load(['app']);
$config->freeze(); // Prevent modifications

// Type-safe access
$port = $config->getInt('server.port', 8080);
$debug = $config->getBool('app.debug', false);
```

## Security

If you discover a security vulnerability, please email security@monkeyslegion.dev.

## Credits

- Created by MonkeysLegion Team
- Built with ❤️ for MonkeysCloud
