<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * The `cirrux` config cannot be used as it is. Thrown while the app boots, and the message names the key.
 */
final class InvalidConfigurationException extends CirruxException
{
    public static function forKey(string $key, string $problem): self
    {
        return new self(sprintf('Invalid Cirrux configuration: "%s" %s.', $key, $problem));
    }
}
