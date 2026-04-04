<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Parsers;

use MonkeysLegion\Mlc\Contracts\ParserInterface;
use MonkeysLegion\Mlc\Exception\ParserException;
use MonkeysLegion\Mlc\Exception\SecurityException;
use MonkeysLegion\Mlc\Parsers\MlcParser;

/**
 * Composite Parser that delegates to others based on file extension.
 */
final class CompositeParser implements ParserInterface
{
    /**
     * Map of extension -> ParserInterface
     * @var array<string, ParserInterface>
     */
    private array $parsers = [];

    /**
     * The parser used for the most recent operation.
     */
    private ?ParserInterface $lastActiveParser = null;

    /**
     * Constructor for CompositeParser.
     *
     * @param ParserInterface $defaultParser Parser used for .mlc and files with no extension
     */
    public function __construct(
        private ParserInterface $defaultParser
    ) {
        $this->registerParser('mlc', $defaultParser);

        if ($defaultParser instanceof MlcParser) {
            $defaultParser->setDelegate($this);
        }
    }

    /**
     * Register a parser for a specific extension.
     */
    public function registerParser(string $extension, ParserInterface $parser): self
    {
        $this->parsers[ltrim($extension, '.')] = $parser;

        // If the parser supports delegation (like MlcParser), set this composite as its delegate.
        if ($parser instanceof MlcParser) {
            $parser->setDelegate($this);
        }

        return $this;
    }

    public function enableStrictSecurity(bool $strict = true): self
    {
        foreach ($this->parsers as $parser) {
            $parser->enableStrictSecurity($strict);
        }
        $this->defaultParser->enableStrictSecurity($strict);
        return $this;
    }

    public function parseFile(string $file): array
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $this->lastActiveParser = $this->parsers[$extension] ?? $this->defaultParser;

        return $this->lastActiveParser->parseFile($file);
    }

    public function parseContent(string $content, string $filename = '<string>', bool $resolveReferences = true): array
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $this->lastActiveParser = $this->parsers[$extension] ?? $this->defaultParser;

        return $this->lastActiveParser->parseContent($content, $filename, $resolveReferences);
    }

    public function getParsedFiles(): array
    {
        return $this->lastActiveParser?->getParsedFiles() ?? [];
    }

    public function getDefaultParser(): ParserInterface
    {
        return $this->defaultParser;
    }
}
