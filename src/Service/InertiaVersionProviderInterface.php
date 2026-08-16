<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Supplies the current asset version before the application handler runs.
 *
 * The provider is the only source of the page version. It must return a
 * non-blank header-safe version or null. Returning null means
 * the application does not use asset versioning: the page is rendered with a
 * null version and no mismatch check is performed.
 */
interface InertiaVersionProviderInterface
{
    public function currentVersion(ServerRequestInterface $request): ?string;
}
