# MonkeysLegion MLC - Configuration Parser

Production-ready `.mlc` configuration file parser and loader for PHP 8.4+.

## Features

- 🚀 **Production-Ready**: Built for high-traffic sites with caching and validation
- 🔒 **Secure**: Path traversal prevention, file permission checks
- ⚡ **Fast**: File-based caching with automatic invalidation
- 🎯 **Type-Safe**: Strong typing with helpful getters (`getString()`, `getInt()`, etc.)
- 🔧 **Flexible**: Support for multiple config formats and merge strategies
- 🛡️ **Validated**: Schema-based validation support
- 📝 **Well-Documented**: Comprehensive error messages with line numbers

## Installation

```bash
composer require monkeyscloud/monkeyslegion-mlc
```

## Basic Usage

### Loading Configuration

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;

$loader = new Loader(
    parser: new Parser(),
    baseDir: '/path/to/config'
);

// Load single file
$config = $loader->loadOne('app');

// Load and merge multiple files
$config = $loader->load(['app', 'database', 'cache']);
```

### Accessing Values

```php
// Dot-notation access
$dbHost = $config->get('database.host', 'localhost');

// Type-safe getters
$port = $config->getInt('database.port', 3306);
$debug = $config->getBool('app.debug', false);
$name = $config->getString('app.name');
$allowed = $config->getArray('cors.allowed_origins', []);

// Required values (throws if missing)
$secret = $config->getRequired('app.secret');

// Check existence
if ($config->has('redis.enabled')) {
    // ...
}

// Get all data
$all = $config->all();
```

## MLC Format

### Basic Syntax

```mlc
# Comments start with #

# Key-value pairs (both syntaxes work)
app_name = "My Application"
app_env  production

# Numbers
port = 8080
timeout = 30.5

# Booleans
debug = true
enabled false

# Arrays
allowed_ips = ["127.0.0.1", "192.168.1.1"]

# Multi-line arrays
allowed_methods = [
    "GET",
    "POST",
    "PUT",
    "DELETE"
]

# Sections
database {
    host = localhost
    port = 3306
    name = mydb
    
    # Nested sections
    credentials {
        username = root
        password = secret
    }
}

# Environment variables (via .env files)
secret_key = ${APP_SECRET}
```

## Advanced Features

### Caching

The MLC package integrates with [MonkeysLegion-Cache](https://github.com/MonkeysCloud/MonkeysLegion-Cache) for high-performance caching with multiple drivers.

```php
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;

// Setup cache (supports File, Redis, Memcached, Array drivers)
$cacheConfig = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/var/cache/mlc',
            'prefix' => 'mlc_',
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1,
            'prefix' => 'mlc_',
        ],
    ],
];

$cacheManager = new CacheManager($cacheConfig);
$cache = $cacheManager->store('file'); // PSR-16 CacheInterface

$loader = new Loader(
    parser: new Parser(),
    baseDir: '/path/to/config',
    cache: $cache
);

$config = $loader->load(['app', 'database']);

// Clear cache when needed
$loader->clearCache();

// Or use the cache manager directly
$cacheManager->clear();
```

**Benefits of MonkeysLegion-Cache integration:**
- PSR-16 compliant caching
- Multiple cache drivers (File, Redis, Memcached, Array)
- Cache tagging support
- Automatic cache invalidation
- Production-tested reliability

For more details on cache configuration and drivers, see the [MonkeysLegion-Cache documentation](https://github.com/MonkeysCloud/MonkeysLegion-Cache).

### Validation

```php
use MonkeysLegion\Mlc\Validator\SchemaValidator;

$schema = [
    'database' => [
        'type' => 'array',
        'required' => true,
        'children' => [
            'host' => ['type' => 'string', 'required' => true],
            'port' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 65535],
            'name' => ['type' => 'string', 'required' => true],
        ],
    ],
    'app' => [
        'type' => 'array',
        'children' => [
            'debug' => ['type' => 'bool', 'required' => true],
            'env' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['dev', 'staging', 'production'],
            ],
        ],
    ],
];

$validator = new SchemaValidator($schema);
$loader->setValidator($validator);

try {
    $config = $loader->load(['app', 'database']);
} catch (LoaderException $e) {
    // Validation failed
    echo $e->getMessage();
}
```

### Freezing Configuration

Prevent accidental modifications in production:

```php
$config = $loader->load(['app']);
$config->freeze();

// This will throw FrozenConfigException
$config->set('app.debug', true);
```

### Auto-Freeze

```php
// Automatically freeze all loaded configs
$loader->setAutoFreeze(true);

$config = $loader->load(['app']); // Already frozen
```

### Hot-Reload Detection (Development)

```php
$lastCheck = time();

// Later...
if ($loader->hasChanges(['app', 'database'], $lastCheck)) {
    $config = $loader->reload(['app', 'database']);
    $lastCheck = time();
}
```

### Configuration Subsets

```php
$config = $loader->load(['app']);

// Get only database config
$dbConfig = $config->subset('database');

// Access as if it were root
$host = $dbConfig->get('host'); // instead of 'database.host'
```

### Merging Configurations

```php
$baseConfig = $loader->load(['base']);
$envConfig = $loader->load(['production']);

$merged = $baseConfig->merge($envConfig);
```

### Export

```php
// To JSON
$json = $config->toJson();

// To array
$array = $config->toArray();
```

## Environment Variables

The loader automatically loads `.env` files in this order:

1. `.env`
2. `.env.local`
3. `.env.{APP_ENV}`
4. `.env.{APP_ENV}.local`

Where `APP_ENV` is determined from `$_SERVER['APP_ENV']` or `$_ENV['APP_ENV']`.

### Custom Environment Directory

```php
$loader = new Loader(
    parser: new Parser(),
    baseDir: '/path/to/config',
    envDir: '/path/to/env'  // Different directory for .env files
);
```

### Disable Auto-Load

```php
$loader = new Loader(
    parser: new Parser(),
    baseDir: '/path/to/config',
    autoLoadEnv: false
);

// Load manually when needed
$loader->loadEnvironment();
```

## Error Handling

The package provides detailed error messages:

```php
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Exception\SecurityException;

try {
    $config = $loader->load(['app']);
} catch (ParserException $e) {
    // Parse error with file and line number
    echo $e->getMessage();
    echo $e->getFile();
    echo $e->getLine();
} catch (SecurityException $e) {
    // Security issue (path traversal, permissions, etc.)
    echo $e->getMessage();
} catch (LoaderException $e) {
    // Loading error (file not found, validation failed, etc.)
    echo $e->getMessage();
}
```

## Security Features

- **Path Traversal Prevention**: Validates all file paths
- **File Permission Checks**: Warns about world-writable configs
- **File Size Limits**: Prevents DoS via large files (10MB default)
- **Depth Limits**: Prevents infinite nesting (50 levels default)
- **Immutability**: Frozen configs cannot be modified

## Performance

### Benchmarks

With caching enabled:
- **First load**: ~5ms for 5 config files
- **Cached load**: ~0.5ms (10x faster)
- **Memory**: ~50KB per config

### Best Practices

1. **Always use caching in production**
2. **Freeze configs after loading**
3. **Use type-safe getters** for better IDE support
4. **Validate configs** to catch errors early
5. **Cache configs at application bootstrap**

## Backwards Compatibility

This version is **100% backwards compatible** with 1.x:

```php
// Old code still works
$loader = new Loader(new Parser(), '/path/to/config');
$config = $loader->load(['app']);
$value = $config->get('key.path');

// New features are opt-in
$config->freeze();  // New
$config->getInt('port', 8080);  // New
```

## Migration from 1.x

No changes required! The new version is a drop-in replacement.

To use new features:

```php
// Add caching
$loader = new Loader(
    new Parser(),
    '/path/to/config',
    cache: new FileCache('/tmp/config-cache')
);

// Use type-safe getters
$port = $config->getInt('server.port', 8080);

// Freeze in production
if ($_ENV['APP_ENV'] === 'production') {
    $config->freeze();
}
```

## Testing

```bash
# Run tests
composer test

# Run static analysis
composer stan

# Fix code style
composer cs-fix

# Run all CI checks
composer ci
```

## License

MIT License - see LICENSE file for details.

## Support

- **Issues**: https://github.com/monkeyscloud/monkeyslegion-mlc/issues
- **Documentation**: https://monkeyslegion.com/docs/packages/mlc

## Changelog

### 2.0.0 (Production Release)

- ✨ Added caching support (File, Null)
- ✨ Added schema validation
- ✨ Added type-safe getters
- ✨ Added config freezing
- ✨ Added security checks
- ✨ Better error messages with line numbers
- ✨ Hot-reload detection
- ✨ Configuration subsets and merging
- 🔒 Path traversal prevention
- 🔒 File permission checks
- 🐛 Fixed edge cases in parser
- 📝 Comprehensive documentation
- ✅ 100% backwards compatible with 1.x

### 1.0.0

- Initial release
