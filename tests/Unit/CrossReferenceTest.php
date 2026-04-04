<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use PHPUnit\Framework\TestCase;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Exception\CircularDependencyException;

class CrossReferenceTest extends TestCase
{
    private MlcParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MlcParser(new NativeEnvRepository());
    }

    public function test_simple_cross_key_reference_should_work(): void
    {
        $content = <<<MLC
        base_url "https://example.com"
        api_v1 "\${base_url}/v1"
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals('https://example.com/v1', $data['api_v1']);
    }

    public function test_circular_reference_should_throw_exception(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage("Circular dependency detected");

        // a -> b -> c -> a
        $content = <<<MLC
        a "\${b}"
        b "\${c}"
        c "\${a}"
        MLC;

        $this->parser->parseContent($content);
    }

    public function test_clean_reference_should_not_throw_exception(): void
    {
        $content = <<<MLC
        a "5"
        b "\${a}"
        c "\${b}"
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertEquals(5, $data['a']);
        $this->assertEquals(5, $data['b']);
        $this->assertEquals(5, $data['c']);
    }
}
