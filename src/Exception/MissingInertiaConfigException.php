<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use InvalidArgumentException;

class MissingInertiaConfigException extends InvalidArgumentException
{
    public static function fromMessage(string $message): self
    {
        return new MissingInertiaConfigException($message);
    }
}
