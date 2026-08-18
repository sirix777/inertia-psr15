<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Factory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Factory\InertiaMiddlewareFactory;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashProviderInterface;
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
            [InertiaFlashProviderInterface::class, false],
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
            [InertiaFlashProviderInterface::class, false],
        ]);
        $container->method('get')->willReturnMap([
            [InertiaFactoryInterface::class, $inertiaFactory],
            [InertiaVersionProviderInterface::class, $versionProvider],
        ]);

        self::assertInstanceOf(InertiaMiddleware::class, (new InertiaMiddlewareFactory())($container));
    }

    public function testCreatesMiddlewareWithTheOptionalFlashProvider(): void
    {
        $inertiaFactory = $this->createMock(InertiaFactoryInterface::class);
        $flashProvider  = $this->createMock(InertiaFlashProviderInterface::class);
        $container      = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [InertiaFactoryInterface::class, true],
            [InertiaVersionProviderInterface::class, false],
            [InertiaFlashProviderInterface::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [InertiaFactoryInterface::class, $inertiaFactory],
            [InertiaFlashProviderInterface::class, $flashProvider],
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

        try {
            (new InertiaMiddlewareFactory())($container);
            self::fail('Expected the factory to reject an invalid version provider.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidContainerServiceException::class, $previous);
            self::assertStringContainsString(InertiaVersionProviderInterface::class, $previous->getMessage());
            self::assertStringContainsString(InertiaMiddlewareFactory::class, $previous->getMessage());
        }
    }

    public function testFailsWhenTheOptionalFlashProviderHasTheWrongType(): void
    {
        $inertiaFactory = $this->createMock(InertiaFactoryInterface::class);
        $container      = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [InertiaFactoryInterface::class, true],
            [InertiaVersionProviderInterface::class, false],
            [InertiaFlashProviderInterface::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [InertiaFactoryInterface::class, $inertiaFactory],
            [InertiaFlashProviderInterface::class, new stdClass()],
        ]);

        try {
            (new InertiaMiddlewareFactory())($container);
            self::fail('Expected the factory to reject an invalid flash provider.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidContainerServiceException::class, $previous);
            self::assertStringContainsString(InertiaFlashProviderInterface::class, $previous->getMessage());
            self::assertStringContainsString(InertiaMiddlewareFactory::class, $previous->getMessage());
        }
    }

    public function testFailsWithContextWhenTheInertiaFactoryServiceIsMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(InertiaFactoryInterface::class)->willReturn(false);

        try {
            (new InertiaMiddlewareFactory())($container);
            self::fail('Expected the factory to require an Inertia factory service.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(MissingContainerServiceException::class, $previous);
            self::assertStringContainsString(InertiaMiddlewareFactory::class, $previous->getMessage());
        }
    }

    public function testFailsWithContextWhenTheInertiaFactoryServiceHasTheWrongType(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(InertiaFactoryInterface::class)->willReturn(true);
        $container->method('get')->with(InertiaFactoryInterface::class)->willReturn(new stdClass());

        try {
            (new InertiaMiddlewareFactory())($container);
            self::fail('Expected the factory to reject an invalid Inertia factory service.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidContainerServiceException::class, $previous);
            self::assertStringContainsString(InertiaFactoryInterface::class, $previous->getMessage());
            self::assertStringContainsString(InertiaMiddlewareFactory::class, $previous->getMessage());
        }
    }
}
