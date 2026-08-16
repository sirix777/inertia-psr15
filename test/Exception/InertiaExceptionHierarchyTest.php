<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\InertiaPsr15\Exception\InertiaConfigurationException;
use Sirix\InertiaPsr15\Exception\InertiaContainerException;
use Sirix\InertiaPsr15\Exception\InertiaExceptionInterface;
use Sirix\InertiaPsr15\Exception\InertiaFlashException;
use Sirix\InertiaPsr15\Exception\InertiaPropResolutionException;
use Sirix\InertiaPsr15\Exception\InertiaRenderingException;
use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;
use Sirix\InertiaPsr15\Exception\MissingFlashProviderException;
use Sirix\InertiaPsr15\Exception\MissingInertiaConfigException;
use Sirix\InertiaPsr15\Exception\UnsupportedInertiaImplementationException;
use Throwable;

class InertiaExceptionHierarchyTest extends TestCase
{
    #[DataProvider('packageExceptions')]
    public function testAllPackageExceptionsImplementTheMarkerInterface(Throwable $exception): void
    {
        self::assertInstanceOf(InertiaExceptionInterface::class, $exception);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function packageExceptions(): iterable
    {
        yield 'invalid argument' => [new InvalidInertiaArgumentException()];

        yield 'configuration' => [new InertiaConfigurationException()];

        yield 'missing configuration' => [new MissingInertiaConfigException()];

        yield 'missing flash provider' => [new MissingFlashProviderException()];

        yield 'unsupported implementation' => [new UnsupportedInertiaImplementationException()];

        yield 'prop resolution' => [new InertiaPropResolutionException('user.name', new RuntimeException())];

        yield 'flash' => [new InertiaFlashException('pull', new RuntimeException())];

        yield 'serialization' => [new InertiaSerializationException()];

        yield 'rendering' => [new InertiaRenderingException()];

        yield 'container' => [new InertiaContainerException()];
    }

    public function testPropResolutionExceptionPreservesTheCauseWithoutLeakingItsMessage(): void
    {
        $cause     = new RuntimeException('Sensitive resolver failure');
        $exception = new InertiaPropResolutionException('user.name', $cause);

        self::assertSame($cause, $exception->getPrevious());
        self::assertStringContainsString('user.name', $exception->getMessage());
        self::assertStringNotContainsString($cause->getMessage(), $exception->getMessage());
    }

    public function testPropResolutionExceptionNormalizesControlBytesInThePath(): void
    {
        $unsafePath = "account.\r\nprofile\x00\t\x1Bname";
        $exception  = new InertiaPropResolutionException($unsafePath, new RuntimeException());

        self::assertSame('account.profilename', $exception->path());
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->path());
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->getMessage());
        self::assertStringNotContainsString($unsafePath, $exception->getMessage());
    }

    public function testFlashExceptionExposesTheOperationWithoutLeakingTheCauseMessage(): void
    {
        $cause     = new RuntimeException('Sensitive provider failure');
        $exception = new InertiaFlashException('persist', $cause);

        self::assertSame('persist', $exception->operation());
        self::assertSame($cause, $exception->getPrevious());
        self::assertStringNotContainsString($cause->getMessage(), $exception->getMessage());
    }
}
