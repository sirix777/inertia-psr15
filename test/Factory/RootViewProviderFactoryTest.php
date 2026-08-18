<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Factory;

use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\Exception\InvalidConfigValueException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\InertiaPsr15\Exception\InertiaConfigurationException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Factory\RootViewProviderFactory;
use Sirix\InertiaPsr15\Model\Page;

class RootViewProviderFactoryTest extends TestCase
{
    public function testUsesTheConfiguredRootView(): void
    {
        $renderer  = $this->createMock(TemplateRendererInterface::class);
        $renderer
            ->expects(self::once())
            ->method('render')
            ->with('inertia/app.html.twig', [
                'page' => Page::from('Dashboard'),
            ])
            ->willReturn('<html></html>')
        ;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [TemplateRendererInterface::class, true],
            ['config', true],
        ]);
        $container->method('get')->willReturnMap([
            [TemplateRendererInterface::class, $renderer],
            [
                'config', [
                    'inertia_psr15' => [
                        'root_view' => ' inertia/app.html.twig ',
                    ],
                ]],
        ]);

        $provider = (new RootViewProviderFactory())($container);

        self::assertSame('<html></html>', $provider(Page::from('Dashboard')));
    }

    public function testUsesTheDefaultRootViewWhenConfigurationIsMissing(): void
    {
        $renderer  = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('app.html.twig', self::isType('array'))->willReturn('<html></html>');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [TemplateRendererInterface::class, true],
            ['config', false],
        ]);
        $container->method('get')->with(TemplateRendererInterface::class)->willReturn($renderer);

        self::assertSame('<html></html>', (new RootViewProviderFactory())($container)(Page::from('Dashboard')));
    }

    public function testUsesTheDefaultRootViewWhenTheRootViewConfigurationIsMissing(): void
    {
        $renderer  = $this->createMock(TemplateRendererInterface::class);
        $renderer->expects(self::once())->method('render')->with('app.html.twig', self::isType('array'))->willReturn('<html></html>');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            [TemplateRendererInterface::class, $renderer],
            [
                'config', [
                    'inertia_psr15' => [],
                ]],
        ]);

        self::assertSame('<html></html>', (new RootViewProviderFactory())($container)(Page::from('Dashboard')));
    }

    public function testRejectsAConfigurationServiceThatIsNotAnArray(): void
    {
        $renderer  = $this->createMock(TemplateRendererInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            [TemplateRendererInterface::class, $renderer],
            ['config', 'invalid'],
        ]);

        try {
            (new RootViewProviderFactory())($container);
            self::fail('Expected the factory to reject a non-array configuration service.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidContainerServiceException::class, $previous);
            self::assertStringContainsString('config', $previous->getMessage());
            self::assertStringContainsString(RootViewProviderFactory::class, $previous->getMessage());
        }
    }

    #[DataProvider('invalidRootViewValues')]
    public function testRejectsAnInvalidRootViewConfiguration(mixed $rootView): void
    {
        $renderer  = $this->createMock(TemplateRendererInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            [TemplateRendererInterface::class, $renderer],
            [
                'config', [
                    'inertia_psr15' => [
                        'root_view' => $rootView,
                    ],
                ],
            ],
        ]);

        try {
            (new RootViewProviderFactory())($container);
            self::fail('Expected the factory to reject an invalid root-view configuration.');
        } catch (InertiaConfigurationException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidConfigValueException::class, $previous);
            self::assertStringContainsString('inertia_psr15.root_view', $previous->getMessage());
            self::assertStringContainsString(RootViewProviderFactory::class, $previous->getMessage());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRootViewValues(): iterable
    {
        yield 'empty string' => [''];

        yield 'wrong type' => [123];
    }
}
