<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\ParserException;

class IncludeTest extends TestCase
{
    private MlcParser $parser;
    private string $tempDir;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->parser = new MlcParser(new NativeEnvRepository());
        $this->tempDir = sys_get_temp_dir() . '/mlc_include_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempFiles) as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function createConfigFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function test_basic_include_works(): void
    {
        $this->createConfigFile('db.mlc', 'host = "localhost"');
        $main = $this->createConfigFile('main.mlc', '@include "db.mlc"');

        $data = $this->parser->parseFile($main);

        $this->assertEquals(['host' => 'localhost'], $data);
    }

    public function test_nested_include_works(): void
    {
        $this->createConfigFile('nested.mlc', 'key = "val"');
        $main = $this->createConfigFile('main.mlc', "section {\n  @include \"nested.mlc\"\n}");

        $data = $this->parser->parseFile($main);

        $this->assertEquals(['section' => ['key' => 'val']], $data);
    }

    public function test_recursive_include_works(): void
    {
        $this->createConfigFile('c.mlc', 'key_c = "c"');
        $this->createConfigFile('b.mlc', "@include \"c.mlc\"\nkey_b = \"b\"");
        $main = $this->createConfigFile('a.mlc', "@include \"b.mlc\"\nkey_a = \"a\"");

        $data = $this->parser->parseFile($main);

        $this->assertEquals([
            'key_c' => 'c',
            'key_b' => 'b',
            'key_a' => 'a'
        ], $data);
    }

    public function test_circular_include_throws_exception(): void
    {
        $this->createConfigFile('a.mlc', '@include "b.mlc"');
        $this->createConfigFile('b.mlc', '@include "a.mlc"');

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Circular include detected');

        $this->parser->parseFile($this->tempDir . '/a.mlc');
    }

    public function test_cross_file_references_work(): void
    {
        $this->createConfigFile('base.mlc', 'domain = "example.com"');
        $main = $this->createConfigFile('main.mlc', <<<MLC
@include "base.mlc"
url = "https://\${domain}/api"
MLC
        );

        $data = $this->parser->parseFile($main);

        $this->assertEquals('example.com', $data['domain']);
        $this->assertEquals('https://example.com/api', $data['url']);
    }

    public function test_include_overriding_behavior(): void
    {
        $this->createConfigFile('defaults.mlc', <<<MLC
port = 3306
timeout = 5
MLC
        );
        $main = $this->createConfigFile('overrides.mlc', <<<MLC
@include "defaults.mlc"
port = 5432
MLC
        );

        $data = @$this->parser->parseFile($main);

        $this->assertEquals(5432, $data['port']);
        $this->assertEquals(5, $data['timeout']);
    }

    public function test_parser_tracks_all_parsed_files(): void
    {
        $dir = $this->tempDir;
        $this->createConfigFile('db.mlc', 'port = 3306');
        $appPath = $this->createConfigFile('app.mlc', '@include "db.mlc"');

        $this->parser->parseFile($appPath);
        $parsedFiles = $this->parser->getParsedFiles();

        $this->assertCount(2, $parsedFiles);
        $this->assertContains(realpath($appPath), $parsedFiles);
        $this->assertContains(realpath($dir . '/db.mlc'), $parsedFiles);
    }

    public function test_include_with_single_quotes_and_spaces_works(): void
    {
        $this->createConfigFile('db details.mlc', 'host = "localhost"');
        $main = $this->createConfigFile('main1.mlc', "@include 'db details.mlc'");

        $data = $this->parser->parseFile($main);
        $this->assertEquals(['host' => 'localhost'], $data);
    }

    public function test_include_with_double_quotes_and_spaces_works(): void
    {
        $this->createConfigFile('db details.mlc', 'host = "localhost"');
        $main = $this->createConfigFile('main1.mlc', "@include \"db details.mlc\"");

        $data = $this->parser->parseFile($main);
        $this->assertEquals(['host' => 'localhost'], $data);
    }

    public function test_unquoted_include_works(): void
    {
        $this->createConfigFile('unquoted.mlc', 'key = "val"');
        $main = $this->createConfigFile('main2.mlc', "@include unquoted.mlc");

        $data = $this->parser->parseFile($main);
        $this->assertEquals(['key' => 'val'], $data);
    }

    public function test_angle_bracket_include_works(): void
    {
        $this->createConfigFile('angle.mlc', 'key = "val"');
        $main = $this->createConfigFile('main3.mlc', "@include <angle.mlc>");

        $data = $this->parser->parseFile($main);
        $this->assertEquals(['key' => 'val'], $data);
    }

    public function test_multiple_spaces_after_include_works(): void
    {
        $this->createConfigFile('spaces.mlc', 'key = "val"');
        $main = $this->createConfigFile('main4.mlc', "@include    spaces.mlc");

        $data = $this->parser->parseFile($main);
        $this->assertEquals(['key' => 'val'], $data);
    }

    public function test_mixed_quotes_in_include_should_throw_syntax_error(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage("Syntax error");

        // Mixed quotes: 'test.mlc"
        $main = $this->createConfigFile('mixed_quotes_main.mlc', "@include 'test.mlc\"");
        $this->parser->parseFile($main);
    }
}
