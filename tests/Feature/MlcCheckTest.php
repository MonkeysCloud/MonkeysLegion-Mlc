<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Feature;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MlcCheckTest extends TestCase
{
    private string $tempDir;
    private string $binPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mlc_check_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        
        // Correct path to the binary relative to this test file (tests/Feature/MlcCheckTest.php)
        $this->binPath = realpath(__DIR__ . '/../../bin/mlc-check');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
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
    public function test_binary_successfully_validates_valid_files(): void
    {
        $filePath = $this->tempDir . '/valid.mlc';
        file_put_contents($filePath, 'key = "val"');
        
        $output = [];
        $exitCode = 0;
        // Use PHP to execute the binary script for testing stability
        exec("php {$this->binPath} {$filePath} 2>&1", $output, $exitCode);
        
        $fullOutput = implode("\n", $output);
        $this->assertEquals(0, $exitCode, "Binary should return exit code 0 for valid files. Output: " . $fullOutput);
        $this->assertStringContainsString('[OK]', $fullOutput);
        $this->assertStringContainsString('SUCCESS!', $fullOutput);
    }

    #[Test]
    public function test_binary_fails_on_invalid_syntax(): void
    {
        $filePath = $this->tempDir . '/invalid.mlc';
        // Unclosed section (properly multi-line so it matches section start)
        file_put_contents($filePath, "section {\n  key = val\n");
        
        $output = [];
        $exitCode = 0;
        exec("php {$this->binPath} {$filePath} 2>&1", $output, $exitCode);
        
        $fullOutput = implode("\n", $output);
        $this->assertEquals(1, $exitCode, "Binary should return exit code 1 for invalid files. Output: " . $fullOutput);
        $this->assertStringContainsString('[FAIL]', $fullOutput);
        $this->assertStringContainsString('Unclosed section', $fullOutput);
    }

    #[Test]
    public function test_binary_validates_php_configs_via_composite(): void
    {
        $filePath = $this->tempDir . '/config.php';
        file_put_contents($filePath, '<?php return ["foo" => "bar"];');

        $output = [];
        $exitCode = 0;
        exec("php {$this->binPath} {$filePath} 2>&1", $output, $exitCode);

        $fullOutput = implode("\n", $output);
        $this->assertEquals(0, $exitCode, "Binary should return exit code 0 for valid PHP config.");
        $this->assertStringContainsString('[OK]', $fullOutput);
        $this->assertStringContainsString('SUCCESS!', $fullOutput);
    }
}
