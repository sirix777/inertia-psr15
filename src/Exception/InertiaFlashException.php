<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

class InertiaFlashException extends RuntimeException implements InertiaExceptionInterface
{
    public function __construct(private readonly string $operation, Throwable $previous)
    {
        parent::__construct(sprintf('The Inertia flash provider failed during %s.', $operation), previous: $previous);
    }

    public function operation(): string
    {
        return $this->operation;
    }
}
