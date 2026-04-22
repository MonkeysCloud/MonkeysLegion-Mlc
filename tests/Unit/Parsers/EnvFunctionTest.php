<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Parsers\MlcParser;

class EnvFunctionTest extends TestCase
{
    private MlcParser $parser;
    private NativeEnvRepository $env;

    protected function setUp(): void
    {
        $this->env = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $this->env);
        $this->parser = new MlcParser($bootstrapper, sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $vars = ['MLC_SECRET', 'MLC_DB_HOST', 'MLC_PORT', 'ML_HOST', 'ML_PORT', 'NON_EXISTENT', 'MISSING'];
        foreach ($vars as $var) {
            $this->env->unset($var);
        }
    }

    #[Test]
    public function test_env_standalone_without_quotes_should_work(): void
    {
        $this->env->set('MLC_SECRET', 'super-secret');
        
        $content = "password = env(MLC_SECRET)";
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('super-secret', $data['password']);
    }

    #[Test]
    public function test_env_standalone_with_double_quotes_should_work(): void
    {
        $this->env->set('MLC_SECRET', 'token123');
        
        $content = 'key = env("MLC_SECRET")';
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('token123', $data['key']);
    }

    #[Test]
    public function test_env_standalone_with_single_quotes_should_work(): void
    {
        $this->env->set('MLC_SECRET', 'val');
        
        $content = "key = env('MLC_SECRET')";
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('val', $data['key']);
    }

    #[Test]
    public function test_env_with_default_should_work(): void
    {
        // MLC_SECRET not set
        $content = 'key = env(MLC_SECRET, "default-val")';
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('default-val', $data['key']);
    }

    #[Test]
    public function test_env_with_unquoted_default_should_work(): void
    {
        $content = 'port = env(MLC_PORT, 8080)';
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals(8080, $data['port']);
    }

    #[Test]
    public function test_env_returning_null_should_work(): void
    {
        $content = 'val = env(NON_EXISTENT)';
        $data = $this->parser->parseContent($content);
        
        $this->assertNull($data['val']);
    }

    #[Test]
    public function test_env_mixed_expansion_should_work(): void
    {
        $this->env->set('MLC_DB_HOST', '127.0.0.1');
        
        $content = 'connection = "mysql://env(MLC_DB_HOST):3306"';
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('mysql://127.0.0.1:3306', $data['connection']);
    }

    #[Test]
    public function test_env_mixed_expansion_with_default_should_work(): void
    {
        $content = 'url = "http://env(ML_HOST, localhost):env(ML_PORT, 9000)"';
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('http://localhost:9000', $data['url']);
    }

    #[Test]
    public function test_env_with_quotes_in_default_should_work(): void
    {
        $content = "msg = env(MISSING, 'hello, world')";
        $data = $this->parser->parseContent($content);
        $this->assertEquals('hello, world', $data['msg']);
    }
}
