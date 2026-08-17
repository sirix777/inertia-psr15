<?php

declare(strict_types=1);

namespace Sirix\InertiaPsr15\Middleware;

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Sirix\InertiaPsr15\Exception\InertiaFlashException;
use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Exception\InertiaVersionException;
use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;
use Sirix\InertiaPsr15\Exception\MissingFlashProviderException;
use Sirix\InertiaPsr15\Exception\UnsupportedInertiaImplementationException;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashProviderInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashStateInterface;
use Sirix\InertiaPsr15\Service\InertiaInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;
use Sirix\InertiaPsr15\Service\Internal\InertiaVersion;
use Throwable;

use function explode;
use function implode;
use function in_array;
use function json_encode;
use function ltrim;
use function preg_match;
use function str_contains;
use function strcasecmp;
use function strlen;
use function trim;

class InertiaMiddleware implements MiddlewareInterface
{
    public const INERTIA_ATTRIBUTE = 'inertia';

    /**
     * InertiaMiddleware constructor.
     */
    public function __construct(
        private readonly InertiaFactoryInterface $inertiaFactory,
        private readonly string $attributeKey = self::INERTIA_ATTRIBUTE,
        private readonly ?InertiaVersionProviderInterface $versionProvider = null,
        private readonly ?InertiaFlashProviderInterface $flashProvider = null
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        try {
            $currentVersion = $this->versionProvider?->currentVersion($request);
        } catch (Throwable $exception) {
            if ($exception instanceof InertiaVersionException) {
                throw $exception;
            }

            throw new InertiaVersionException($exception);
        }
        if (null !== $currentVersion) {
            InertiaVersion::assertValid($currentVersion);
        }

        $inertia = $this->inertiaFactory->fromRequest($request, $currentVersion);
        $this->connectFlashProvider($request, $inertia);

        $request = $request->withAttribute($this->attributeKey, $inertia);

        if (null !== $currentVersion && $this->versionMismatch($request, $currentVersion)) {
            $response = $inertia->location($this->requestLocation($request))
                ->withHeader('X-Inertia-Version', $currentVersion)
                ->withoutHeader('X-Inertia')
            ;

            return $this->withInertiaVary($this->finalizeFlash($request, $response, $inertia));
        }

        $response = $handler->handle($request);

        $response = $this->withInertiaVary($response);

        if (! $request->hasHeader('X-Inertia')) {
            return $this->finalizeFlash($request, $response, $inertia);
        }

        $response = $response->withAddedHeader('X-Inertia', 'true');

        return $this->finalizeFlash($request, $this->changeRedirectCode($request, $response), $inertia);
    }

    private function connectFlashProvider(Request $request, InertiaInterface $inertia): void
    {
        if (! $this->flashProvider instanceof InertiaFlashProviderInterface) {
            return;
        }

        if (! $inertia instanceof InertiaFlashStateInterface) {
            throw new UnsupportedInertiaImplementationException(
                'The configured flash provider requires an Inertia implementation with flash state support.'
            );
        }

        $inertia->setFlashResolver(fn (): array => $this->flashProvider->pull($request));
    }

    private function finalizeFlash(Request $request, Response $response, InertiaInterface $inertia): Response
    {
        if (! $inertia instanceof InertiaFlashStateInterface) {
            return $response;
        }

        $control  = $this->isFlashControlResponse($response);
        $redirect = $this->isRedirectResponse($response);
        if ($redirect && ! $control) {
            $inertia->consumeIncomingFlash();
        }

        if ($redirect || $control) {
            $this->persistPendingFlash($request, $inertia->pendingFlash());
        }

        if ($control && $this->flashProvider instanceof InertiaFlashProviderInterface) {
            $this->preserveFlash($request);
        }

        if (! $redirect && ! $control && [] !== $inertia->pendingFlash() && ! $inertia->hasRenderedPage()) {
            throw new InvalidInertiaArgumentException(
                'Pending Inertia flash data requires a rendered Page response or a redirect response.'
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $flash */
    private function persistPendingFlash(Request $request, array $flash): void
    {
        if ([] === $flash) {
            return;
        }

        if (! $this->flashProvider instanceof InertiaFlashProviderInterface) {
            throw new MissingFlashProviderException('Cannot persist flash data for a redirect without an Inertia flash provider.');
        }

        try {
            json_encode($flash, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            if ($exception instanceof InertiaSerializationException) {
                throw $exception;
            }

            throw new InertiaSerializationException(
                'Unable to serialize pending Inertia flash data.',
                $exception->getCode(),
                previous: $exception
            );
        }

        try {
            $this->flashProvider->persist($request, $flash);
        } catch (Throwable $exception) {
            if ($exception instanceof InertiaFlashException) {
                throw $exception;
            }

            throw new InertiaFlashException('persist', $exception);
        }
    }

    private function preserveFlash(Request $request): void
    {
        try {
            $this->flashProvider?->preserve($request);
        } catch (Throwable $exception) {
            if ($exception instanceof InertiaFlashException) {
                throw $exception;
            }

            throw new InertiaFlashException('preserve', $exception);
        }
    }

    private function isRedirectResponse(Response $response): bool
    {
        return StatusCodeInterface::STATUS_MULTIPLE_CHOICES <= $response->getStatusCode()
            && StatusCodeInterface::STATUS_BAD_REQUEST > $response->getStatusCode()
            && $response->hasHeader('Location');
    }

    private function isFlashControlResponse(Response $response): bool
    {
        return StatusCodeInterface::STATUS_CONFLICT === $response->getStatusCode()
            && ($response->hasHeader('X-Inertia-Location') || $response->hasHeader('X-Inertia-Redirect'));
    }

    private function versionMismatch(Request $request, ?string $currentVersion): bool
    {
        return null !== $currentVersion
            && $request->hasHeader('X-Inertia')
            && RequestMethodInterface::METHOD_GET === $request->getMethod()
            && ($request->getHeader('X-Inertia-Version')[0] ?? '') !== $currentVersion;
    }

    private function changeRedirectCode(Request $request, Response $response): Response
    {
        if (! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        if (
            StatusCodeInterface::STATUS_FOUND === $response->getStatusCode()
            && in_array($request->getMethod(), [RequestMethodInterface::METHOD_PUT, RequestMethodInterface::METHOD_PATCH, RequestMethodInterface::METHOD_DELETE])
        ) {
            $response = $response->withStatus(StatusCodeInterface::STATUS_SEE_OTHER);
        }

        if (
            StatusCodeInterface::STATUS_MULTIPLE_CHOICES <= $response->getStatusCode()
            && StatusCodeInterface::STATUS_BAD_REQUEST > $response->getStatusCode()
            && $response->hasHeader('Location')
            && 'prefetch' !== $request->getHeaderLine('Purpose')
        ) {
            $location = $response->getHeaderLine('Location');
            if (str_contains($location, '#') && $this->isSafeRedirectLocation($location)) {
                return $response
                    ->withStatus(StatusCodeInterface::STATUS_CONFLICT)
                    ->withHeader('X-Inertia-Redirect', $location)
                    ->withoutHeader('Location')
                ;
            }
        }

        // For External redirects
        // https://inertiajs.com/redirects#external-redirects
        if (
            StatusCodeInterface::STATUS_CONFLICT === $response->getStatusCode()
            && $response->hasHeader('X-Inertia-Location')
        ) {
            return $response->withoutHeader('X-Inertia');
        }

        return $response;
    }

    private function withInertiaVary(Response $response): Response
    {
        $tokens = [];
        foreach (explode(',', $response->getHeaderLine('Vary')) as $token) {
            $token = trim($token);
            if ('' !== $token) {
                $tokens[] = $token;
            }
        }

        foreach ($tokens as $token) {
            if (0 === strcasecmp($token, 'X-Inertia')) {
                return $response;
            }
        }

        if ([] === $tokens) {
            return $response->withAddedHeader('Vary', 'X-Inertia');
        }

        $tokens[] = 'X-Inertia';

        return $response->withHeader('Vary', implode(', ', $tokens));
    }

    private function isSafeRedirectLocation(string $location): bool
    {
        return '' !== $location
            && 8192 >= strlen($location)
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $location);
    }

    private function requestLocation(Request $request): string
    {
        $uri      = $request->getUri();
        $path     = '/' . ltrim($uri->getPath(), '/\\');
        $query    = $uri->getQuery();
        $location = $path . ('' === $query ? '' : '?' . $query);

        if (8192 < strlen($location) || str_contains($location, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $location)) {
            return '/';
        }

        return $location;
    }
}
