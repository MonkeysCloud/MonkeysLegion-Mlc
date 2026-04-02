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

    public function test_get_should_return_value(): void
    {
        $this->assertSame('Test App', $this->config->get('app.name'));
        $this->assertSame('localhost', $this->config->get('database.host'));
    }

    public function test_get_should_return_default_when_not_found(): void
    {
        $this->assertSame('default', $this->config->get('nonexistent', 'default'));
        $this->assertNull($this->config->get('nonexistent'));
    }

    public function test_has_should_return_true_for_existing_path(): void
    {
        $this->assertTrue($this->config->has('app.name'));
        $this->assertTrue($this->config->has('database.port'));
    }

    public function test_has_should_return_false_for_non_existing_path(): void
    {
        $this->assertFalse($this->config->has('nonexistent'));
        $this->assertFalse($this->config->has('app.nonexistent'));
    }

    public function test_get_string_should_return_string(): void
    {
        $this->assertSame('Test App', $this->config->getString('app.name'));
    }

    public function test_get_string_should_throw_on_non_string(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getString('app.debug'); // This is a boolean
    }

    public function test_get_int_should_return_integer(): void
    {
        $this->assertSame(3306, $this->config->getInt('database.port'));
    }

    public function test_get_int_should_throw_on_non_integer(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getInt('app.name'); // This is a string
    }

    public function test_get_bool_should_return_boolean(): void
    {
        $this->assertTrue($this->config->getBool('app.debug'));
        $this->assertFalse($this->config->getBool('cache.enabled'));
    }

    public function test_get_bool_should_throw_on_non_boolean(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getBool('app.name'); // This is a string
    }

    public function test_get_array_should_return_array(): void
    {
        $expected = [
            'name' => 'Test App',
            'debug' => true,
            'version' => '1.0.0',
        ];
        
        $this->assertSame($expected, $this->config->getArray('app'));
    }

    public function test_get_required_should_return_value_when_exists(): void
    {
        $this->assertSame('Test App', $this->config->getRequired('app.name'));
    }

    public function test_get_required_should_throw_when_not_exists(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Required config key 'nonexistent' not found");
        $this->config->getRequired('nonexistent');
    }

    public function test_all_should_return_all_data(): void
    {
        $data = $this->config->all();
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('app', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('cache', $data);
    }

    public function test_freeze_should_prevent_setting(): void
    {
        $this->assertFalse($this->config->isFrozen());
        
        $this->config->freeze();
        
        $this->assertTrue($this->config->isFrozen());
        
        $this->expectException(FrozenConfigException::class);
        $this->config->set('app.name', 'New Name');
    }

    public function test_set_should_update_value(): void
    {
        $this->config->set('app.name', 'New Name');
        
        $this->assertSame('New Name', $this->config->get('app.name'));
    }

    public function test_set_should_create_nested_paths(): void
    {
        $this->config->set('new.nested.path', 'value');
        
        $this->assertTrue($this->config->has('new.nested.path'));
        $this->assertSame('value', $this->config->get('new.nested.path'));
    }

    public function test_merge_should_create_new_config(): void
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

    public function test_subset_should_create_new_config_with_subset(): void
    {
        $subset = $this->config->subset('app');

        $this->assertSame('Test App', $subset->get('name'));
        $this->assertTrue($subset->get('debug'));
        $this->assertFalse($subset->has('database'));
    }

    public function test_to_json_should_return_valid_json(): void
    {
        $json = $this->config->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertSame($this->config->all(), $decoded);
    }

    public function test_to_array_should_return_array(): void
    {
        $array = $this->config->toArray();
        
        $this->assertSame($this->config->all(), $array);
    }

    public function test_get_float_should_return_float(): void
    {
        $this->assertSame(3306.0, $this->config->getFloat('database.port'));
        
        $this->config->set('pi', 3.14);
        $this->assertSame(3.14, $this->config->getFloat('pi'));
    }

    public function test_get_float_should_throw_on_non_float(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getFloat('app.name');
    }

    public function test_get_string_should_return_default(): void
    {
        $this->config->set('explicit.null', null);
        $this->assertSame('fallback', $this->config->getString('missing', 'fallback'));
        $this->assertSame('fallback', $this->config->getString('explicit.null', 'fallback'));
    }

    public function test_get_int_should_return_default(): void
    {
        $this->config->set('explicit.null', null);
        $this->assertSame(999, $this->config->getInt('missing', 999));
        $this->assertSame(999, $this->config->getInt('explicit.null', 999));
    }

    public function test_get_float_should_return_default(): void
    {
        $this->config->set('explicit.null', null);
        $this->assertSame(1.23, $this->config->getFloat('missing', 1.23));
        $this->assertSame(1.23, $this->config->getFloat('explicit.null', 1.23));
    }

    public function test_get_bool_should_return_default(): void
    {
        $this->config->set('explicit.null', null);
        $this->assertTrue($this->config->getBool('missing', true));
        $this->assertFalse($this->config->getBool('explicit.null', false));
    }

    public function test_get_array_should_return_default(): void
    {
        $this->config->set('explicit.null', null);
        $this->assertSame(['fallback'], $this->config->getArray('missing', ['fallback']));
        $this->assertSame(['fallback'], $this->config->getArray('explicit.null', ['fallback']));
    }

    public function test_get_array_should_throw_on_non_array(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getArray('app.name');
    }

    public function test_subset_should_return_empty_if_not_array(): void
    {
        $subset = $this->config->subset('app.name'); // This is a string
        $this->assertEmpty($subset->all());
    }

    public function test_set_should_create_deeply_nested_arrays(): void
    {
        $this->config->set('very.deeply.nested.key', 'value');
        $this->assertEquals('value', $this->config->get('very.deeply.nested.key'));
        $this->assertIsArray($this->config->get('very.deeply.nested'));
    }

    public function test_clear_cache_for_path_should_clear_children(): void
    {
        // Populate cache
        $this->config->get('app.name');
        $this->config->get('app.debug');
        
        $stats = $this->config->getCacheStats();
        $this->assertContains('app.name', $stats['keys']);
        $this->assertContains('app.debug', $stats['keys']);

        // Set 'app' (parent) should clear 'app.name' and 'app.debug'
        $this->config->set('app', ['version' => '2.0']);
        
        $stats = $this->config->getCacheStats();
        $this->assertNotContains('app.name', $stats['keys']);
        $this->assertNotContains('app.debug', $stats['keys']);
    }

    public function test_clear_cache_should_remove_cached_values(): void
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
