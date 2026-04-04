<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Config;

class FullSyntaxTest extends TestCase
{
    private MlcParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MlcParser(new NativeEnvRepository());
    }

    private function getComprehensiveContent(): string
    {
        return <<<MLC
        # This is a comment
        
        # 1. Basic Types and Syntaxes
        string_unquoted  hello
        string_double    "hello world"
        string_single    'hello world'
        integer_pos      42
        integer_neg      -10
        float_pos        3.1415
        float_neg        -0.5
        bool_true_1      true
        bool_true_2      TRUE
        bool_false_1     false
        bool_false_2     FALSE
        null_value_1     null
        null_value_2     NULL
        
        # 2. Assignment syntaxes
        with_equals = yes
        without_equals yes
        
        # 3. Simple JSON Arrays/Objects
        simple_array = [1, 2, "three", true, null]
        simple_object = {"key": "value", "num": 100}
        
        # 4. Multi-line Arrays
        multi_array = [
            "first",
            "second",
            "third"
        ]
        
        # 5. Nested Sections
        app {
            name = "MonkeysLegion"
            version 3.0
            
            database {
                host = localhost
                port = 3306
            }
        }
        
        # 6. Interpolation / References
        base_url = "https://monkeys.cloud"
        endpoint = "\${base_url}/api/v1"
        missing_env = \${NEVER_WILL_EXIST:-fallback_value}
        boolean_ref = \${bool_true_1}
        
        MLC;
    }

    public function test_comprehensive_syntax_and_types_should_parse_correctly(): void
    {
        $content = $this->getComprehensiveContent();
        $data = $this->parser->parseContent($content);

        // 1. Strings
        $this->assertSame('hello', $data['string_unquoted']);
        $this->assertSame('hello world', $data['string_double']);
        $this->assertSame('hello world', $data['string_single']);
        
        // 2. Numbers
        $this->assertSame(42, $data['integer_pos']);
        $this->assertSame(-10, $data['integer_neg']);
        $this->assertSame(3.1415, $data['float_pos']);
        $this->assertSame(-0.5, $data['float_neg']);
        
        // 3. Booleans
        $this->assertTrue($data['bool_true_1']);
        $this->assertTrue($data['bool_true_2']);
        $this->assertFalse($data['bool_false_1']);
        $this->assertFalse($data['bool_false_2']);
        
        // 4. Nulls
        $this->assertNull($data['null_value_1']);
        $this->assertNull($data['null_value_2']);
        
        // 5. Syntaxes
        $this->assertSame('yes', $data['with_equals']);
        $this->assertSame('yes', $data['without_equals']);
        
        // 6. JSON Data structures
        $this->assertSame([1, 2, "three", true, null], $data['simple_array']);
        $this->assertSame(['key' => 'value', 'num' => 100], $data['simple_object']);
        $this->assertSame(["first", "second", "third"], $data['multi_array']);
        
        // 7. Sections
        $this->assertSame("MonkeysLegion", $data['app']['name']);
        $this->assertSame(3.0, $data['app']['version']);
        $this->assertSame('localhost', $data['app']['database']['host']);
        $this->assertSame(3306, $data['app']['database']['port']);
        
        // 8. Interpolation and References
        $this->assertSame("https://monkeys.cloud", $data['base_url']);
        $this->assertSame("https://monkeys.cloud/api/v1", $data['endpoint']);
        $this->assertSame("fallback_value", $data['missing_env']);
        $this->assertTrue($data['boolean_ref']);
    }

    public function test_comprehensive_syntax_via_config_dot_notation(): void
    {
        $content = $this->getComprehensiveContent();
        $data = $this->parser->parseContent($content);
        $config = new Config($data);

        // 9. Config Getters & Dot Notation
        $this->assertSame('MonkeysLegion', $config->getString('app.name'));
        $this->assertSame(3306, $config->getInt('app.database.port'));
        $this->assertSame(3.0, $config->getFloat('app.version'));
        
        $this->assertTrue($config->getBool('bool_true_1'));
        $this->assertSame(-10, $config->getInt('integer_neg'));
        
        $this->assertSame('fallback_value', $config->getString('missing_env'));
        $this->assertSame('https://monkeys.cloud/api/v1', $config->getString('endpoint'));
        
        // 10. Config Arrays
        $expectedMulti = ["first", "second", "third"];
        $this->assertSame($expectedMulti, $config->getArray('multi_array'));
        
        // 11. Config Null and Exists
        $this->assertNull($config->get('null_value_1'));
        $this->assertTrue($config->has('app.database.host'));
        $this->assertFalse($config->has('app.database.password'));
    }
}
