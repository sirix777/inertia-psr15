<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Twig;

use JsonException;
use Sirix\InertiaPsr15\Model\Page;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

use function htmlspecialchars;
use function json_encode;

class InertiaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('inertia', $this->inertia(...))];
    }

    /**
     * @throws JsonException
     */
    public function inertia(Page $page): Markup
    {
        return new Markup(
            '<div id="app" data-page="'
            . htmlspecialchars(
                json_encode($page, JSON_THROW_ON_ERROR),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '"></div>',
            'UTF-8'
        );
    }
}
