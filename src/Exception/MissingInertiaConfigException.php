<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

class MissingInertiaConfigException extends InvalidInertiaArgumentException
{
    public static function fromMessage(string $message): self
    {
        return new MissingInertiaConfigException($message);
    }
}
