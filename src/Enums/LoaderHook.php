<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Enums;

/**
 * Supported lifecycle hooks in the Loader.
 */
enum LoaderHook: string
{
    /**
     * Fired at the beginning of load() before any processing.
     * Arguments: (array $names)
     */
    case Loading = 'onLoading';

    /**
     * Fired right before load() returns the final Config instance.
     * Arguments: (Config $config)
     */
    case Loaded = 'onLoaded';

    /**
     * Fired when a validator returns structural errors.
     * Arguments: (array $errors, array $data)
     */
    case ValidationError = 'onValidationError';
}
