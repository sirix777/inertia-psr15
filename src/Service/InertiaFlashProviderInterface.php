<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Storage-neutral flash lifecycle port.
 */
interface InertiaFlashProviderInterface
{
    /** @return array<string, mixed> */
    public function pull(ServerRequestInterface $request): array;

    /** @param array<string, mixed> $flash */
    public function persist(ServerRequestInterface $request, array $flash): void;

    public function preserve(ServerRequestInterface $request): void;
}
