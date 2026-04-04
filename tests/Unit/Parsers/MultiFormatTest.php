<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Parsers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Parsers\JsonParser;
use MonkeysLegion\Mlc\Parsers\YamlParser;
use MonkeysLegion\Mlc\Parsers\PhpParser;
use MonkeysLegion\Mlc\Parsers\CompositeParser;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;

class MultiFormatTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mlc_multi_test_' . uniqid();
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

    #[Test]
    public function test_should_be_able_to_load_multiple_formats(): void
    {
        // 1. Prepare files
        file_put_contents($this->tempDir . '/app.json', json_encode(['name' => 'JSON App', 'version' => 1]));
        file_put_contents($this->tempDir . '/service.yaml', "app:\n  port: 8080\n  mode: prod");
        file_put_contents($this->tempDir . '/config.php', "<?php return ['php' => 'rocks', 'arr' => [1,2]];");
        file_put_contents($this->tempDir . '/main.mlc', "main_key = \"val\"");

        // 2. Set up Composite Parser
        $repository = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $repository);
        $root = $this->tempDir;

        $mlc = new MlcParser($bootstrapper, $root);
        $composite = new CompositeParser($mlc);
        $composite->registerParser('json', new JsonParser());
        $composite->registerParser('yaml', new YamlParser());
        $composite->registerParser('yml', new YamlParser());
        $composite->registerParser('php', new PhpParser($bootstrapper, $root));

        // 3. Initialize Loader
        $loader = new Loader($composite, $this->tempDir);

        // 4. Load & Merge
        $config = $loader->load(['app', 'service', 'config', 'main']);

        // 5. Verify
        $this->assertEquals('JSON App', $config->get('name'));
        $this->assertEquals(8080, $config->get('app.port'));
        $this->assertEquals('rocks', $config->get('php'));
        $this->assertEquals('val', $config->get('main_key'));
    }

    #[Test]
    public function test_should_be_able_to_include_json_inside_mlc(): void
    {
        // 1. Prepare MLC with @include for JSON
        file_put_contents($this->tempDir . '/extra.json', json_encode(['from_json' => 'hello']));
        file_put_contents($this->tempDir . '/main.mlc', <<<MLC
        outer_key = "outer"
        @include extra.json
        MLC);

        // 2. Set up Composite Parser
        $repository = new NativeEnvRepository();
        $bootstrapper = new EnvManager(new DotenvLoader(), $repository);
        $root = $this->tempDir;

        $mlcParser = new MlcParser($bootstrapper, $root);
        $composite = new CompositeParser($mlcParser);
        $composite->registerParser('json', new JsonParser());

        // 3. Loader
        $loader = new Loader($composite, $this->tempDir);
        $config = $loader->loadOne('main');

        // 4. Verify
        $this->assertEquals('outer', $config->get('outer_key'));
        $this->assertEquals('hello', $config->get('from_json'));
    }
}
