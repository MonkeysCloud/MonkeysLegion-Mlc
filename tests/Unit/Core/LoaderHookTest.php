<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Enums\LoaderHook;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Contracts\ConfigValidatorInterface;
use MonkeysLegion\Mlc\Config;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LoaderHookTest extends TestCase
{
    private string $baseDir;
    private Loader $loader;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_hook_test_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
        $parser = new MlcParser($bootstrapper, $this->baseDir);
        $this->loader = new Loader($parser, $this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    #[Test]
    public function test_loading_hooks_should_trigger_via_enum(): void
    {
        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');
        
        $loadingTriggered = false;
        $loadedTriggered = false;
        
        $this->loader->on(LoaderHook::Loading, function(array $names) use (&$loadingTriggered) {
            $this->assertEquals(['app'], $names);
            $loadingTriggered = true;
        });
        
        $this->loader->on(LoaderHook::Loaded, function(Config $config) use (&$loadedTriggered) {
            $this->assertEquals('val', $config->get('key'));
            $loadedTriggered = true;
        });
        
        $this->loader->load(['app']);
        
        $this->assertTrue($loadingTriggered);
        $this->assertTrue($loadedTriggered);
    }

    #[Test]
    public function test_loading_hooks_should_trigger_via_proxies(): void
    {
        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');
        
        $loadingTriggered = false;
        $loadedTriggered = false;
        
        $this->loader->onLoading(function(array $names) use (&$loadingTriggered) {
            $this->assertEquals(['app'], $names);
            $loadingTriggered = true;
        });
        
        $this->loader->onLoaded(function(Config $config) use (&$loadedTriggered) {
            $this->assertEquals('val', $config->get('key'));
            $loadedTriggered = true;
        });
        
        $this->loader->load(['app']);
        
        $this->assertTrue($loadingTriggered);
        $this->assertTrue($loadedTriggered);
    }

    #[Test]
    public function test_validation_error_hook_should_trigger(): void
    {
        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');
        
        $validator = $this->createMock(ConfigValidatorInterface::class);
        $validator->method('validate')->willReturn(['Field X is missing']);
        $this->loader->setValidator($validator);
        
        $errorTriggered = false;
        $this->loader->onValidationError(function(array $errors, array $data) use (&$errorTriggered) {
            $this->assertEquals(['Field X is missing'], $errors);
            $this->assertEquals('val', $data['key']);
            $errorTriggered = true;
        });
        
        try {
            $this->loader->load(['app']);
            $this->fail("Should have thrown LoaderException");
        } catch (\Throwable $e) {
            $this->assertStringContainsString('validation failed', $e->getMessage());
        }
        
        $this->assertTrue($errorTriggered);
    }

    #[Test]
    public function test_hooks_trigger_on_cache_hits(): void
    {
        $cache = new StubArrayCache();
        $this->loader = new Loader(new MlcParser(new EnvManager(new DotenvLoader(), new NativeEnvRepository()), $this->baseDir), $this->baseDir, cache: $cache);
        
        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');
        
        $loadingCount = 0;
        $loadedCount = 0;
        
        $this->loader->onLoading(function() use (&$loadingCount) {
            $loadingCount++;
        });
        
        $this->loader->onLoaded(function() use (&$loadedCount) {
            $loadedCount++;
        });
        
        // 1. First load (fresh)
        $this->loader->load(['app']);
        $this->assertEquals(1, $loadingCount);
        $this->assertEquals(1, $loadedCount);
        
        // 2. Second load (cache hit)
        $this->loader->load(['app']);
        $this->assertEquals(2, $loadingCount);
        $this->assertEquals(2, $loadedCount);
    }
}
