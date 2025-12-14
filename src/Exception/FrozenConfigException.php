<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Exception;

/**
 * Exception thrown when trying to modify a frozen configuration.
 */
class FrozenConfigException extends ConfigException
{
}
