<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Factory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\InertiaPsr15\Factory\InertiaMiddlewareFactory;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;
use stdClass;

class InertiaMiddlewareFactoryTest extends TestCase
{
    public function testCreatesMiddlewareFromTheInertiaFactoryService(): void
    {
        $inertiaFactory = $this->createMock(InertiaFactoryInterface::class);
        $container      = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [InertiaFactoryInterface::class, true],
            [InertiaVersionProviderInterface::class, false],
        ]);
        $container->method('get')->with(InertiaFactoryInterface::class)->willReturn($inertiaFactory);

        self::assertInstanceOf(InertiaMiddleware::class, (new InertiaMiddlewareFactory())($container));
    }

    public function testCreatesMiddlewareWithTheOptionalVersionProvider(): void
    {
        $inertiaFactory  = $this->createMock(InertiaFactoryInterface::class);
        $versionProvider = $this->createMock(InertiaVersionProviderInterface::class);
        $container       = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [InertiaFactoryInterface::class, true],
            [InertiaVersionProviderInterface::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [InertiaFactoryInterface::class, $inertiaFactory],
            [InertiaVersionProviderInterface::class, $versionProvider],
        ]);

        self::assertInstanceOf(InertiaMiddleware::class, (new InertiaMiddlewareFactory())($container));
    }

    public function testFailsWhenTheOptionalVersionProviderHasTheWrongType(): void
    {
        $inertiaFactory = $this->createMock(InertiaFactoryInterface::class);
        $container      = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [InertiaFactoryInterface::class, true],
            [InertiaVersionProviderInterface::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [InertiaFactoryInterface::class, $inertiaFactory],
            [InertiaVersionProviderInterface::class, new stdClass()],
        ]);

        $this->expectException(InvalidContainerServiceException::class);
        $this->expectExceptionMessage(InertiaVersionProviderInterface::class);
        $this->expectExceptionMessage(InertiaMiddlewareFactory::class);

        (new InertiaMiddlewareFactory())($container);
    }

    public function testFailsWithContextWhenTheInertiaFactoryServiceIsMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(InertiaFactoryInterface::class)->willReturn(false);

        $this->expectException(MissingContainerServiceException::class);
        $this->expectExceptionMessage(InertiaMiddlewareFactory::class);

        (new InertiaMiddlewareFactory())($container);
    }

    public function testFailsWithContextWhenTheInertiaFactoryServiceHasTheWrongType(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(InertiaFactoryInterface::class)->willReturn(true);
        $container->method('get')->with(InertiaFactoryInterface::class)->willReturn(new stdClass());

        $this->expectException(InvalidContainerServiceException::class);
        $this->expectExceptionMessage(InertiaFactoryInterface::class);
        $this->expectExceptionMessage(InertiaMiddlewareFactory::class);

        (new InertiaMiddlewareFactory())($container);
    }
}
