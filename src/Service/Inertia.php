<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Service;

use Closure;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\InertiaPsr15\Model\LazyProp;
use Sirix\InertiaPsr15\Model\Page;

use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_map;
use function array_values;
use function array_walk_recursive;
use function count;
use function explode;
use function json_encode;
use function trim;

class Inertia implements InertiaInterface
{
    private readonly RootViewProviderInterface $rootViewProvider;
    private Page $page;

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        RootViewProviderInterface $rootViewProvider
    ) {
        $this->rootViewProvider = $rootViewProvider;
        $this->page = Page::create();
    }

    /**
     * @param array<string, mixed> $props
     *
     * @throws JsonException
     */
    public function render(string $component, array $props = [], ?string $url = null): ResponseInterface
    {
        $this->page = $this->page
            ->withComponent($component)
            ->withUrl($url ?? (string) $this->request->getUri())
        ;

        if ($this->request->hasHeader('X-Inertia-Partial-Data')) {
            $only = explode(',', $this->request->getHeaderLine('X-Inertia-Partial-Data'));
            // Normalize: trim and remove empty entries to avoid always-true comparisons and handle empty header correctly
            $only = array_values(array_filter(array_map(static fn (string $v): string => trim($v), $only), static fn (string $v): bool => '' !== $v));
            $props = ((count($only) > 0) && $this->request->getHeaderLine('X-Inertia-Partial-Component') === $component)
            ? array_intersect_key($props, array_flip($only))
            : $props;
        } else {
            $props = array_filter($props, fn ($prop) => ! $prop instanceof LazyProp);
        }

        array_walk_recursive($props, function(&$prop) {
            if ($prop instanceof Closure || $prop instanceof LazyProp) {
                $prop = $prop();
            }
        });

        $this->page = $this->page->withProps($props);

        if ($this->request->hasHeader('X-Inertia')) {
            $json = json_encode($this->page, JSON_THROW_ON_ERROR);

            return $this->createResponse($json, 'application/json');
        }

        $rootViewProvider = $this->rootViewProvider;
        $html = $rootViewProvider($this->page);

        return $this->createResponse($html, 'text/html; charset=UTF-8');
    }

    public function version(string $version): void
    {
        $this->page = $this->page->withVersion($version);
    }

    public function share(string $key, mixed $value = null): void
    {
        $this->page = $this->page->addProp($key, $value);
    }

    public function getVersion(): ?string
    {
        return $this->page->getVersion();
    }

    public static function lazy(callable $callable): LazyProp
    {
        return new LazyProp($callable);
    }

    public function location(ResponseInterface|string $destination, int $status = 302): ResponseInterface
    {
        $response = $this->createResponse('', 'text/html; charset=UTF-8');

        // We check if InertiaMiddleware has set up the 'X-Inertia-Location' header, so we handle the response accordingly
        if ($this->request->hasHeader('X-Inertia')) {
            $response = $response->withStatus(409);

            return $response->withHeader(
                'X-Inertia-Location',
                $destination instanceof ResponseInterface ? $destination->getHeaderLine('Location') : $destination
            );
        }

        if ($destination instanceof ResponseInterface) {
            return $destination;
        }

        $response = $response->withStatus($status);

        return $response->withHeader('Location', $destination);
    }

    private function createResponse(string $data, string $contentType): ResponseInterface
    {
        $stream = $this->streamFactory->createStream($data);

        return $this->responseFactory->createResponse()
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType)
        ;
    }
}
