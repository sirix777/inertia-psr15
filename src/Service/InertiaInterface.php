<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Psr\Http\Message\ResponseInterface;
use Sirix\InertiaPsr15\Model\OnceProp;

interface InertiaInterface
{
    /**
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props = []): ResponseInterface;

    public function share(string $key, mixed $value = null): void;

    public function shareOnce(string $key, mixed $value): OnceProp;

    /**
     * Queue flash data for this response or the next request after a redirect.
     *
     * @param array<array-key, mixed>|string $key
     */
    public function flash(array|string $key, mixed $value = null): static;

    public function encryptHistory(bool $enabled = true): void;

    public function clearHistory(bool $enabled = true): void;

    public function preserveFragment(bool $enabled = true): void;

    /** String locations accept only 301, 302, 303, 307, or 308. */
    public function location(ResponseInterface|string $destination, int $status = 302): ResponseInterface;
}
