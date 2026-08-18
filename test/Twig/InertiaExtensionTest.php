<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Twig;

use Error;
use JsonException;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Model\Page;
use Sirix\InertiaPsr15\Twig\InertiaExtension;
use Throwable;

use function substr_count;

class InertiaExtensionTest extends TestCase
{
    public function testRendersTheV3InitialPageFormat(): void
    {
        $markup = (new InertiaExtension())->inertia(Page::from('Dashboard', [
            'title' => '<Dashboard>',
        ], '/'));

        $this->assertSame(
            '<script data-page="app" type="application/json">{"component":"Dashboard","props":{"title":"\u003CDashboard\u003E"},"url":"\/","version":null}</script><div id="app"></div>',
            (string) $markup
        );
    }

    public function testEscapesScriptBreakoutPayloadsInTheInitialPageJson(): void
    {
        $markup = (string) (new InertiaExtension())->inertia(Page::from('Dashboard', [
            'payload' => '</script><img src=x onerror=alert(1)>',
        ], '/'));

        self::assertStringNotContainsString('</script><img', $markup);
        self::assertStringContainsString('\u003C\/script\u003E', $markup);
        self::assertSame(1, substr_count($markup, '</script>'));
    }

    public function testPreservesUnicodeAndSubstitutesInvalidUtf8(): void
    {
        $markup = (string) (new InertiaExtension())->inertia(Page::from('Dashboard', [
            'title' => 'Привет 👋',
        ], '/'));

        self::assertStringContainsString('\u041f\u0440\u0438\u0432\u0435\u0442', $markup);

        $markup = (string) (new InertiaExtension())->inertia(Page::from('Dashboard', [
            'invalid' => "\xB1\x31",
        ], '/'));

        self::assertStringContainsString('"invalid":"\ufffd1"', $markup);
    }

    public function testWrapsJsonEncodingFailuresWithTheOriginalException(): void
    {
        $recursive         = [];
        $recursive['self'] = &$recursive;

        try {
            (new InertiaExtension())->inertia(Page::from('Dashboard', [
                'recursive' => $recursive,
            ], '/'));
            self::fail('Expected page serialization to fail.');
        } catch (InertiaSerializationException $exception) {
            self::assertSame('Unable to serialize the Inertia page.', $exception->getMessage());
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
            self::assertSame(JSON_ERROR_RECURSION, $exception->getPrevious()->getCode());
        }
    }

    public function testWrapsJsonSerializableFailuresWithTheExactThrowable(): void
    {
        foreach ([new RuntimeException('serialization failed'), new Error('serialization error')] as $original) {
            $value = new class($original) implements JsonSerializable {
                public function __construct(private readonly Throwable $exception) {}

                public function jsonSerialize(): mixed
                {
                    throw $this->exception;
                }
            };

            try {
                (new InertiaExtension())->inertia(Page::from('Dashboard', [
                    'value' => $value,
                ], '/'));
                self::fail('JsonSerializable failures must be wrapped.');
            } catch (InertiaSerializationException $exception) {
                self::assertSame($original, $exception->getPrevious());
            }
        }
    }
}
