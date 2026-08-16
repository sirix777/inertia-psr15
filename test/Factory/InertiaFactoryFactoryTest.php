<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Factory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Factory\InertiaFactoryFactory;
use Sirix\InertiaPsr15\Service\InertiaFactory;
use Sirix\InertiaPsr15\View\RootViewProviderInterface;
use stdClass;

class InertiaFactoryFactoryTest extends TestCase
{
    public function testCreatesAnInertiaFactoryFromRequiredServices(): void
    {
        $responseFactory   = $this->createMock(ResponseFactoryInterface::class);
        $streamFactory     = $this->createMock(StreamFactoryInterface::class);
        $rootViewProvider  = $this->createMock(RootViewProviderInterface::class);
        $container         = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            [ResponseFactoryInterface::class, $responseFactory],
            [StreamFactoryInterface::class, $streamFactory],
            [RootViewProviderInterface::class, $rootViewProvider],
        ]);

        self::assertInstanceOf(InertiaFactory::class, (new InertiaFactoryFactory())($container));
    }

    public function testFailsWithContextWhenARequiredServiceIsMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        try {
            (new InertiaFactoryFactory())($container);
            self::fail('Expected the factory to require all services.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(MissingContainerServiceException::class, $previous);
            self::assertStringContainsString(InertiaFactoryFactory::class, $previous->getMessage());
        }
    }

    public function testFailsWithContextWhenARequiredServiceHasTheWrongType(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn(new stdClass());

        try {
            (new InertiaFactoryFactory())($container);
            self::fail('Expected the factory to reject an invalid response factory service.');
        } catch (InertiaContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidContainerServiceException::class, $previous);
            self::assertStringContainsString(ResponseFactoryInterface::class, $previous->getMessage());
            self::assertStringContainsString(InertiaFactoryFactory::class, $previous->getMessage());
        }
    }

    public function testPropagatesContainerResolutionFailures(): void
    {
        $exception = new class('Resolution failed') extends RuntimeException implements ContainerExceptionInterface {};
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willThrowException($exception);

        try {
            (new InertiaFactoryFactory())($container);
            self::fail('Expected the factory to wrap the container failure.');
        } catch (InertiaContainerException $caught) {
            self::assertSame($exception, $caught->getPrevious());
        }
    }
}
