<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Supplies the current asset version before the application handler runs.
 *
 * Returning null delegates version resolution to the handler and enables the
 * backward-compatible late mismatch check.
 */
interface InertiaVersionProviderInterface
{
    public function currentVersion(ServerRequestInterface $request): ?string;
}
