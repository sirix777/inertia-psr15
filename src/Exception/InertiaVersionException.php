<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use RuntimeException;
use Throwable;

class InertiaVersionException extends RuntimeException implements InertiaExceptionInterface
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The Inertia version provider failed.', previous: $previous);
    }
}
