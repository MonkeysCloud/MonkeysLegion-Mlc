<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;

class ParserTest extends TestCase
{
    private Parser $parser;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'mlc_test_') . '.mlc';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            chmod($this->tempFile, 0644); // Ensure we can delete it
            unlink($this->tempFile);
        }
    }

    public function test_nested_sections_should_be_parsed_correctly(): void
    {
        $content = <<<MLC
        database {
            connection {
                host "localhost"
                port 3306
            }
            timeout 5.5
        }
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals('localhost', $data['database']['connection']['host']);
        $this->assertEquals(3306, $data['database']['connection']['port']);
        $this->assertEquals(5.5, $data['database']['timeout']);
    }

    public function test_multi_line_arrays_should_be_parsed_correctly(): void
    {
        $content = <<<MLC
        items = [
            "apple",
            "banana",
            "cherry"
        ]
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals(['apple', 'banana', 'cherry'], $data['items']);
    }

    public function test_json_objects_should_be_parsed_correctly(): void
    {
        $content = 'options {"retries": 3, "enabled": true}';
        $data = $this->parser->parseContent($content);

        $this->assertEquals(['retries' => 3, 'enabled' => true], $data['options']);
    }

    public function test_different_separators_should_work(): void
    {
        $content = <<<MLC
        key_eq = value1
        key_ws value2
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals('value1', $data['key_eq']);
        $this->assertEquals('value2', $data['key_ws']);
    }

    public function test_unclosed_section_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Unclosed section: missing '}' at end of file");

        $content = <<<MLC
        database {
            host 'localhost'
        MLC;
        $this->parser->parseContent($content);
    }

    public function test_redefining_key_as_section_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Cannot redefine key 'database' as section");

        $content = <<<MLC
        database = "mysql"
        database {
            host "localhost"
        }
        MLC;
        $this->parser->parseContent($content);
    }

    public function test_duplicate_key_should_trigger_warning(): void
    {
        $content = <<<MLC
        key val1
        key val2
        MLC;
        
        $data = @$this->parser->parseContent($content);
        $this->assertEquals('val2', $data['key']);
    }

    public function test_syntax_error_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Syntax error");

        $content = "invalid-line-without-separator";
        $this->parser->parseContent($content);
    }

    public function test_file_size_limit_should_throw_exception(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage("Config file too large");

        $fp = fopen($this->tempFile, 'w');
        fseek($fp, 11 * 1024 * 1024);
        fwrite($fp, ' ');
        fclose($fp);

        $this->parser->parseFile($this->tempFile);
    }

    public function test_path_traversal_should_throw_exception(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage("Path traversal detected");
        
        $this->parser->parseFile('/tmp/../../../etc/passwd');
    }

    public function test_non_existent_file_should_throw_exception(): void
    {
        $this->expectException(SecurityException::class);
        
        $this->parser->parseFile('/tmp/mlc_non_existent_file_xyz.mlc');
    }

    public function test_non_readable_file_should_throw_exception(): void
    {
        if (get_current_user() === 'root') {
            $this->markTestSkipped('Cannot test non-readable files as root');
        }

        @touch($this->tempFile);
        chmod($this->tempFile, 0000);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage("Config file not readable");

        try {
            $this->parser->parseFile($this->tempFile);
        } finally {
            chmod($this->tempFile, 0644);
        }
    }

    public function test_world_writable_file_should_trigger_warning(): void
    {
        @touch($this->tempFile);
        chmod($this->tempFile, 0666);

        $data = @$this->parser->parseFile($this->tempFile);
        $this->assertIsArray($data);
    }

    public function test_empty_file_should_trigger_notice(): void
    {
        file_put_contents($this->tempFile, "");
        $data = @$this->parser->parseFile($this->tempFile);
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_parse_value_with_different_types_should_work(): void
    {
        $content = <<<MLC
        is_true TRUE
        is_false false
        my_int 42
        my_float 3.14
        my_null NULL
        quoted_string "Value with spaces"
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertTrue($data['is_true']);
        $this->assertFalse($data['is_false']);
        $this->assertSame(42, $data['my_int']);
        $this->assertSame(3.14, $data['my_float']);
        $this->assertNull($data['my_null']);
    }

    public function test_json_array_decode_failure_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Invalid JSON array");

        $content = "items [invalid-json]";
        $this->parser->parseContent($content);
    }

    public function test_invalid_json_object_wrapped_in_braces_should_throw(): void
    {
        $this->expectException(ParserException::class);

        $content = 'data {"port": 80 "host": "local"}'; 
        $this->parser->parseContent($content);
    }

    public function test_unclosed_array_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Unclosed array starting at line 1");

        $content = "items = [ \"one\", \"two\" ";
        $this->parser->parseContent($content);
    }

    public function test_max_nesting_depth_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Maximum nesting depth exceeded");
        
        $lines = [];
        for ($i = 0; $i < 51; $i++) { $lines[] = "s{$i} {"; }
        for ($i = 0; $i < 51; $i++) { $lines[] = "}"; }
        
        $this->parser->parseContent(implode("\n", $lines));
    }

    public function test_directory_passed_as_file_should_throw_exception(): void
    {
        $this->expectException(SecurityException::class);
        @$this->parser->parseFile(sys_get_temp_dir());
    }

    public function test_numeric_edge_cases_should_work(): void
    {
        $content = <<<MLC
        neg_int -123
        pos_float +5.67
        MLC;
        
        $data = $this->parser->parseContent($content);
        $this->assertSame(-123, $data['neg_int']);
        $this->assertSame(5.67, $data['pos_float']);
    }

    public function test_empty_value_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Syntax error");

        $content = "key =";
        $this->parser->parseContent($content);
    }

    public function test_standalone_null_value_should_return_null(): void
    {
        $content = "val null";
        $data = $this->parser->parseContent($content);
        $this->assertNull($data['val']);
    }

    public function test_inline_env_expansion_with_types_should_convert_to_strings(): void
    {
        putenv('ENV_TRUE=true');
        putenv('ENV_FALSE=false');
        putenv('ENV_NULL=null');
        
        $content = <<<MLC
        a = "is \${ENV_TRUE}"
        b = "is \${ENV_FALSE}"
        c = "is \${ENV_NULL}"
        d = "is \${ENV_OTHER:-fallback}"
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals('is true', $data['a']);
        $this->assertEquals('is false', $data['b']);
        $this->assertEquals('is null', $data['c']);
        $this->assertEquals('is fallback', $data['d']);
    }

    public function test_unexpected_closing_brace_should_throw_exception(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Unexpected closing brace '}' without matching opening brace");

        $content = "}";
        $this->parser->parseContent($content);
    }
}
