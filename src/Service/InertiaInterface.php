<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ResponseInterface;

interface InertiaInterface
{
    /**
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props = []): ResponseInterface;

    public function version(string $version): void;

    public function share(string $key, mixed $value = null): void;

    public function getVersion(): ?string;

    public function location(ResponseInterface|string $destination, int $status = 302): ResponseInterface;
}
