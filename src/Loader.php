<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc;

use JsonException;
use RuntimeException;

final class Loader
{
    public function __construct(
        private Parser $parser,
        private string $baseDir  // directory containing .mlc files
    ) {}

    /**
     * Load & merge multiple named files: ['app','cors'] → app.mlc, cors.mlc
     *
     * Later files override earlier keys.
     *
     * @param string[] $names
     * @return Config
     * @throws JsonException
     */
    public function load(array $names): Config
    {
        $merged = [];
        foreach ($names as $n) {
            $path = "{$this->baseDir}/{$n}.mlc";
            if (! is_file($path)) {
                throw new RuntimeException("Config file missing: {$path}");
            }
            $cfg = $this->parser->parseFile($path);
            $merged = array_replace_recursive($merged, $cfg);
        }
        return new Config($merged);
    }
}