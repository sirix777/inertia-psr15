<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Twig;

use Psr\Container\ContainerInterface;

class InertiaExtensionFactory
{
    public function __invoke(ContainerInterface $container): InertiaExtension
    {
        return new InertiaExtension();
    }
}
