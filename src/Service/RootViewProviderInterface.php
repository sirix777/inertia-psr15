<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Sirix\InertiaPsr15\Model\Page;

interface RootViewProviderInterface
{
    public function __invoke(Page $page): string;
}
