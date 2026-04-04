<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Integrations;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Parsers\JsonParser;
use MonkeysLegion\Mlc\Parsers\CompositeParser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CompositeFileLeakageTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_leak_test_' . uniqid();
        mkdir($this->baseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
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
    public function test_composite_parser_leaks_stale_file_lists(): void
    {
        $bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
        $mlc = new MlcParser($bootstrapper, $this->baseDir);
        $json = new JsonParser();
        $composite = new CompositeParser($mlc);
        $composite->registerParser('json', $json);

        // 1. First, parse an MLC file. This populates MlcParser's internal file list.
        $aPath = realpath($this->baseDir) . '/a.mlc';
        file_put_contents($aPath, 'key = "val"');
        $composite->parseFile($aPath);
        
        $this->assertContains($aPath, $composite->getParsedFiles(), "Initial parse should contain a.mlc");

        // 2. Next, parse a JSON file. This uses the JsonParser. 
        $bPath = realpath($this->baseDir) . '/b.json';
        file_put_contents($bPath, '{"foo": "bar"}');
        $composite->parseFile($bPath);
        
        $files = $composite->getParsedFiles();
        
        $this->assertNotContains(
            $aPath, 
            $files, 
            "STALE DATA LEAK: CompositeParser returned files from a previous parse operation (a.mlc) when the current parse (b.json) was independent."
        );
    }
}
