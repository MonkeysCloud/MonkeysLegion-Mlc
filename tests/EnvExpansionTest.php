<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Mlc\Exception\ParserException;

class EnvExpansionTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    protected function tearDown(): void
    {
        // Clear environment variables after each test if we modified them
        putenv('MLC_TEST_VAR');
        putenv('MLC_TEST_BOOL_TRUE');
        putenv('MLC_TEST_BOOL_FALSE');
        putenv('MLC_TEST_NULL');
        putenv('MLC_TEST_HOST');
        putenv('MLC_TEST_PORT');
        putenv('EXPAND_ME');
        putenv('ENV_A');
        putenv('ENV_B');
        putenv('ENV_PART');
        
        $vars = [
            'MLC_TEST_VAR', 'MLC_TEST_BOOL_TRUE', 'MLC_TEST_BOOL_FALSE', 
            'MLC_TEST_NULL', 'MLC_TEST_HOST', 'MLC_TEST_PORT',
            'EXPAND_ME', 'ENV_A', 'ENV_B', 'ENV_PART'
        ];
        
        foreach ($vars as $var) {
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    public function test_standalone_expansion_should_work(): void
    {
        putenv('MLC_TEST_VAR=hello_world');
        
        $content = "key = \${MLC_TEST_VAR}";
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('hello_world', $data['key']);
    }

    public function test_standalone_expansion_with_default_should_work(): void
    {
        // ML_NOT_SET is not in env
        $content = "key = \${ML_NOT_SET:-default_value}";
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('default_value', $data['key']);
    }

    public function test_expansion_type_casting_should_work(): void
    {
        putenv('MLC_TEST_BOOL_TRUE=true');
        putenv('MLC_TEST_BOOL_FALSE=false');
        putenv('MLC_TEST_NULL=null');

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

    public function test_inline_expansion_should_work(): void
    {
        putenv('MLC_TEST_HOST=localhost');
        putenv('MLC_TEST_PORT=8080');

        $content = 'api_url = "http://${MLC_TEST_HOST}:${MLC_TEST_PORT}/v1"';
        $data = $this->parser->parseContent($content);

        $this->assertEquals('http://localhost:8080/v1', $data['api_url']);
    }

    public function test_inline_expansion_with_default_should_work(): void
    {
        putenv('MLC_TEST_HOST=example.com');
        // PORT not set

        $content = 'api_url = "http://${MLC_TEST_HOST}:${MLC_TEST_PORT:-9000}/api"';
        $data = $this->parser->parseContent($content);

        $this->assertEquals('http://example.com:9000/api', $data['api_url']);
    }

    public function test_nested_inline_expansion_should_work(): void
    {
        putenv('ENV_A=foo');
        putenv('ENV_B=bar');

        $content = "combined = \${ENV_A}-\${ENV_B}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('foo-bar', $data['combined']);
    }

    public function test_unquoted_inline_expansion_should_work(): void
    {
        putenv('ENV_PART=world');
        $content = "greeting hello-\${ENV_PART}";
        $data = $this->parser->parseContent($content);

        $this->assertEquals('hello-world', $data['greeting']);
    }

    public function test_recursive_expansion_should_be_handled(): void
    {
        // env() returns true/false literals that are then parsed by Parser.
        // If I have ${VAR} where VAR="true", Parser sees "true" (standalone) which is handled by Parser later or env() itself.
        
        putenv('EXPAND_ME=true');
        $content = "val = \${EXPAND_ME}";
        $data = $this->parser->parseContent($content);
        $this->assertTrue($data['val']);
    }

    public function test_edge_case_casting_should_work(): void
    {
        putenv('MLC_TEST_TRUE_PAREN=(true)');
        putenv('MLC_TEST_FALSE_PAREN=(false)');
        putenv('MLC_TEST_NULL_PAREN=(null)');
        putenv('MLC_TEST_EMPTY=empty');
        putenv('MLC_TEST_EMPTY_PAREN=(empty)');

        $content = <<<MLC
        a = \${MLC_TEST_TRUE_PAREN}
        b = \${MLC_TEST_FALSE_PAREN}
        c = \${MLC_TEST_NULL_PAREN}
        d = \${MLC_TEST_EMPTY}
        e = \${MLC_TEST_EMPTY_PAREN}
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertTrue($data['a']);
        $this->assertFalse($data['b']);
        $this->assertNull($data['c']);
        $this->assertSame('', $data['d']);
        $this->assertSame('', $data['e']);
    }

    public function test_env_source_priority_should_work(): void
    {
        $_ENV['SOURCE_TEST'] = 'env_value';
        $this->assertEquals('env_value', env('SOURCE_TEST'));

        unset($_ENV['SOURCE_TEST']);
        $_SERVER['SOURCE_TEST'] = 'server_value';
        $this->assertEquals('server_value', env('SOURCE_TEST'));

        unset($_SERVER['SOURCE_TEST']);
        putenv('SOURCE_TEST=getenv_value');
        $this->assertEquals('getenv_value', env('SOURCE_TEST'));

        putenv('SOURCE_TEST');
    }
}
