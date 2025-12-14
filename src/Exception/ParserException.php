<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Exception;

use Throwable;

/**
 * Exception thrown during config file parsing.
 */
class ParserException extends MlcException
{
    public function __construct(
        string $message,
        int $line = 0,
        string $file = '',
        ?Throwable $previous = null
    ) {
        $fullMessage = $message;
        
        if ($file !== '' && $line > 0) {
            $fullMessage = "Parse error in {$file} at line {$line}: {$message}";
        } elseif ($file !== '') {
            $fullMessage = "Parse error in {$file}: {$message}";
        }

        parent::__construct($fullMessage, 0, $previous);
        
        $this->line = $line;
        $this->file = $file;
    }
}
