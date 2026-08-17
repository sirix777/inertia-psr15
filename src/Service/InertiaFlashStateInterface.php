<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Closure;

/**
 * Public extension contract for connecting optional flash storage.
 */
interface InertiaFlashStateInterface
{
    /** @param Closure(): array<string, mixed> $resolver */
    public function setFlashResolver(Closure $resolver): void;

    /** @return array<string, mixed> */
    public function pendingFlash(): array;

    /** Consume incoming flash without adding it to a Page response. */
    public function consumeIncomingFlash(): void;

    /** Whether this request's Inertia service has produced a Page response. */
    public function hasRenderedPage(): bool;
}
