<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\LoaderException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LoaderCoverageExtTest extends TestCase
{
    private string $baseDir;
    private MlcParser $parser;
    private Loader $loader;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_loader_cov_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
        $this->parser = new MlcParser($bootstrapper, $this->baseDir);
        $this->loader = new Loader($this->parser, $this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            $files = glob($path . '/{,.}*', GLOB_BRACE);
            foreach ($files as $file) {
                if (basename($file) !== '.' && basename($file) !== '..') {
                    is_dir($file) ? $this->removeDirectory($file) : unlink($file);
                }
            }
            rmdir($path);
        }
    }

    #[Test]
    public function test_resolve_config_path_extension_matching_edge(): void
    {
        file_put_contents($this->baseDir . '/config.yaml', "key: val");
        
        $reflection = new \ReflectionMethod(Loader::class, 'resolveConfigPath');
        
        // Pass name with extension, existing
        $res = $reflection->invoke($this->loader, 'config.yaml');
        $this->assertStringContainsString('config.yaml', $res);

        // Pass name WITHOUT extension, find yaml
        $res = $reflection->invoke($this->loader, 'config');
        $this->assertStringContainsString('config.yaml', $res);
        
        // Fallback: name contains dots but extension not in whitelist
        file_put_contents($this->baseDir . '/my.data.mlc', "key val");
        $res = $reflection->invoke($this->loader, 'my.data');
        $this->assertStringContainsString('my.data.mlc', $res);
    }

    #[Test]
    public function test_resolve_config_path_not_found_throws(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Config file not found");
        
        $reflection = new \ReflectionMethod(Loader::class, 'resolveConfigPath');
        $reflection->invoke($this->loader, 'completely_missing');
    }

    #[Test]
    public function test_clear_cache_with_null_cache(): void
    {
        $loader = new Loader($this->parser, $this->baseDir, cache: null);
        $loader->clearCache(); // should just return silently
        $this->assertTrue(true);
    }

    #[Test]
    public function test_is_cache_valid_file_not_existing_returns_false(): void
    {
        $reflection = new \ReflectionMethod(Loader::class, 'isCacheValid');
        $res = $reflection->invoke($this->loader, ['app'], [
            'files' => [$this->baseDir . '/ghost.mlc'],
            'mtimes' => [123]
        ]);
        $this->assertFalse($res);
    }

    #[Test]
    public function test_clear_cache_throws_on_failure(): void
    {
        $cache = $this->createMock(\MonkeysLegion\Mlc\Contracts\CacheInterface::class);
        $cache->method('clear')->willThrowException(new \Exception("Wipe Fail"));
        
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);
        
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Failed to clear cache");
        $loader->clearCache();
    }

    #[Test]
    public function test_is_cache_valid_type_defensive_checks(): void
    {
        $reflection = new \ReflectionMethod(Loader::class, 'isCacheValid');
        
        // files not array
        $this->assertFalse($reflection->invoke($this->loader, [], ['files' => 'not_arr', 'mtimes' => []]));
        
        // member not string
        $this->assertFalse($reflection->invoke($this->loader, [], ['files' => [new \stdClass()], 'mtimes' => [123]]));
    }

    #[Test]
    public function test_construct_throws_on_missing_dir(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Config directory not found");
        new Loader($this->parser, '/tmp/ghost_dir_' . uniqid());
    }

    #[Test]
    public function test_construct_throws_on_unreadable_dir(): void
    {
        $dir = $this->baseDir . '/unreadable';
        mkdir($dir, 0000);
        
        try {
            $this->expectException(LoaderException::class);
            $this->expectExceptionMessage("Config directory not readable");
            new Loader($this->parser, $dir);
        } finally {
            chmod($dir, 0777);
        }
    }
}
