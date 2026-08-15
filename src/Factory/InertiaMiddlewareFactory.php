<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;

class InertiaMiddlewareFactory
{
    /**
     * @throws ContainerExceptionInterface      when the underlying container fails to resolve the service
     * @throws InvalidContainerServiceException when the resolved service has an unexpected type
     * @throws MissingContainerServiceException when the service is not registered
     */
    public function __invoke(ContainerInterface $container): InertiaMiddleware
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        return new InertiaMiddleware(
            $resolver->get(InertiaFactoryInterface::class),
            versionProvider: $resolver->optional(InertiaVersionProviderInterface::class),
        );
    }
}
