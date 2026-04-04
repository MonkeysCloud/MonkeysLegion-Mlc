<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FinalCoverageTest extends TestCase
{
    #[Test]
    public function test_config_typed_getters_coverage(): void
    {
        $config = new Config([
            'int' => 42,
            'float' => 3.14,
            'string' => 'hello',
            'bool' => true,
            'array' => [1, 2]
        ]);
        
        $this->assertEquals(42, $config->getInt('int'));
        $this->assertEquals(3.14, $config->getFloat('float'));
        $this->assertEquals('hello', $config->getString('string'));
        $this->assertTrue($config->getBool('bool'));
        $this->assertEquals([1, 2], $config->getArray('array'));
        
        // Null defaults
        $this->assertNull($config->getInt('missing'));
        $this->assertNull($config->getFloat('missing'));
        $this->assertNull($config->getString('missing'));
        $this->assertNull($config->getBool('missing'));
        $this->assertNull($config->getArray('missing'));
    }

    #[Test]
    public function test_mlc_parser_unreachable_branches(): void
    {
        $repository = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $repository);
        $parser = new MlcParser($bootstrapper, sys_get_temp_dir());
        
        // Explicitly set currentFile to <string>
        $refProp = new \ReflectionProperty(MlcParser::class, 'currentFile');
        $refProp->setAccessible(true);
        $refProp->setValue($parser, '<string>');
        
        // resolveIncludePath with <string>
        $reflection = new \ReflectionMethod(MlcParser::class, 'resolveIncludePath');
        $this->assertEquals('other.mlc', $reflection->invoke($parser, 'other.mlc'));
        
        // resolveIncludePath with realpath failure
        // Mock currentFile to a non-existent dir
        $refProp->setValue($parser, '/tmp/ghost/file.mlc');
        
        $path = $reflection->invoke($parser, 'inc.mlc');
        // On Unix, dirname('/tmp/ghost/file.mlc') is '/tmp/ghost'
        // So path is '/tmp/ghost/inc.mlc'
        $this->assertSame('/tmp/ghost' . DIRECTORY_SEPARATOR . 'inc.mlc', $path);
    }
}
