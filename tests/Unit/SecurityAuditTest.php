<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Exception\SecurityException;

class SecurityAuditTest extends TestCase
{
    private string $tempDir;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mlc_security_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        
        $this->tempFile = $this->tempDir . '/config.mlc';
        file_put_contents($this->tempFile, "key = value\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_loose_mode_triggers_warning_for_world_writable_file(): void
    {
        // Make file world-writable
        chmod($this->tempFile, 0777);

        $parser = new MlcParser(new NativeEnvRepository());

        // PHPUnit can catch trigger_error via expectWarning
        // Or we can set a custom error handler to verify the exact warning.
        $warningTriggered = false;
        set_error_handler(function($errno, $errstr) use (&$warningTriggered) {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'world-writable')) {
                $warningTriggered = true;
                return true; // prevent error bubbling
            }
            return false;
        });

        $parser->parseFile($this->tempFile);
        
        restore_error_handler();

        $this->assertTrue($warningTriggered, "A warning should be triggered for world-writable files in loose mode.");
    }

    public function test_strict_mode_throws_security_exception_via_parser(): void
    {
        chmod($this->tempFile, 0777);

        $parser = new MlcParser(new NativeEnvRepository());
        $parser->enableStrictSecurity();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageMatches('/is world-writable.*severe security risk/i');

        $parser->parseFile($this->tempFile);
    }

    public function test_strict_mode_propagates_from_loader_to_parser(): void
    {
        chmod($this->tempFile, 0777);

        $parser = new MlcParser(new NativeEnvRepository());
        
        // Pass strictSecurity = true as the 6th argument
        $loader = new Loader(
            parser: $parser,
            baseDir: $this->tempDir,
            strictSecurity: true
        );

        $this->expectException(\MonkeysLegion\Mlc\Exception\LoaderException::class);
        $this->expectExceptionMessageMatches('/is world-writable/i');

        // Loading should trigger it inside parser
        $loader->load(['config']);
    }

    public function test_strict_mode_allows_secure_files(): void
    {
        // 0644 is user writable, but not group/world writable
        chmod($this->tempFile, 0644);

        $parser = new MlcParser(new NativeEnvRepository());
        $parser->enableStrictSecurity();

        $data = $parser->parseFile($this->tempFile);

        $this->assertIsArray($data);
        $this->assertSame('value', $data['key']);
    }
}
