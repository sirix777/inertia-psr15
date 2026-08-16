<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\ContainerResolver\Exception\ContainerResolverException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Service\InertiaFactory;
use Sirix\InertiaPsr15\View\RootViewProviderInterface;

class InertiaFactoryFactory
{
    /**
     * @throws InertiaContainerException when a required service cannot be resolved
     */
    public function __invoke(ContainerInterface $container): InertiaFactory
    {
        try {
            $resolver = ContainerResolver::forFactory($container, self::class);

            return new InertiaFactory(
                $resolver->get(ResponseFactoryInterface::class),
                $resolver->get(StreamFactoryInterface::class),
                $resolver->get(RootViewProviderInterface::class),
            );
        } catch (ContainerExceptionInterface|ContainerResolverException $exception) {
            throw new InertiaContainerException('Unable to resolve Inertia factory services.', $exception->getCode(), previous: $exception);
        }
    }
}
