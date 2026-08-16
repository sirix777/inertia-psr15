<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Model;

use JsonException;
use PHPUnit\Framework\TestCase;
use Sirix\InertiaPsr15\Model\Page;

use function json_encode;

final class PageTest extends TestCase
{
    public function testFlashDefaultsToAnEmptyArrayAndIsOmittedFromSerialization(): void
    {
        $page = Page::from('Dashboard', [
            'title' => 'Dashboard',
        ], '/', 'version');

        self::assertSame([], $page->getFlash());
        self::assertSame([
            'component' => 'Dashboard',
            'props'     => [
                'title' => 'Dashboard',
            ],
            'url'       => '/',
            'version'   => 'version',
        ], $page->jsonSerialize());
    }

    public function testFlashIsExposedAtThePageTopLevel(): void
    {
        $page = Page::from('Dashboard', [], '/')->withFlash([
            'message' => 'Saved',
            'id'      => 42,
        ]);

        self::assertSame([
            'message' => 'Saved',
            'id'      => 42,
        ], $page->getFlash());
        self::assertSame([
            'component' => 'Dashboard',
            'props'     => [],
            'url'       => '/',
            'version'   => null,
            'flash'     => [
                'message' => 'Saved',
                'id'      => 42,
            ],
        ], $page->jsonSerialize());
    }

    public function testTopLevelFlashRemainsIndependentFromAFlashProp(): void
    {
        $page = Page::from('Dashboard', [
            'flash' => [
                'message' => 'A prop value',
            ],
        ], '/')->withFlash([
            'message' => 'A protocol value',
        ]);

        self::assertSame([
            'message' => 'A prop value',
        ], $page->getProps()['flash']);
        self::assertSame([
            'message' => 'A protocol value',
        ], $page->getFlash());
        self::assertSame([
            'flash' => [
                'message' => 'A prop value',
            ],
        ], $page->jsonSerialize()['props']);
        self::assertSame([
            'message' => 'A protocol value',
        ], $page->jsonSerialize()['flash']);
    }

    /** @throws JsonException */
    public function testJsonEncodingKeepsFlashAsANonEmptyTopLevelField(): void
    {
        $page = Page::from('Dashboard', [], '/')->withFlash([
            'message' => '<Saved>',
        ]);

        self::assertSame(
            '{"component":"Dashboard","props":[],"url":"\/","version":null,"flash":{"message":"<Saved>"}}',
            json_encode($page, JSON_THROW_ON_ERROR)
        );
    }

    /** @throws JsonException */
    public function testJsonEncodingCanSafelySubstituteInvalidUtf8InFlash(): void
    {
        $page = Page::from('Dashboard', [], '/')->withFlash([
            'message' => "Invalid \xB1",
        ]);

        self::assertSame(
            '{"component":"Dashboard","props":[],"url":"\/","version":null,"flash":{"message":"Invalid \ufffd"}}',
            json_encode($page, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
        );
    }
}
