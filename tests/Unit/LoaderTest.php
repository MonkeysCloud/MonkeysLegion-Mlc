<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Exception\LoaderException;
use MonkeysLegion\Mlc\Contracts\ConfigValidatorInterface;
use MonkeysLegion\Mlc\Contracts\CacheInterface;

class LoaderTest extends TestCase
{
    private string $baseDir;
    private Parser $parser;
    private Loader $loader;
    private NativeEnvRepository $env;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_loader_test_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $this->env = new NativeEnvRepository();
        $this->parser = new Parser($this->env);
        $this->loader = new Loader($this->parser, $this->baseDir, autoLoadEnv: false);
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

    public function test_load_should_throw_on_missing_file(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Config file not found");

        $this->loader->load(['non_existent']);
    }

    public function test_load_should_throw_on_no_files_specified(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("No config files specified");

        $this->loader->load([]);
    }

    public function test_load_should_use_cache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");
        $mtime = filemtime($this->baseDir . '/app.mlc');

        $cacheData = [
            'data' => ['key' => 'cached_val'],
            'files' => [$this->baseDir . '/app.mlc'],
            'mtimes' => [$mtime],
            'timestamp' => time()
        ];

        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cacheData);

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $config = $loader->load(['app']);

        $this->assertEquals('cached_val', $config->get('key'));
    }

    public function test_load_should_set_cache_when_empty(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");

        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set');

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $loader->load(['app']);
    }

    public function test_load_should_ignore_invalid_cache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");
        $mtime = filemtime($this->baseDir . '/app.mlc');

        // Cache reflects an old mtime
        $cacheData = [
            'data' => ['key' => 'cached_val'],
            'files' => [$this->baseDir . '/app.mlc'],
            'mtimes' => [$mtime - 1], 
            'timestamp' => time()
        ];

        $cache->method('get')->willReturn($cacheData);
        $cache->expects($this->once())->method('set'); // Should overwrite

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $config = $loader->load(['app']);

        // Result should be from file, not cache
        $this->assertEquals('val', $config->get('key'));
    }

    public function test_validator_should_be_hit(): void
    {
        $validator = $this->createMock(ConfigValidatorInterface::class);
        $validator->expects($this->once())
            ->method('validate')
            ->willReturn(['Validation Error']);

        file_put_contents($this->baseDir . '/app.mlc', "key val");
        
        $this->loader->setValidator($validator);

        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Config validation failed");
        $this->expectExceptionMessage("Validation Error");

        $this->loader->load(['app']);
    }


    public function test_load_environment_should_load_correct_priority(): void
    {
        // We can't easily test Dotenv side effects on $_ENV in a clean way without isolation,
        // but we can test resolveAppEnv which is private by using Reflection or 
        // by checking if it attempts to load the right files.
        
        file_put_contents($this->baseDir . '/.env', "APP_ENV=prod\nKEY1=val1");
        file_put_contents($this->baseDir . '/.env.local', "APP_ENV=dev\nKEY2=val2");

        // We run a fresh loader that autoLoads
        $loader = new Loader($this->parser, $this->baseDir, autoLoadEnv: true);
        
        // This should have loaded .env and .env.local
        $this->assertEquals('dev', $this->env->get('APP_ENV'));
    }

    public function test_resolve_app_env_from_server_should_work(): void
    {
        $_SERVER['APP_ENV'] = 'testing';
        
        // Use reflection to test private resolveAppEnv
        $reflection = new \ReflectionClass(Loader::class);
        $method = $reflection->getMethod('resolveAppEnv');
        
        $res = $method->invoke($this->loader, $this->baseDir);
        $this->assertEquals('testing', $res);

        unset($_SERVER['APP_ENV']);
    }

    public function test_resolve_app_env_from_env_file_should_work(): void
    {
        file_put_contents($this->baseDir . '/.env', "APP_ENV=\"staging\"");
        
        $reflection = new \ReflectionClass(Loader::class);
        $method = $reflection->getMethod('resolveAppEnv');
        
        $res = $method->invoke($this->loader, $this->baseDir);
        $this->assertEquals('staging', $res);
    }

    public function test_has_changes_should_detect_file_modifications(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        file_put_contents($this->baseDir . '/app.mlc', "key val");
        $mtime = filemtime($this->baseDir . '/app.mlc');

        $cacheData = [
            'files' => [$this->baseDir . '/app.mlc'],
            'mtimes' => [$mtime],
        ];

        $cache->method('get')->willReturn($cacheData);
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);

        // No changes yet
        $this->assertFalse($loader->hasChanges(['app']));

        // Modify file
        touch($this->baseDir . '/app.mlc', $mtime + 10);
        $this->assertTrue($loader->hasChanges(['app']));
    }

    public function test_clear_cache_should_call_cache_clear(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clear');
        
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $loader->clearCache();
    }

    public function test_clear_cache_should_throw_on_error(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('clear')->willThrowException(new \Exception("Cache Failure"));
        
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Failed to clear cache");
        
        $loader->clearCache();
    }

    public function test_invalid_base_dir_should_throw(): void
    {
        $this->expectException(LoaderException::class);
        new Loader($this->parser, '/non/existent/dir');
    }

    public function test_reload_should_ignore_cache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $loader->reload(['app']);
    }
    public function test_load_should_continue_when_cache_read_fails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \Exception("Read Error"));
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $config = $loader->load(['app']);

        $this->assertEquals('val', $config->get('key'));
    }

    public function test_load_should_continue_when_cache_write_fails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willThrowException(new \Exception("Write Error"));
        
        file_put_contents($this->baseDir . '/app.mlc', "key val");

        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $config = $loader->load(['app']);

        $this->assertEquals('val', $config->get('key'));
    }

    public function test_load_environment_should_handle_missing_env_dir(): void
    {
        // envDir is provided but does not exist
        $loader = new Loader($this->parser, $this->baseDir, envDir: '/tmp/non_existent_env_dir', autoLoadEnv: true);
        $this->assertIsObject($loader);
    }

    public function test_resolve_app_env_with_empty_env_file_should_return_null(): void
    {
        file_put_contents($this->baseDir . '/.env', "# just a comment\nFOO=BAR");
        
        $reflection = new \ReflectionMethod(Loader::class, 'resolveAppEnv');
        
        $res = $reflection->invoke($this->loader, $this->baseDir);
        $this->assertNull($res);
    }

    public function test_is_cache_valid_with_corrupt_cached_data_should_return_false(): void
    {
        $reflection = new \ReflectionMethod(Loader::class, 'isCacheValid');
        
        // Missing 'mtimes' in cached data
        $res = $reflection->invoke($this->loader, ['app'], ['files' => []]);
        $this->assertFalse($res);
        
        // Mismatched file count
        $res = $reflection->invoke($this->loader, ['app', 'db'], ['files' => ['app'], 'mtimes' => [123]]);
        $this->assertFalse($res);
    }

    public function test_is_cache_valid_with_missing_file_should_return_false(): void
    {
        $reflection = new \ReflectionMethod(Loader::class, 'isCacheValid');
        
        $path = $this->baseDir . '/missing.mlc';
        $res = $reflection->invoke($this->loader, ['missing'], [
            'files' => [$path],
            'mtimes' => [123]
        ]);
        $this->assertFalse($res);
    }

    public function test_has_changes_returns_true_when_cache_fails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \Exception("Fail"));
        
        $loader = new Loader($this->parser, $this->baseDir, cache: $cache, autoLoadEnv: false);
        $this->assertTrue($loader->hasChanges(['app']));
    }

    public function test_has_changes_returns_false_when_no_cache_manager(): void
    {
        $loader = new Loader($this->parser, $this->baseDir, cache: null, autoLoadEnv: false);
        $this->assertFalse($loader->hasChanges(['app']));
    }
    public function test_load_should_throw_on_parse_failure(): void
    {
        // Parser is final, so we trigger a real failure with invalid syntax
        file_put_contents($this->baseDir . '/fail.mlc', "invalid-syntax-no-separator");
        
        $this->expectException(LoaderException::class);
        $this->expectExceptionMessage("Failed to load config 'fail'");
        
        $this->loader->load(['fail']);
    }

    public function test_unreadable_base_dir_should_throw(): void
    {
        if (get_current_user() === 'root') {
            $this->markTestSkipped('Cannot test unreadable dirs as root');
        }

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

    public function test_unreadable_config_file_should_throw(): void
    {
        if (get_current_user() === 'root') {
            $this->markTestSkipped('Cannot test unreadable files as root');
        }

        file_put_contents($this->baseDir . '/secret.mlc', "key val");
        chmod($this->baseDir . '/secret.mlc', 0000);

        try {
            $this->expectException(LoaderException::class);
            $this->expectExceptionMessage("Config file not readable");
            $this->loader->load(['secret']);
        } finally {
            chmod($this->baseDir . '/secret.mlc', 0644);
        }
    }
}
