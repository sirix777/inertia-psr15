<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Factory;

use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\ContainerResolver\Exception\ConfigReaderException;
use Sirix\ContainerResolver\Exception\ContainerResolverException;
use Sirix\InertiaPsr15\Exception\InertiaConfigurationException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\View\RootViewProviderDecorator;

class RootViewProviderFactory
{
    /**
     * @throws InertiaConfigurationException when root-view configuration is invalid
     * @throws InertiaContainerException     when a required service cannot be resolved
     */
    public function __invoke(ContainerInterface $container): RootViewProviderDecorator
    {
        try {
            $resolver         = ContainerResolver::forFactory($container, self::class);
            $templateRenderer = $resolver->get(TemplateRendererInterface::class);
            $rootView         = ConfigReader::fromContainer($resolver)->nonEmptyString('inertia_psr15.root_view', 'app.html.twig');

            $callback = (static fn (string $template, array $params): string => $templateRenderer->render($template, $params));

            return new RootViewProviderDecorator($callback, $rootView);
        } catch (ConfigReaderException $exception) {
            throw new InertiaConfigurationException('Unable to read the Inertia root-view configuration.', $exception->getCode(), previous: $exception);
        } catch (ContainerExceptionInterface|ContainerResolverException $exception) {
            throw new InertiaContainerException('Unable to resolve Inertia root-view services.', $exception->getCode(), previous: $exception);
        }
    }
}
