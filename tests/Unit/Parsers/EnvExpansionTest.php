<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\ParserException;

class EnvExpansionTest extends TestCase
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
        $vars = [
            'MLC_TEST_VAR', 'MLC_TEST_BOOL_TRUE', 'MLC_TEST_BOOL_FALSE',
            'MLC_TEST_NULL', 'MLC_TEST_HOST', 'MLC_TEST_PORT',
            'EXPAND_ME', 'ENV_A', 'ENV_B', 'ENV_PART'
        ];

        foreach ($vars as $var) {
            $this->env->unset($var);
        }
    }

    #[Test]
    public function test_standalone_expansion_should_work(): void
    {
        $this->env->set('MLC_TEST_VAR', 'hello_world');

        $content = "key = \${MLC_TEST_VAR}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('hello_world', $data['key']);
    }

    #[Test]
    public function test_standalone_expansion_with_default_should_work(): void
    {
        // ML_NOT_SET is not in env
        $content = "key = \${ML_NOT_SET:-default_value}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('default_value', $data['key']);
    }

    #[Test]
    public function test_expansion_type_casting_should_work(): void
    {
        $this->env->set('MLC_TEST_BOOL_TRUE', 'true');
        $this->env->set('MLC_TEST_BOOL_FALSE', 'false');
        $this->env->set('MLC_TEST_NULL', 'null');

        $content = <<<MLC
        is_prod = \${MLC_TEST_BOOL_TRUE}
        is_debug = \${MLC_TEST_BOOL_FALSE}
        nothing = \${MLC_TEST_NULL}
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertTrue($data['is_prod']);
        $this->assertFalse($data['is_debug']);
        $this->assertNull($data['nothing']);
    }

    #[Test]
    public function test_inline_expansion_should_work(): void
    {
        $this->env->set('MLC_TEST_HOST', 'localhost');
        $this->env->set('MLC_TEST_PORT', '8080');

        $content = 'api_url = "http://${MLC_TEST_HOST}:${MLC_TEST_PORT}/v1"';
        $data = $this->parser->parseContent($content);

        $this->assertEquals('http://localhost:8080/v1', $data['api_url']);
    }

    #[Test]
    public function test_inline_expansion_with_default_should_work(): void
    {
        $this->env->set('MLC_TEST_HOST', 'example.com');

        $content = 'api_url = "http://${MLC_TEST_HOST}:${MLC_TEST_PORT:-9000}/api"';
        $data = $this->parser->parseContent($content);

        $this->assertEquals('http://example.com:9000/api', $data['api_url']);
    }

    #[Test]
    public function test_nested_inline_expansion_should_work(): void
    {
        $this->env->set('ENV_A', 'foo');
        $this->env->set('ENV_B', 'bar');

        $content = "combined = \${ENV_A}-\${ENV_B}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('foo-bar', $data['combined']);
    }

    #[Test]
    public function test_unquoted_inline_expansion_should_work(): void
    {
        $this->env->set('ENV_PART', 'world');
        $content = "greeting hello-\${ENV_PART}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('hello-world', $data['greeting']);
    }

    #[Test]
    public function test_recursive_expansion_should_be_handled(): void
    {
        // env() returns true/false literals that are then parsed by Parser.
        // If I have ${VAR} where VAR="true", Parser sees "true" (standalone) which is handled by Parser later or env() itself.

        $this->env->set('EXPAND_ME', 'true');
        $content = "val = \${EXPAND_ME}";
        $data = $this->parser->parseContent($content);
        $this->assertTrue($data['val']);
    }

    #[Test]
    public function test_edge_case_casting_should_work(): void
    {
        $this->env->set('MLC_TEST_TRUE_PAREN', 'true');
        $this->env->set('MLC_TEST_FALSE_PAREN', 'false');
        $this->env->set('MLC_TEST_NULL_PAREN', 'null');
        $this->env->set('MLC_TEST_EMPTY', '');

        $content = <<<MLC
        a = \${MLC_TEST_TRUE_PAREN}
        b = \${MLC_TEST_FALSE_PAREN}
        c = \${MLC_TEST_NULL_PAREN}
        d = \${MLC_TEST_EMPTY}
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertTrue($data['a']);
        $this->assertFalse($data['b']);
        $this->assertNull($data['c']);
        $this->assertTrue(empty($data['d']));
    }

    #[Test]
    public function test_env_source_priority_should_work(): void
    {
        $_ENV['SOURCE_TEST'] = 'env_value';
        $this->assertEquals('env_value', $this->env->get('SOURCE_TEST'));

        unset($_ENV['SOURCE_TEST']);
        $_SERVER['SOURCE_TEST'] = 'server_value';
        $this->assertEquals('server_value', $this->env->get('SOURCE_TEST'));

        unset($_SERVER['SOURCE_TEST']);
        $this->env->set('SOURCE_TEST', 'getenv_value');
        $this->assertEquals('getenv_value', $this->env->get('SOURCE_TEST'));

        $this->env->set('SOURCE_TEST', '');
    }
}
