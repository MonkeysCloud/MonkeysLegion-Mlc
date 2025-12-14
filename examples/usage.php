<?php

/**
 * MonkeysLegion MLC - Complete Usage Examples
 * 
 * This file demonstrates all features of the MLC package v2.0
 * with MonkeysLegion-Cache integration
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Mlc\Validator\SchemaValidator;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Exception\ConfigException;

// ============================================================================
// EXAMPLE 1: Basic Usage (Backwards Compatible with 1.x)
// ============================================================================

echo "=== Example 1: Basic Usage ===\n\n";

$loader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config'
);

$config = $loader->load(['app', 'database']);

// Access values
echo "App Name: " . $config->get('app.name', 'Default App') . "\n";
echo "DB Host: " . $config->get('database.host', 'localhost') . "\n\n";

// ============================================================================
// EXAMPLE 2: Type-Safe Access
// ============================================================================

echo "=== Example 2: Type-Safe Access ===\n\n";

try {
    // Type-safe getters
    $port = $config->getInt('database.port', 3306);
    echo "Port (int): {$port}\n";
    
    $debug = $config->getBool('app.debug', false);
    echo "Debug (bool): " . ($debug ? 'true' : 'false') . "\n";
    
    $name = $config->getString('app.name');
    echo "Name (string): {$name}\n";
    
    $features = $config->getArray('app.features', []);
    echo "Features: " . json_encode($features) . "\n\n";
    
} catch (ConfigException $e) {
    echo "Type error: {$e->getMessage()}\n\n";
}

// ============================================================================
// EXAMPLE 3: With Caching (MonkeysLegion-Cache)
// ============================================================================

echo "=== Example 3: With Caching ===\n\n";

// Setup cache manager with File driver
$cacheConfig = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/tmp/mlc-cache',
            'prefix' => 'mlc_',
        ],
    ],
];

$cacheManager = new CacheManager($cacheConfig);
$cache = $cacheManager->store('file');

$cachedLoader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config',
    cache: $cache
);

// First load - will parse files
$start = microtime(true);
$config1 = $cachedLoader->load(['app', 'database']);
$time1 = (microtime(true) - $start) * 1000;

// Second load - from cache
$start = microtime(true);
$config2 = $cachedLoader->load(['app', 'database']);
$time2 = (microtime(true) - $start) * 1000;

echo "First load (parse): " . number_format($time1, 2) . " ms\n";
echo "Second load (cache): " . number_format($time2, 2) . " ms\n";
echo "Speedup: " . number_format($time1 / $time2, 1) . "x\n\n";

// ============================================================================
// EXAMPLE 4: Redis Cache (Production Setup)
// ============================================================================

echo "=== Example 4: Redis Cache (Production) ===\n\n";

// Redis cache configuration
$redisCacheConfig = [
    'default' => 'redis',
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1,
            'prefix' => 'mlc_',
        ],
    ],
];

// Note: This requires Redis extension and running Redis server
// For this example, we'll stick with file cache
echo "Redis cache configured (requires Redis extension)\n";
echo "Config structure:\n";
print_r($redisCacheConfig);
echo "\n";

// ============================================================================
// EXAMPLE 5: Schema Validation
// ============================================================================

echo "=== Example 5: Schema Validation ===\n\n";

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

$validatedLoader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config'
);

$validatedLoader->setValidator($validator);

try {
    $validatedConfig = $validatedLoader->load(['app', 'database']);
    echo "✓ Config validated successfully\n\n";
} catch (LoaderException $e) {
    echo "✗ Validation failed: {$e->getMessage()}\n\n";
}

// ============================================================================
// EXAMPLE 6: Freezing Configuration
// ============================================================================

echo "=== Example 6: Freezing Configuration ===\n\n";

$config = $loader->loadOne('app');

// Freeze to prevent modifications
$config->freeze();
echo "Config frozen: " . ($config->isFrozen() ? 'Yes' : 'No') . "\n";

try {
    // This will throw an exception
    $config->set('app.name', 'Modified');
} catch (ConfigException $e) {
    echo "✓ Cannot modify frozen config: {$e->getMessage()}\n\n";
}

// ============================================================================
// EXAMPLE 7: Auto-Freeze
// ============================================================================

echo "=== Example 7: Auto-Freeze (Production Mode) ===\n\n";

$productionLoader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config'
);

$productionLoader->setAutoFreeze(true);

$frozenConfig = $productionLoader->loadOne('app');
echo "Auto-frozen: " . ($frozenConfig->isFrozen() ? 'Yes' : 'No') . "\n\n";

// ============================================================================
// EXAMPLE 8: Merging Configurations
// ============================================================================

echo "=== Example 8: Merging Configurations ===\n\n";

$baseConfig = $loader->loadOne('app');
$envConfig = $loader->loadOne('database');

$merged = $baseConfig->merge($envConfig);
echo "Merged keys: " . implode(', ', array_keys($merged->all())) . "\n\n";

// ============================================================================
// EXAMPLE 9: Config Subsets
// ============================================================================

echo "=== Example 9: Config Subsets ===\n\n";

$fullConfig = $loader->load(['app', 'database']);

// Extract just database config
$dbConfig = $fullConfig->subset('database');
echo "Database config keys: " . implode(', ', array_keys($dbConfig->all())) . "\n\n";

// ============================================================================
// EXAMPLE 10: Hot-Reload Detection
// ============================================================================

echo "=== Example 10: Hot-Reload Detection ===\n\n";

$loaderWithCache = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config',
    cache: $cache
);

// Load config
$config = $loaderWithCache->load(['app']);

// Check if files have changed
$hasChanges = $loaderWithCache->hasChanges(['app']);
echo "Config has changes: " . ($hasChanges ? 'Yes' : 'No') . "\n";

// Reload without cache
$reloaded = $loaderWithCache->reload(['app']);
echo "Config reloaded\n\n";

// ============================================================================
// EXAMPLE 11: Export Formats
// ============================================================================

echo "=== Example 11: Export Formats ===\n\n";

$config = $loader->loadOne('app');

// Export as JSON
$json = $config->toJson(JSON_PRETTY_PRINT);
echo "JSON Export:\n{$json}\n\n";

// Export as array
$array = $config->toArray();
echo "Array Export (keys): " . implode(', ', array_keys($array)) . "\n\n";

// ============================================================================
// EXAMPLE 12: Required Values
// ============================================================================

echo "=== Example 12: Required Values ===\n\n";

try {
    $secret = $config->getRequired('app.secret');
    echo "Secret found: {$secret}\n";
} catch (ConfigException $e) {
    echo "✗ Required value missing: {$e->getMessage()}\n";
}

try {
    $missing = $config->getRequired('app.nonexistent');
} catch (ConfigException $e) {
    echo "✓ Exception for missing required value\n\n";
}

// ============================================================================
// EXAMPLE 13: Error Handling
// ============================================================================

echo "=== Example 13: Error Handling ===\n\n";

try {
    $badLoader = new Loader(
        parser: new Parser(),
        baseDir: '/nonexistent/path'
    );
} catch (LoaderException $e) {
    echo "✓ Caught LoaderException: {$e->getMessage()}\n\n";
}

// ============================================================================
// EXAMPLE 14: Cache Management
// ============================================================================

echo "=== Example 14: Cache Management ===\n\n";

// Clear all MLC cache
$loaderWithCache->clearCache();
echo "✓ Cache cleared\n";

// Or use cache manager directly
$cacheManager->clear();
echo "✓ All cache cleared via CacheManager\n\n";

// ============================================================================
// EXAMPLE 15: Multiple Cache Stores
// ============================================================================

echo "=== Example 15: Multiple Cache Stores ===\n\n";

$multiCacheConfig = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/tmp/mlc-file-cache',
            'prefix' => 'mlc_file_',
        ],
        'array' => [
            'driver' => 'array',
            'prefix' => 'mlc_array_',
        ],
    ],
];

$multiCacheManager = new CacheManager($multiCacheConfig);

// Use file cache
$fileCache = $multiCacheManager->store('file');
$fileLoader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config',
    cache: $fileCache
);

echo "File cache loader created\n";

// Use array cache (in-memory, for testing)
$arrayCache = $multiCacheManager->store('array');
$arrayLoader = new Loader(
    parser: new Parser(),
    baseDir: __DIR__ . '/config',
    cache: $arrayCache
);

echo "Array cache loader created\n\n";

// ============================================================================
// EXAMPLE 16: Production Setup (Complete)
// ============================================================================

echo "=== Example 16: Production Setup (Complete) ===\n\n";

// Production-ready setup with all features
$productionCacheConfig = [
    'default' => 'redis',
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'database' => 1,
            'prefix' => 'mlc_prod_',
        ],
        'file' => [
            'driver' => 'file',
            'path' => '/var/cache/mlc',
            'prefix' => 'mlc_prod_',
        ],
    ],
];

// Note: Would use Redis in production, File for this example
$productionCache = (new CacheManager($productionCacheConfig))->store('file');

$productionLoader = new Loader(
    parser: new Parser(),
    baseDir: '/path/to/config',
    cache: $productionCache
);

$productionLoader->setValidator($validator);
$productionLoader->setAutoFreeze(true);

echo "Production loader configured with:\n";
echo "  ✓ Caching (MonkeysLegion-Cache)\n";
echo "  ✓ Validation\n";
echo "  ✓ Auto-freeze\n";
echo "  ✓ Ready for production!\n\n";

echo "=== All Examples Complete ===\n";
