<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Exception\ConfigException;
use MonkeysLegion\Mlc\Exception\FrozenConfigException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for the Config dual-layer engine.
 */
final class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'app' => [
                'name'    => 'Test App',
                'debug'   => true,
                'version' => '1.0.0',
            ],
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'testdb',
            ],
            'cache' => [
                'enabled' => false,
                'ttl'     => 3600,
            ],
        ]);
    }

    // =========================================================================
    // Read API
    // =========================================================================

    #[Test]
    public function test_get_should_return_value(): void
    {
        $this->assertSame('Test App', $this->config->get('app.name'));
        $this->assertSame('localhost', $this->config->get('database.host'));
    }

    #[Test]
    public function test_get_should_return_default_when_not_found(): void
    {
        $this->assertSame('default', $this->config->get('nonexistent', 'default'));
        $this->assertNull($this->config->get('nonexistent'));
    }

    #[Test]
    public function test_has_should_return_true_for_existing_path(): void
    {
        $this->assertTrue($this->config->has('app.name'));
        $this->assertTrue($this->config->has('database.port'));
    }

    #[Test]
    public function test_has_should_return_false_for_non_existing_path(): void
    {
        $this->assertFalse($this->config->has('nonexistent'));
        $this->assertFalse($this->config->has('app.nonexistent'));
    }

    #[Test]
    public function test_get_string_should_return_string(): void
    {
        $this->assertSame('Test App', $this->config->getString('app.name'));
    }

    #[Test]
    public function test_get_string_should_throw_on_non_string(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getString('app.debug'); // boolean
    }

    #[Test]
    public function test_get_int_should_return_integer(): void
    {
        $this->assertSame(3306, $this->config->getInt('database.port'));
    }

    #[Test]
    public function test_get_int_should_throw_on_non_integer(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getInt('app.name'); // string
    }

    #[Test]
    public function test_get_bool_should_return_boolean(): void
    {
        $this->assertTrue($this->config->getBool('app.debug'));
        $this->assertFalse($this->config->getBool('cache.enabled'));
    }

    #[Test]
    public function test_get_bool_should_throw_on_non_boolean(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getBool('app.name'); // string
    }

    #[Test]
    public function test_get_array_should_return_array(): void
    {
        $expected = [
            'name'    => 'Test App',
            'debug'   => true,
            'version' => '1.0.0',
        ];
        $this->assertSame($expected, $this->config->getArray('app'));
    }

    #[Test]
    public function test_get_required_should_return_value_when_exists(): void
    {
        $this->assertSame('Test App', $this->config->getRequired('app.name'));
    }

    #[Test]
    public function test_get_required_should_throw_when_not_exists(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Required config key 'nonexistent' not found");
        $this->config->getRequired('nonexistent');
    }

    #[Test]
    public function test_all_should_return_all_data(): void
    {
        $data = $this->config->all();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('app', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('cache', $data);
    }

    #[Test]
    public function test_get_float_should_return_float(): void
    {
        $this->assertSame(3306.0, $this->config->getFloat('database.port'));
    }

    #[Test]
    public function test_get_float_should_throw_on_non_float(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getFloat('app.name');
    }

    #[Test]
    public function test_get_string_should_return_default(): void
    {
        $this->assertSame('fallback', $this->config->getString('missing', 'fallback'));
    }

    #[Test]
    public function test_get_int_should_return_default(): void
    {
        $this->assertSame(999, $this->config->getInt('missing', 999));
    }

    #[Test]
    public function test_get_float_should_return_default(): void
    {
        $this->assertSame(1.23, $this->config->getFloat('missing', 1.23));
    }

    #[Test]
    public function test_get_bool_should_return_default(): void
    {
        $this->assertTrue($this->config->getBool('missing', true));
    }

    #[Test]
    public function test_get_array_should_return_default(): void
    {
        $this->assertSame(['fallback'], $this->config->getArray('missing', ['fallback']));
    }

    #[Test]
    public function test_get_array_should_throw_on_non_array(): void
    {
        $this->expectException(ConfigException::class);
        $this->config->getArray('app.name');
    }

    // =========================================================================
    // Utility / export
    // =========================================================================

    #[Test]
    public function test_merge_should_create_new_config(): void
    {
        $other = new Config([
            'app' => ['name' => 'Merged App'],
            'new' => ['key' => 'value'],
        ]);

        $merged = $this->config->merge($other);

        // Original unchanged
        $this->assertSame('Test App', $this->config->get('app.name'));

        // Merged has new values
        $this->assertSame('Merged App', $merged->get('app.name'));
        $this->assertSame('value', $merged->get('new.key'));
        $this->assertTrue($merged->get('app.debug'));
    }

    #[Test]
    public function test_subset_should_create_new_config_with_subset(): void
    {
        $subset = $this->config->subset('app');
        $this->assertSame('Test App', $subset->get('name'));
        $this->assertTrue($subset->get('debug'));
        $this->assertFalse($subset->has('database'));
    }

    #[Test]
    public function test_subset_should_return_empty_if_not_array(): void
    {
        $subset = $this->config->subset('app.name'); // string, not array
        $this->assertEmpty($subset->all());
    }

    #[Test]
    public function test_to_json_should_return_valid_json(): void
    {
        $json = $this->config->toJson();
        $this->assertJson($json);
        $this->assertSame($this->config->all(), json_decode($json, true));
    }

    #[Test]
    public function test_to_array_should_return_array(): void
    {
        $this->assertSame($this->config->all(), $this->config->toArray());
    }

    // =========================================================================
    // Dual-layer — default state
    // =========================================================================

    #[Test]
    public function test_fresh_config_has_no_locks_and_dormant_dual_layer(): void
    {
        $this->assertFalse($this->config->isLocked());
        $this->assertFalse($this->config->areOverridesLocked());
        $this->assertFalse($this->config->isDualLayerActive());
    }

    // =========================================================================
    // Dual-layer — override() auto-activation
    // =========================================================================

    #[Test]
    public function test_override_activates_dual_layer(): void
    {
        $this->assertFalse($this->config->isDualLayerActive());
        $this->config->override('app.name', 'Overridden');
        $this->assertTrue($this->config->isDualLayerActive());
    }

    #[Test]
    public function test_override_shadows_base_value(): void
    {
        $this->config->override('database.host', '10.0.0.1');
        $this->assertSame('10.0.0.1', $this->config->get('database.host'));
        // Sibling untouched
        $this->assertSame(3306, $this->config->get('database.port'));
    }

    #[Test]
    public function test_override_does_not_mutate_compiled_base(): void
    {
        $this->config->override('app.name', 'Runtime Name');
        // all() returns compiled base only
        $this->assertSame('Test App', $this->config->all()['app']['name']);
    }

    #[Test]
    public function test_override_adds_new_key_not_in_base(): void
    {
        $this->config->override('brand_new', 'injected');
        $this->assertSame('injected', $this->config->get('brand_new'));
        $this->assertTrue($this->config->has('brand_new'));
    }

    #[Test]
    public function test_has_sees_override_layer(): void
    {
        $this->config->override('runtime_key', true);
        $this->assertTrue($this->config->has('runtime_key'));
    }

    #[Test]
    public function test_get_overrides_returns_current_map(): void
    {
        $this->config->override('a', 1);
        $this->config->override('b', 2);
        $this->assertSame(['a' => 1, 'b' => 2], $this->config->getOverrides());
    }

    // =========================================================================
    // lock() — Lock 1: sealed, no overrides ever
    // =========================================================================

    #[Test]
    public function test_lock_sets_flag(): void
    {
        $this->config->lock();
        $this->assertTrue($this->config->isLocked());
    }

    #[Test]
    public function test_lock_blocks_override(): void
    {
        $this->config->lock();
        $this->expectException(FrozenConfigException::class);
        $this->expectExceptionMessageMatches('/sealed/');
        $this->config->override('app.name', 'x');
    }

    #[Test]
    public function test_lock_allows_get_and_snapshot(): void
    {
        $this->config->lock();
        $this->assertSame('Test App', $this->config->get('app.name'));
        $snap = $this->config->snapshot();
        $this->assertSame('Test App', $snap->get('app.name'));
    }

    #[Test]
    public function test_lock_is_fluent(): void
    {
        $result = $this->config->lock();
        $this->assertSame($this->config, $result);
    }

    // =========================================================================
    // lockOverrides() — Lock 2: override layer sealed
    // =========================================================================

    #[Test]
    public function test_lock_overrides_sets_flag(): void
    {
        $this->config->lockOverrides();
        $this->assertTrue($this->config->areOverridesLocked());
    }

    #[Test]
    public function test_lock_overrides_blocks_further_override(): void
    {
        $this->config->override('app.debug', false);
        $this->config->lockOverrides();

        $this->expectException(FrozenConfigException::class);
        $this->expectExceptionMessageMatches('/override layer is sealed/');
        $this->config->override('app.debug', true);
    }

    #[Test]
    public function test_lock_overrides_preserves_applied_overrides(): void
    {
        $this->config->override('app.name', 'Locked In');
        $this->config->lockOverrides();
        $this->assertSame('Locked In', $this->config->get('app.name'));
    }

    #[Test]
    public function test_lock_overrides_is_fluent(): void
    {
        $result = $this->config->lockOverrides();
        $this->assertSame($this->config, $result);
    }

    // =========================================================================
    // snapshot()
    // =========================================================================

    #[Test]
    public function test_snapshot_returns_new_instance(): void
    {
        $snap = $this->config->snapshot();
        $this->assertNotSame($this->config, $snap);
    }

    #[Test]
    public function test_snapshot_with_dormant_dual_layer_clones_base(): void
    {
        $snap = $this->config->snapshot();
        $this->assertSame($this->config->all(), $snap->all());
        $this->assertFalse($snap->isDualLayerActive());
    }

    #[Test]
    public function test_snapshot_bakes_overrides_into_base(): void
    {
        $this->config->override('database.host', 'replica');
        $snap = $this->config->snapshot();

        $this->assertSame('replica', $snap->get('database.host'));
        $this->assertSame(3306, $snap->get('database.port'));
        $this->assertFalse($snap->isDualLayerActive());
    }

    #[Test]
    public function test_snapshot_is_isolated_from_original(): void
    {
        $snap = $this->config->snapshot();
        $this->config->override('app.name', 'Changed');

        $this->assertSame('Test App', $snap->get('app.name'));
    }

    #[Test]
    public function test_snapshot_of_locked_config_is_unlocked(): void
    {
        $this->config->lock();
        $snap = $this->config->snapshot();

        $this->assertFalse($snap->isLocked());
        $snap->override('app.name', 'Free');
        $this->assertSame('Free', $snap->get('app.name'));
    }

    // =========================================================================
    // Lookup cache
    // =========================================================================

    #[Test]
    public function test_clear_cache_removes_cached_values(): void
    {
        $this->config->get('app.name');
        $stats = $this->config->getCacheStats();
        $this->assertGreaterThan(0, $stats['size']);

        $this->config->clearCache();
        $this->assertSame(0, $this->config->getCacheStats()['size']);
    }

    #[Test]
    public function test_override_busts_lookup_cache_for_path(): void
    {
        // Prime lookup cache
        $this->config->get('app.name');
        $this->assertContains('app.name', $this->config->getCacheStats()['keys']);

        // Override should evict the cached entry
        $this->config->override('app.name', 'New');
        $this->assertNotContains('app.name', $this->config->getCacheStats()['keys']);
        $this->assertSame('New', $this->config->get('app.name'));
    }
}
