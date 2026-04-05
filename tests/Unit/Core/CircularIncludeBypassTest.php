<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Core;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\ParserException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CircularIncludeBypassTest extends TestCase
{
    private string $baseDir;
    private MlcParser $parser;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mlc_circular_bypass_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
        $this->parser = new MlcParser($bootstrapper, $this->baseDir);
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
    public function test_circular_include_detection_is_bypassed_by_normalization_mismatch(): void
    {
        // 1. Create a loop: a.mlc -> b.mlc -> a.mlc
        // Use a relative path from the perspective of the test
        $aPath = $this->baseDir . '/a.mlc';
        $bPath = $this->baseDir . '/b.mlc';
        
        file_put_contents($aPath, "@include b.mlc");
        file_put_contents($bPath, "@include a.mlc");
        
        // 2. Trigger parsing with a NON-NORMALIZED path (e.g. including ./)
        $nonNormalizedA = $this->baseDir . '/./a.mlc';
        
        // This should throw a ParserException for circular include
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Circular include');
        
        $this->parser->parseFile($nonNormalizedA);
    }
}
