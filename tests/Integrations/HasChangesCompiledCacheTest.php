<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Integrations;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Cache\CompiledPhpCache;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HasChangesCompiledCacheTest extends TestCase
{
    private string $baseDir;
    private string $cacheDir;
    private MlcParser $parser;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_has_changes_src_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/mlc_has_changes_cache_' . uniqid();
        
        mkdir($this->baseDir, 0777, true);
        mkdir($this->cacheDir, 0777, true);
        
        $bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
        $this->parser = new MlcParser($bootstrapper, $this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
        $this->removeDirectory($this->cacheDir);
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
    public function test_has_changes_incorrectly_reports_hot_reload_on_compiled_cache_hits(): void
    {
        $cache = new CompiledPhpCache($this->cacheDir);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');

        // 1. Initial load (write to bytecode cache)
        $loader->load(['app']);

        // 2. hasChanges() should be FALSE immediately because nothing changed.
        // BUG: In the current implementation, hasChanges() reads the raw array from CompiledPhpCache,
        // fails isCacheValid() because it's not an envelope, and returns TRUE.
        $this->assertFalse(
            $loader->hasChanges(['app']),
            "BUG: hasChanges() should be FALSE for an unchanged CompiledPhpCache entry, but it reported TRUE."
        );
    }
}
