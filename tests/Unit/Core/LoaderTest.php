<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Contracts\ConfigValidatorInterface;
use MonkeysLegion\Mlc\Contracts\CacheInterface;

class LoaderTest extends TestCase
{
    private string $baseDir;
    private MlcParser $parser;
    private Loader $loader;
    private NativeEnvRepository $env;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_loader_test_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $this->env = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $this->env);
        $this->parser = new MlcParser($bootstrapper, $this->baseDir);
        $this->loader = new Loader($this->parser, $this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
        $this->env->unset('APP_ENV');
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
    public function test_load_should_merge_multiple_files(): void
    {
        file_put_contents($this->baseDir . '/app.mlc', "name 'Original'\nversion 1");
        file_put_contents($this->baseDir . '/db.mlc', "host 'localhost'\nport 3306");
        file_put_contents($this->baseDir . '/override.mlc', "name 'Override'\ndebug true");

        $config = $this->loader->load(['app', 'db', 'override']);

        $this->assertEquals('Override', $config->get('name'));
        $this->assertEquals(1, $config->get('version'));
        $this->assertEquals('localhost', $config->get('host'));
        $this->assertTrue($config->get('debug'));
    }

    #[Test]
    public function test_load_should_fail_on_missing_file(): void
    {
        $this->expectException(LoaderException::class);
        $this->loader->load(['missing']);
    }

    #[Test]
    public function test_load_should_fail_on_empty_names(): void
    {
        $this->expectException(LoaderException::class);
        $this->loader->load([]);
    }

    #[Test]
    public function test_load_one_convenience_method(): void
    {
        file_put_contents($this->baseDir . '/app.mlc', "name 'App'");
        $config = $this->loader->loadOne('app');
        $this->assertEquals('App', $config->get('name'));
    }

    #[Test]
    public function test_reload_should_bypass_cache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        // Reload should never call cache->get
        $cache->expects($this->never())->method('get');
        
        file_put_contents($this->baseDir . '/app.mlc', "name 'App'");
        $loader->reload(['app']);
    }

    #[Test]
    public function test_validator_should_be_executed(): void
    {
        $validator = $this->createMock(ConfigValidatorInterface::class);
        $validator->expects($this->once())
            ->method('validate')
            ->willReturn(['Validation Error']);

        $this->loader->setValidator($validator);
        
        file_put_contents($this->baseDir . '/app.mlc', "name 'App'");
        
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage('Validation Error');
        
        $this->loader->load(['app']);
    }

    #[Test]
    public function test_cache_key_generation(): void
    {
        $reflection = new \ReflectionMethod(Loader::class, 'generateCacheKey');
        $k1 = $reflection->invoke($this->loader, ['app', 'db']);
        $k2 = $reflection->invoke($this->loader, ['db', 'app']);
        
        $this->assertNotEquals($k1, $k2);
        $this->assertEquals(36, strlen($k1)); // mlc_ + 32 chars md5
    }

    #[Test]
    public function test_has_changes_basics(): void
    {
        // No cache set -> always false
        $this->assertFalse($this->loader->hasChanges(['app']));

        $cache = $this->createMock(CacheInterface::class);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        // Cache missing -> has changes
        $cache->method('get')->willReturn(null);
        $this->assertTrue($loader->hasChanges(['app']));
    }

    #[Test]
    public function test_compile_should_bypass_get_and_call_set(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache);

        file_put_contents($this->baseDir . '/app.mlc', "name 'App'");

        // compile should:
        // 1. NOT call cache->get (bypass read)
        // 2. call cache->set (write result)
        $cache->expects($this->never())->method('get');
        $cache->expects($this->once())->method('set');

        $config = $loader->compile(['app']);
        $this->assertEquals('App', $config->get('name'));
    }

    #[Test]
    public function test_cache_invalidation_works_for_parsers_with_empty_parsed_files(): void
    {
        $stubCache = new StubArrayCache();

        // Use a parser that returns [] from getParsedFiles
        $plainParser = $this->createMock(\MonkeysLegion\Mlc\Contracts\ParserInterface::class);
        $plainParser->method('parseFile')->willReturn(['key' => 'val']);
        $plainParser->method('getParsedFiles')->willReturn([]);

        $loader = new Loader($plainParser, $this->baseDir, cache: $stubCache);

        file_put_contents($this->baseDir . '/app.mlc', 'key = val');

        // Load into cache
        $loader->load(['app']);

        // Cache should be valid
        $this->assertFalse($loader->hasChanges(['app']));

        // Verify the cache envelope contains the file
        $cached = $stubCache->all();
        $envelope = reset($cached);
        $this->assertContains(realpath($this->baseDir . '/app.mlc'), $envelope['files']);

        // Modify file and check changes
        sleep(1); // Ensure mtime change
        touch($this->baseDir . '/app.mlc');
        $this->assertTrue($loader->hasChanges(['app']));
    }
}
