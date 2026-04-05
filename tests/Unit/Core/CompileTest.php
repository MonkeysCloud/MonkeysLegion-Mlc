<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Cache\CompiledPhpCache;
use MonkeysLegion\Mlc\Exception\LoaderException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CompileTest extends TestCase
{
    private string $baseDir;
    private string $cacheDir;
    private MlcParser $parser;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_compile_src_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/mlc_compile_cache_' . uniqid();
        
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
        $files = glob($dir . '/{,.}*', GLOB_BRACE);
        foreach ($files as $file) {
            if (basename($file) !== '.' && basename($file) !== '..') {
                is_dir($file) ? $this->removeDirectory($file) : unlink($file);
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function test_compile_should_write_to_compiled_php_cache(): void
    {
        $cache = new CompiledPhpCache($this->cacheDir);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');

        // Compile it
        $config = $loader->compile(['app']);
        $this->assertEquals('val', $config->get('key'));

        // Verify file exists in cache directory
        $cachedFiles = glob($this->cacheDir . '/*.generated.php');
        $this->assertCount(1, $cachedFiles);
        
        // Verify content is a PHP array
        $data = include $cachedFiles[0];
        $this->assertEquals(['key' => 'val'], $data);
    }

    #[Test]
    public function test_compile_should_be_atomic_and_bypass_read(): void
    {
        $cache = $this->createMock(\MonkeysLegion\Mlc\Contracts\CacheInterface::class);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', 'foo = "bar"');

        // Should NOT call get() but MUST call set()
        $cache->expects($this->never())->method('get');
        $cache->expects($this->once())->method('set');

        $loader->compile(['app']);
    }

    #[Test]
    public function test_compile_with_standard_cache_stores_envelope(): void
    {
        $cache = new \MonkeysLegion\Mlc\Tests\Unit\Core\StubArrayCache();
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', 'x = 10');

        $loader->compile(['app']);
        
        // Check what's in the stub cache
        $keys = array_keys($cache->all());
        $this->assertCount(1, $keys);
        
        $cached = $cache->get($keys[0]);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('data', $cached);
        $this->assertEquals(['x' => 10], $cached['data']);
        $this->assertArrayHasKey('mtimes', $cached);
    }

    #[Test]
    public function test_load_serves_from_compiled_php_cache(): void
    {
        $cache = new CompiledPhpCache($this->cacheDir);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', 'key = "val"');

        // 1. First load (fresh parse + write)
        $loader->load(['app']);

        // 2. Mock the parser to ensure it is NOT called during the second load
        $mockParser = $this->createMock(\MonkeysLegion\Mlc\Contracts\ParserInterface::class);
        $mockParser->expects($this->never())->method('parseFile');
        
        $reflection = new \ReflectionClass(Loader::class);
        $parserProperty = $reflection->getProperty('parser');
        $parserProperty->setValue($loader, $mockParser);

        // 3. Second load (should hit CompiledPhpCache)
        $config = $loader->load(['app']);
        $this->assertEquals('val', $config->get('key'));
    }

    #[Test]
    public function test_compile_fails_if_parsing_fails(): void
    {
        $loader = new Loader($this->parser, $this->baseDir);
        
        // Correct syntax for section start, but unclosed
        file_put_contents($this->baseDir . '/fail.mlc', "section {\n  key = val");

        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage('Failed to load config');
        
        $loader->compile(['fail']);
    }
}
