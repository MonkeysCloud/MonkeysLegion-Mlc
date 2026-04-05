<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\ParserException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Additional tests to hit 100% coverage and reduce CRAP index for MlcParser.
 */
class MlcCoverageExtTest extends TestCase
{
    private MlcParser $parser;
    private NativeEnvRepository $env;

    protected function setUp(): void
    {
        $this->env = new NativeEnvRepository();
        $bootstrapper = new \MonkeysLegion\Env\EnvManager(
            new \MonkeysLegion\Env\Loaders\DotenvLoader(),
            $this->env
        );
        $this->parser = new MlcParser($bootstrapper, sys_get_temp_dir());
    }

    #[Test]
    public function test_resolve_node_mixed_expansion_with_complex_types(): void
    {
        $this->env->set('MY_ARRAY', '["a", "b"]'); // It's a string in env repo usually, but let's see
        
        // We want to hit the 'is_array' branch in resolveNode mixed expansion
        // To do that, we need a variable that resolves to an array.
        // References from rootData can be arrays.
        
        $content = <<<MLC
        base_arr = ["one", "two"]
        mixed = "Value is \${base_arr}"
        MLC;
        
        $data = $this->parser->parseContent($content);
        
        $this->assertEquals('Value is ["one","two"]', $data['mixed']);
    }

    #[Test]
    public function test_resolve_node_mixed_expansion_with_booleans_and_null(): void
    {
        $content = <<<MLC
        b_true = true
        b_false = false
        b_null = null
        mixed = "T:\${b_true}, F:\${b_false}, N:\${b_null}"
        MLC;
        
        $data = $this->parser->parseContent($content);
        $this->assertEquals('T:true, F:false, N:null', $data['mixed']);
    }

    #[Test]
    public function test_resolve_node_with_non_string_defensive_check(): void
    {
        $reflection = new \ReflectionMethod(MlcParser::class, 'resolveNode');
        
        $node = 123;
        $rootData = [];
        $reflection->invokeArgs($this->parser, [&$node, &$rootData, []]);
        
        $this->assertEquals(123, $node); // Unchanged
    }

    #[Test]
    public function test_parse_value_numeric_edge_cases(): void
    {
        // Hit float vs int
        $reflection = new \ReflectionMethod(MlcParser::class, 'parseValue');
        
        $this->assertSame(42, $reflection->invoke($this->parser, "42"));
        $this->assertSame(42.0, $reflection->invoke($this->parser, "42.0"));
        $this->assertSame(-10.5, $reflection->invoke($this->parser, "-10.5"));
    }

    #[Test]
    public function test_resolve_variable_nested_not_found(): void
    {
        $reflection = new \ReflectionMethod(MlcParser::class, 'resolveVariable');
        $rootData = ['a' => ['b' => 1]];
        
        // Path dots but key missing in middle
        $res = $reflection->invokeArgs($this->parser, ['a.c.d', &$rootData, []]);
        $this->assertNull($res);
        
        // Path ends at non-array
        $res = $reflection->invokeArgs($this->parser, ['a.b.c', &$rootData, []]);
        $this->assertNull($res);
    }

    #[Test]
    public function test_parse_value_mixed_case_booleans(): void
    {
        $reflection = new \ReflectionMethod(MlcParser::class, 'parseValue');
        
        $this->assertTrue($reflection->invoke($this->parser, "TRUE"));
        $this->assertFalse($reflection->invoke($this->parser, "fALSe"));
        $this->assertNull($reflection->invoke($this->parser, "nULl"));
    }

    #[Test]
    public function test_resolve_node_mixed_expansion_default_fallback(): void
    {
        // Hit the 'default' branch in match(true)
        $rootData = ['obj' => new \stdClass()];
        $node = "Result is \${obj}";
        
        $reflection = new \ReflectionMethod(MlcParser::class, 'resolveNode');
        $reflection->invokeArgs($this->parser, [&$node, &$rootData, []]);
        
        $this->assertEquals('Result is unknown', $node);
    }

    #[Test]
    public function test_resolve_node_standalone_expansion_recursive_string(): void
    {
        // Hit the case where resolvedValue is a string and needs parseValue
        $rootData = ['ref' => '42'];
        $node = "\${ref}";
        
        $reflection = new \ReflectionMethod(MlcParser::class, 'resolveNode');
        $reflection->invokeArgs($this->parser, [&$node, &$rootData, []]);
        
        $this->assertSame(42, $node); // String '42' parsed as int 42
    }
}
