<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers\Traits;

use MonkeysLegion\Mlc\Exception\SecurityException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trick to override global filesize for this namespace only to test failure path.
 */
function filesize(string $file): int|false {
    if ($file === 'trigger_fail_filesize') {
        return false;
    }
    return \filesize($file);
}

class SecurityCoverageTest extends TestCase
{
    use FileSecurityTrait;

    #[Test]
    public function test_validate_file_size_failure(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage("Could not determine size");
        
        $this->validateFileSize('trigger_fail_filesize');
    }

    #[Test]
    public function test_validate_filepath_not_found(): void
    {
        // This hits the realpath === false branch
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage("Config file not found or inaccessible");
        
        $this->validateFilePath('/non/existent/path/xyz/123');
    }
}
