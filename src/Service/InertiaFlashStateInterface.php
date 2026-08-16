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
}
