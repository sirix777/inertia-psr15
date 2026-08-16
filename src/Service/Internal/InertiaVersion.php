<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service\Internal;

use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;

use function preg_match;
use function strlen;
use function trim;

final class InertiaVersion
{
    public static function assertValid(string $version): void
    {
        if ('' === trim($version) || 8192 < strlen($version) || 1 === preg_match('/[\x00-\x1F\x7F]/', $version)) {
            throw new InvalidInertiaArgumentException('Inertia version must be a non-empty header-safe value.');
        }
    }
}
