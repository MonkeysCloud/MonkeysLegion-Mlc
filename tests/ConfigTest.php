<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests;

use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Exception\ConfigException;
use MonkeysLegion\Mlc\Exception\FrozenConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Example test case for Config class.
 * 
 * This demonstrates how to write tests for the MLC package.
 */
final class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'app' => [
                'name' => 'Test App',
                'debug' => true,
                'version' => '1.0.0',
            ],
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'testdb',
            ],
            'cache' => [
                'enabled' => false,
                'ttl' => 3600,
            ],
        ]);
    }

    public function testGetReturnsValue(): void
    {
        $this->assertSame('Test App', $this->config->get('app.name'));
        $this->assertSame('localhost', $this->config->get('database.host'));
    }

    public function testGetReturnsDefaultWhenNotFound(): void
    {
        $this->assertSame('default', $this->config->get('nonexistent', 'default'));
        $this->assertNull($this->config->get('nonexistent'));
    }

    public function testHasReturnsTrueForExistingPath(): void
    {
        $this->assertTrue($this->config->has('app.name'));
        $this->assertTrue($this->config->has('database.port'));
    }

    public function testHasReturnsFalseForNonExistingPath(): void
    {
        $this->assertFalse($this->config->has('nonexistent'));
        $this->assertFalse($this->config->has('app.nonexistent'));
    }

    public function testGetStringReturnsString(): void
    {
        $this->assertSame('Test App', $this->config->getString('app.name'));
    }

    public function testGetStringThrowsForNonString(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getString('app.debug'); // This is a boolean
    }

    public function testGetIntReturnsInteger(): void
    {
        $this->assertSame(3306, $this->config->getInt('database.port'));
    }

    public function testGetIntThrowsForNonInteger(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getInt('app.name'); // This is a string
    }

    public function testGetBoolReturnsBoolean(): void
    {
        $this->assertTrue($this->config->getBool('app.debug'));
        $this->assertFalse($this->config->getBool('cache.enabled'));
    }

    public function testGetBoolThrowsForNonBoolean(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getBool('app.name'); // This is a string
    }

    public function testGetArrayReturnsArray(): void
    {
        $expected = [
            'name' => 'Test App',
            'debug' => true,
            'version' => '1.0.0',
        ];
        
        $this->assertSame($expected, $this->config->getArray('app'));
    }

    public function testGetRequiredReturnsValueWhenExists(): void
    {
        $this->assertSame('Test App', $this->config->getRequired('app.name'));
    }

    public function testGetRequiredThrowsWhenNotExists(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Required config key 'nonexistent' not found");
        $this->config->getRequired('nonexistent');
    }

    public function testAllReturnsAllData(): void
    {
        $data = $this->config->all();
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('app', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('cache', $data);
    }

    public function testFreezePreventsSetting(): void
    {
        $this->assertFalse($this->config->isFrozen());
        
        $this->config->freeze();
        
        $this->assertTrue($this->config->isFrozen());
        
        $this->expectException(FrozenConfigException::class);
        $this->config->set('app.name', 'New Name');
    }

    public function testSetUpdatesValue(): void
    {
        $this->config->set('app.name', 'New Name');
        
        $this->assertSame('New Name', $this->config->get('app.name'));
    }

    public function testSetCreatesNestedPaths(): void
    {
        $this->config->set('new.nested.path', 'value');
        
        $this->assertTrue($this->config->has('new.nested.path'));
        $this->assertSame('value', $this->config->get('new.nested.path'));
    }

    public function testMergeCreatesNewConfig(): void
    {
        $other = new Config([
            'app' => [
                'name' => 'Merged App',
            ],
            'new' => [
                'key' => 'value',
            ],
        ]);

        $merged = $this->config->merge($other);

        // Original unchanged
        $this->assertSame('Test App', $this->config->get('app.name'));
        
        // Merged has new values
        $this->assertSame('Merged App', $merged->get('app.name'));
        $this->assertSame('value', $merged->get('new.key'));
        
        // Merged retains original values not in other
        $this->assertTrue($merged->get('app.debug'));
    }

    public function testSubsetCreatesNewConfigWithSubset(): void
    {
        $subset = $this->config->subset('app');

        $this->assertSame('Test App', $subset->get('name'));
        $this->assertTrue($subset->get('debug'));
        $this->assertFalse($subset->has('database'));
    }

    public function testToJsonReturnsValidJson(): void
    {
        $json = $this->config->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertSame($this->config->all(), $decoded);
    }

    public function testToArrayReturnsArray(): void
    {
        $array = $this->config->toArray();
        
        $this->assertSame($this->config->all(), $array);
    }

    public function testCachingImprovesPerformance(): void
    {
        // Warmup
        $this->config->get('app.name');
        $this->config->clearCache();

        $iterations = 10000;

        // First access - no cache (repeated clear)
        $start1 = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->config->clearCache();
            $this->config->get('app.name');
        }
        $time1 = microtime(true) - $start1;

        // Second access - cached
        // Populate cache first
        $this->config->get('app.name');
        
        $start2 = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->config->get('app.name');
        }
        $time2 = microtime(true) - $start2;

        // Cached access should be faster
        $this->assertLessThan($time1, $time2);
    }

    public function testClearCacheRemovesCachedValues(): void
    {
        // Access to populate cache
        $this->config->get('app.name');
        
        $stats = $this->config->getCacheStats();
        $this->assertGreaterThan(0, $stats['size']);

        $this->config->clearCache();

        $stats = $this->config->getCacheStats();
        $this->assertSame(0, $stats['size']);
    }
}
