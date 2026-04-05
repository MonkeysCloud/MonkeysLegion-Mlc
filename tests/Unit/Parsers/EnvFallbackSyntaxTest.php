<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Parsers\MlcParser;

class EnvFallbackSyntaxTest extends TestCase
{
    private MlcParser $parser;

    protected function setUp(): void
    {
        $repository = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $repository);
        $this->parser = new MlcParser($bootstrapper, sys_get_temp_dir());
    }

    #[Test]
    public function test_env_fallback_types(): void
    {
        $content = <<<MLC
        val_string    = \${MISSING:-default}
        val_int       = \${MISSING:-123}
        val_bool      = \${MISSING:-true}
        val_null      = \${MISSING:-null}
        val_quoted    = \${MISSING:-"quoted_default"}
        val_single    = \${MISSING:-'single_quoted'}
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertSame('default', $data['val_string']);
        $this->assertSame(123, $data['val_int']);
        $this->assertSame(true, $data['val_bool']);
        $this->assertNull($data['val_null']);
        $this->assertSame('quoted_default', $data['val_quoted']);
        $this->assertSame('single_quoted', $data['val_single']);
    }

    #[Test]
    public function test_env_fallback_with_nested_reference(): void
    {
        $content = <<<MLC
        OTHER = "resolved_from_ref"
        val   = \${MISSING:-\${OTHER}}
        MLC;

        $data = $this->parser->parseContent($content);

        // This works now because of the recursive fix.
        $this->assertSame('resolved_from_ref', $data['val']);
    }

    #[Test]
    public function test_env_triple_nested_fallback(): void
    {
        $content = <<<MLC
        val = \${MISSING:-\${OTHER_MISSING:-finally_this}}
        MLC;

        $data = $this->parser->parseContent($content);

        $this->assertSame('finally_this', $data['val']);
    }

    #[Test]
    public function test_env_nested_missing_resolves_to_null(): void
    {
        $content = <<<MLC
        val = \${MISSING:-\${STILL_MISSING}}
        MLC;

        $data = $this->parser->parseContent($content);

        // If both MISSING and STILL_MISSING are missing, 
        // the final resolveVariable call for STILL_MISSING returns null.
        $this->assertNull($data['val']);
    }
}
