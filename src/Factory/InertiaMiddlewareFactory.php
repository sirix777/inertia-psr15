<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\ContainerResolver\Exception\ContainerResolverException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashProviderInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;

class InertiaMiddlewareFactory
{
    /**
     * @throws InertiaContainerException when a required service cannot be resolved
     */
    public function __invoke(ContainerInterface $container): InertiaMiddleware
    {
        try {
            $resolver = ContainerResolver::forFactory($container, self::class);

            return new InertiaMiddleware(
                $resolver->get(InertiaFactoryInterface::class),
                versionProvider: $resolver->optional(InertiaVersionProviderInterface::class),
                flashProvider: $resolver->optional(InertiaFlashProviderInterface::class),
            );
        } catch (ContainerExceptionInterface|ContainerResolverException $exception) {
            throw new InertiaContainerException('Unable to resolve Inertia middleware services.', $exception->getCode(), previous: $exception);
        }
    }
}
