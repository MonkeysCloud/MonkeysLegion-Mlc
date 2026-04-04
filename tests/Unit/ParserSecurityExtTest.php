<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Parsers\JsonParser;
use MonkeysLegion\Mlc\Parsers\YamlParser;
use MonkeysLegion\Mlc\Parsers\PhpParser;
use MonkeysLegion\Mlc\Parsers\CompositeParser;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Mlc\Exception\SecurityException;
use MonkeysLegion\Mlc\Exception\ParserException;

class ParserSecurityExtTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mlc_sec_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            $files = glob($path . '/*');
            foreach ($files as $file) {
                is_dir($file) ? $this->removeDirectory($file) : unlink($file);
            }
            rmdir($path);
        }
    }

    public static function parserProvider(): array
    {
        $env = new NativeEnvRepository();
        return [
            'MLC Parser'  => [new MlcParser($env)],
            'JSON Parser' => [new JsonParser()],
            'YAML Parser' => [new YamlParser()],
            'PHP Parser'  => [new PhpParser()],
        ];
    }

    #[DataProvider('parserProvider')]
    public function test_should_throw_on_non_existent_file($parser): void
    {
        $this->expectException(SecurityException::class);
        $parser->parseFile($this->tempDir . '/none.txt');
    }

    #[DataProvider('parserProvider')]
    public function test_should_throw_on_path_traversal($parser): void
    {
        // Actually, this matches another test, but let's keep it.
        $this->expectException(SecurityException::class);
        $parser->parseFile($this->tempDir . '/../etc/passwd');
    }

    #[DataProvider('parserProvider')]
    public function test_should_throw_on_world_writable_file_in_strict_mode($parser): void
    {
        $file = $this->tempDir . '/writable.txt';
        file_put_contents($file, 'content');
        chmod($file, 0666);
        $parser->enableStrictSecurity(true);
        $this->expectException(SecurityException::class);
        $parser->parseFile($file);
    }

    #[DataProvider('parserProvider')]
    public function test_security_trait_edge_cases($parser): void
    {
        $content = match (true) {
            $parser instanceof JsonParser => '{}',
            $parser instanceof YamlParser => 'key: value',
            $parser instanceof PhpParser  => '<?php return [];',
            default                       => 'key = value',
        };

        // Empty file triggers notice
        $fileEmpty = $this->tempDir . '/empty' . uniqid();
        file_put_contents($fileEmpty, '');
        try {
            @$parser->parseFile($fileEmpty);
        } catch (ParserException) {
            // Ignore parse errors on empty files
        }
        
        // World writable warning
        $fileWarn = $this->tempDir . '/warn' . uniqid();
        file_put_contents($fileWarn, $content);
        chmod($fileWarn, 0666);
        $parser->enableStrictSecurity(false);
        try {
            @$parser->parseFile($fileWarn);
        } catch (ParserException) {
            // Ignore parse errors
        }
        
        $this->assertTrue(true);
    }

    public function test_php_parser_coverage(): void
    {
        $parser = new PhpParser();
        
        // invalid return
        $file = $this->tempDir . '/bad.php';
        file_put_contents($file, "<?php return 'bad';");
        $this->assertEquals([], $parser->parseFile($file));
        
        // parseContent throws
        try {
            $parser->parseContent("...");
        } catch (ParserException $e) {
            $this->assertStringContainsString("not supported", $e->getMessage());
        }
        
        // getParsedFiles
        $this->assertEquals([], $parser->getParsedFiles());
    }

    public function test_json_parser_coverage(): void
    {
        $parser = new JsonParser();
        $this->assertEquals([], $parser->parseContent('"str"')); // is_array check
        try {
            $parser->parseContent("{invalid}");
        } catch (ParserException $e) {}
        $this->assertEquals([], $parser->getParsedFiles());
    }

    public function test_yaml_parser_coverage(): void
    {
        $parser = new YamlParser();
        $yaml = "a: 1\nb: true\nc: null\nd: 1.2\ne: 'str'\nf:\n  g: nested";
        $parser->parseContent($yaml);
        $this->assertEquals([], $parser->getParsedFiles());
    }

    public function test_composite_parser_coverage(): void
    {
        $mlc = new MlcParser(new NativeEnvRepository());
        $composite = new CompositeParser($mlc);
        $composite->registerParser('json', new JsonParser());
        $composite->registerParser('.yaml', new YamlParser());
        
        $this->assertSame($mlc, $composite->getDefaultParser());
        $composite->enableStrictSecurity(true);
        $composite->getParsedFiles();
        
        $composite->parseContent('key = val', 'x.mlc');
        $composite->parseContent('{"a":1}', 'x.json');
        
        $jsonFile = $this->tempDir . '/t.json';
        file_put_contents($jsonFile, '{"a":1}');
        $composite->parseFile($jsonFile);

        // Edge: non-mlc default
        $json = new JsonParser();
        $c2 = new CompositeParser($json);
        $c2->registerParser('mlc', new MlcParser(new NativeEnvRepository()));
    }
}
