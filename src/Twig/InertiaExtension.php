<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

use function htmlspecialchars;
use function is_string;
use function json_encode;

class InertiaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('inertia', $this->inertia(...))];
    }

    /**
     * @param array<string, mixed>|string $page
     */
    public function inertia(array|string $page): Markup
    {
        $json = is_string($page) ? $page : (string) json_encode($page);

        // htmlspecialchars expects string; ensure $json is string and specify encoding explicitly
        return new Markup('<div id="app" data-page="' . htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></div>', 'UTF-8');
    }
}
