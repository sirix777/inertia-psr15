<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Twig;

use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Model\Page;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

use function json_encode;

class InertiaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('inertia', $this->inertia(...))];
    }

    public function inertia(Page $page): Markup
    {
        try {
            $pageJson = json_encode(
                $page,
                JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_HEX_TAG
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            if ($exception instanceof InertiaSerializationException) {
                throw $exception;
            }

            throw new InertiaSerializationException('Unable to serialize the Inertia page.', $exception->getCode(), previous: $exception);
        }

        return new Markup(
            '<script data-page="app" type="application/json">'
            . $pageJson
            . '</script><div id="app"></div>',
            'UTF-8'
        );
    }
}
