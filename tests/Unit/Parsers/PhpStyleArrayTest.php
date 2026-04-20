<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Parsers\MlcParser;

class PhpStyleArrayTest extends TestCase
{
    private MlcParser $parser;

    protected function setUp(): void
    {
        $repository = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $repository);
        $this->parser = new MlcParser($bootstrapper, sys_get_temp_dir());
    }

    #[Test]
    public function test_it_supports_php_style_simple_arrays(): void
    {
        $content = <<<MLC
        public = ['/bla', 'dahdad']
        mixed = ["foo", 'bar', 123]
        multi = [
            'one',
            '/two/three'
        ]
        with_internal_quotes = ['It\'s a quote', "He said \"Hello\""]
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertSame(['/bla', 'dahdad'], $data['public']);
        $this->assertSame(['foo', 'bar', 123], $data['mixed']);
        $this->assertSame(['one', '/two/three'], $data['multi']);
        $this->assertSame(["It's a quote", 'He said "Hello"'], $data['with_internal_quotes']);
    }
}
