<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Exception;

use RuntimeException;
use Throwable;

use function preg_replace;
use function sprintf;

class InertiaPropResolutionException extends RuntimeException implements InertiaExceptionInterface
{
    private readonly string $path;

    public function __construct(string $path, Throwable $previous)
    {
        $this->path = $this->normalizePath($path);

        parent::__construct(sprintf('Unable to resolve the Inertia prop "%s".', $this->path), previous: $previous);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function normalizePath(string $path): string
    {
        return preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $path) ?? '';
    }
}
