<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ServerRequestInterface as Request;

interface InertiaFactoryInterface
{
    public function fromRequest(Request $request): InertiaInterface;
}
