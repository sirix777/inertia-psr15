<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Factory;

use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\InertiaPsr15\Service\RootViewProviderDecorator;

class RootViewProviderFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): RootViewProviderDecorator
    {
        $templateRenderer = $container->get(TemplateRendererInterface::class);

        $callback = (static fn (string $template, array $params): string => $templateRenderer->render($template, $params));

        return new RootViewProviderDecorator($callback, 'app.html.twig');
    }
}
