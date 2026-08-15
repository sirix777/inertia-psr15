<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

/**
 * @internal implemented by Inertia services that can lock a provider-owned version
 */
interface InertiaVersionProviderAwareInterface
{
    public function setVersionFromProvider(string $version): void;
}
