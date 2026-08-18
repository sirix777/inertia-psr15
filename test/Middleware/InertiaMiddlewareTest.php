<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Middleware;

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use JsonException;
use JsonSerializable;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\InertiaPsr15\Exception\InertiaFlashException;
use Sirix\InertiaPsr15\Exception\InertiaSerializationException;
use Sirix\InertiaPsr15\Exception\InertiaVersionException;
use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;
use Sirix\InertiaPsr15\Exception\MissingFlashProviderException;
use Sirix\InertiaPsr15\Exception\UnsupportedInertiaImplementationException;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Model\Page;
use Sirix\InertiaPsr15\Service\InertiaFactory;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashProviderInterface;
use Sirix\InertiaPsr15\Service\InertiaInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;
use Sirix\InertiaPsr15\View\RootViewProviderInterface;
use Throwable;

use function json_decode;
use function strtolower;
use function substr_count;

class InertiaMiddlewareTest extends TestCase
{
    public function testProcessWithoutInertiaHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(false);
        $inertia = $this->createMock(InertiaInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $inertia)->willReturn($request);

        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->with($this->identicalTo($request), null)->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->with('Vary')->willReturn('');
        $response->method('withAddedHeader')->with('Vary', 'X-Inertia')->willReturn($response);
        $handler  = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testDoesntChangeHandlerResponseWhenVersioningIsDisabled(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn(RequestMethodInterface::METHOD_GET);

        $factory->method('fromRequest')->with($this->identicalTo($request), null)->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(StatusCodeInterface::STATUS_ACCEPTED);
        $response->method('withAddedHeader')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testConfiguredVersionProviderShortCircuitsMismatchingGetBeforeTheHandler(): void
    {
        $request = new ServerRequest([], [], '/projects?tab=open', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->expects($this->once())->method('location')->with('/projects?tab=open')->willReturn(
            new Response('php://memory', StatusCodeInterface::STATUS_CONFLICT, [
                'X-Inertia'          => 'true',
                'X-Inertia-Location' => '/projects?tab=open',
            ])
        );

        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->once())->method('fromRequest')->with($request, 'current')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->expects($this->once())->method('currentVersion')->with($request)->willReturn('current');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('/projects?tab=open', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
    }

    public function testConfiguredVersionProviderUsesItsVersionInThePageResponse(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'current',
        ]);
        $rootViewProvider = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory  = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $rootViewProvider);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->with($request)->willReturn('current');
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

                return $inertia->render('Projects', [
                    'projects' => [],
                ]);
            }
        };

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        /** @var array<string, mixed> $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $handler->calls);
        self::assertSame('current', $page['version']);
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testLowercaseInertiaHeadersAreHandledCaseInsensitively(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'x-inertia'         => 'true',
            'x-inertia-version' => 'current',
        ]);
        $rootViewProvider = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory  = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $rootViewProvider);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->expects($this->once())->method('currentVersion')->with($request)->willReturn('current');
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

                return $inertia->render('Projects');
            }
        };

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(1, $handler->calls);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testVersionMismatchUsesTheFirstOfMultipleClientVersionHeaderValues(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => ['current', 'stale'],
        ]);
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->with($request)->willReturn('current');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

                return $inertia->render('Projects');
            }
        };

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    public function testMissingClientVersionCausesAMismatch(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $rootViewProvider = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory  = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $rootViewProvider);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->expects($this->once())->method('currentVersion')->with($request)->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('/projects', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testVersionProviderRejectsAnEmptyVersion(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->never())->method('fromRequest');
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(InvalidInertiaArgumentException::class);

        (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);
    }

    public function testVersionProviderRejectsAWhitespaceOnlyVersion(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->never())->method('fromRequest');
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('   ');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(InvalidInertiaArgumentException::class);

        (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);
    }

    public function testVersionProviderFailuresUseThePackageExceptionBoundary(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->never())->method('fromRequest');
        $cause    = new RuntimeException('manifest unavailable');
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willThrowException($cause);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        try {
            (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);
            self::fail('The provider failure must be wrapped.');
        } catch (InertiaVersionException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testNullVersionFromProviderDisablesVersioning(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $rootViewProvider = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory  = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $rootViewProvider);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->expects($this->once())->method('currentVersion')->with($request)->willReturn(null);
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

                return $inertia->render('Projects');
            }
        };

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        /** @var array<string, mixed> $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $handler->calls);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertNull($page['version']);
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testVersionMismatchOnAPrefetchIsStillReportedAsAControlResponse(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
            'Purpose'           => 'prefetch',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('location')->willReturn(
            (new Response())->withStatus(StatusCodeInterface::STATUS_CONFLICT)->withHeader('X-Inertia-Location', '/projects')
        );
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->with($request, 'current')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
    }

    public function testVersionMismatchDoesNotShortCircuitNonGetRequests(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->once())->method('fromRequest')->with($request, 'current')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Response());

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testVersionProviderRejectsUnsafeHeaderValuesBeforeTheHandlerRuns(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->never())->method('fromRequest');
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn("current\r\nX-Evil: true");
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(InvalidInertiaArgumentException::class);
        (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);
    }

    public function testVersionMismatchNeverUsesAnUntrustedAuthorityOrUnsafePathInItsLocationHeader(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->expects($this->once())->method('location')->with('/attacker.example/path?next=1')->willReturn(
            (new Response())->withStatus(StatusCodeInterface::STATUS_CONFLICT)
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('//attacker.example/path');
        $uri->method('getQuery')->willReturn('next=1');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('stale');
        $request->method('getMethod')->willReturn(RequestMethodInterface::METHOD_GET);

        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->with($request, 'current')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testVersionMismatchNormalizesBackslashNetworkPathsToAnInternalPath(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->expects($this->once())->method('location')->with('/attacker.example/path')->willReturn(
            (new Response())->withStatus(StatusCodeInterface::STATUS_CONFLICT)
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/\attacker.example/path');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('stale');
        $request->method('getMethod')->willReturn(RequestMethodInterface::METHOD_GET);

        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->with($request, 'current')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testItChangesResponseCodeTo303WhenRedirectHappensForPutPatchDelete(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn(RequestMethodInterface::METHOD_PUT);

        $factory->method('fromRequest')->with($this->identicalTo($request), null)->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('getStatusCode')->willReturn(StatusCodeInterface::STATUS_FOUND);
        $response->expects($this->once())->method('withStatus')->with(StatusCodeInterface::STATUS_SEE_OTHER)->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testItRemovesInertiaHeaderForExternalRedirects(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn(RequestMethodInterface::METHOD_POST);

        $factory->method('fromRequest')->with($this->identicalTo($request), null)->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('hasHeader')->with('X-Inertia-Location')->willReturn(true);
        $response->method('getStatusCode')->willReturn(StatusCodeInterface::STATUS_CONFLICT);
        $response->expects($this->once())->method('withoutHeader')->with('X-Inertia')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testAddsOneVaryTokenToEveryResponseIncluding204AndRedirects(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        foreach ([
            new Response('php://memory', StatusCodeInterface::STATUS_NO_CONTENT), new Response('php://memory', StatusCodeInterface::STATUS_FOUND, [
                'Vary' => 'Accept, X-Inertia',
            ])] as $response) {
            $request = new ServerRequest();
            $handler = new class($response) implements RequestHandlerInterface {
                public function __construct(private readonly ResponseInterface $response) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->response;
                }
            };

            $result = $middleware->process($request, $handler);
            self::assertSame(1, substr_count(strtolower($result->getHeaderLine('Vary')), 'x-inertia'));
        }
    }

    public function testConvertsNormalInertiaRedirectWithFragmentExceptPrefetch(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        $request = new ServerRequest([], [], '/', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'v1',
        ]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://memory', StatusCodeInterface::STATUS_FOUND, [
                    'Location' => '/next#section',
                ]);
            }
        };
        $result = $middleware->process($request, $handler);
        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $result->getStatusCode());
        self::assertSame('/next#section', $result->getHeaderLine('X-Inertia-Redirect'));
        self::assertFalse($result->hasHeader('Location'));

        $prefetch = $request->withHeader('Purpose', 'prefetch');
        $result   = $middleware->process($prefetch, $handler);
        self::assertSame(StatusCodeInterface::STATUS_FOUND, $result->getStatusCode());
        self::assertSame('/next#section', $result->getHeaderLine('Location'));
    }

    public function testKeepsOrdinaryInertiaRedirectsAsRedirects(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        $request = new ServerRequest([], [], '/', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'v1',
        ]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://memory', StatusCodeInterface::STATUS_FOUND, [
                    'Location' => '/next',
                ]);
            }
        };
        $result = $middleware->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_FOUND, $result->getStatusCode());
        self::assertSame('/next', $result->getHeaderLine('Location'));
        self::assertFalse($result->hasHeader('X-Inertia-Redirect'));

        $put    = $request->withMethod(RequestMethodInterface::METHOD_PUT);
        $result = $middleware->process($put, $handler);
        self::assertSame(StatusCodeInterface::STATUS_SEE_OTHER, $result->getStatusCode());
        self::assertFalse($result->hasHeader('X-Inertia-Redirect'));
    }

    public function testVersionMismatchHasOneVaryTokenAndIsAnExternalReloadResponse(): void
    {
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');
        $middleware = new InertiaMiddleware($factory, versionProvider: $provider);

        $request = new ServerRequest([], [], '/users?filter=active', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);
        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('/users?filter=active', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testFlashProviderPullsOnceForARenderedInertiaResponse(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $provider = new class implements InertiaFlashProviderInterface {
            public int $pulls = 0;

            public function pull(ServerRequestInterface $request): array
            {
                ++$this->pulls;

                return [
                    'message' => 'Welcome',
                ];
            }

            public function persist(ServerRequestInterface $request, array $flash): void {}

            public function preserve(ServerRequestInterface $request): void {}
        };
        $root = new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), $root);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

                return $inertia->render('Projects');
            }
        };

        $response = (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);

        /** @var array<string, mixed> $page */
        $page = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'message' => 'Welcome',
        ], $page['flash']);
        self::assertSame(1, $provider->pulls);
    }

    public function testFlashProviderConsumesIncomingAndPersistsPendingFlashForRedirect(): void
    {
        $request  = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory');
        $provider = new class implements InertiaFlashProviderInterface {
            public int $pulls = 0;

            /** @var array<string, mixed> */
            public array $persisted = [];
            public int $persists    = 0;

            public function pull(ServerRequestInterface $request): array
            {
                ++$this->pulls;

                return [
                    'outdated' => 'Old message',
                ];
            }

            public function persist(ServerRequestInterface $request, array $flash): void
            {
                ++$this->persists;
                $this->persisted = $flash;
            }

            public function preserve(ServerRequestInterface $request): void {}
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('message', 'Saved');

                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
            }
        };

        (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);

        self::assertSame([
            'message' => 'Saved',
        ], $provider->persisted);
        self::assertSame(1, $provider->persists);
        self::assertSame(1, $provider->pulls);

        $emptyRedirect = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
            }
        };

        (new InertiaMiddleware($factory, flashProvider: $provider))->process(
            new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory', [
                'X-Inertia' => 'true',
            ]),
            $emptyRedirect
        );

        self::assertSame(1, $provider->persists);
    }

    public function testPendingFlashOnANonPageNonRedirectResponseFailsInsteadOfBeingDiscarded(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory');
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('message', 'Saved');

                return new Response();
            }
        };

        $this->expectException(InvalidInertiaArgumentException::class);
        (new InertiaMiddleware($factory))->process($request, $handler);
    }

    public function testEarlyMismatchPreservesFlashWithoutPullingOrHandlingTheRequest(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_GET, 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $provider = new class implements InertiaFlashProviderInterface {
            public int $pulls     = 0;
            public int $preserves = 0;

            public function pull(ServerRequestInterface $request): array
            {
                ++$this->pulls;

                return [];
            }

            public function persist(ServerRequestInterface $request, array $flash): void {}

            public function preserve(ServerRequestInterface $request): void
            {
                ++$this->preserves;
            }
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $version = $this->createMock(InertiaVersionProviderInterface::class);
        $version->method('currentVersion')->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        (new InertiaMiddleware($factory, versionProvider: $version, flashProvider: $provider))->process($request, $handler);

        self::assertSame(0, $provider->pulls);
        self::assertSame(1, $provider->preserves);
    }

    public function testRedirectWithPendingFlashFailsWithoutAProvider(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory');
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('message', 'Saved');

                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
            }
        };

        $this->expectException(MissingFlashProviderException::class);
        (new InertiaMiddleware($factory))->process($request, $handler);
    }

    public function testRedirectDoesNotPersistPendingFlashThatCannotBeSerialized(): void
    {
        $request  = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory');
        $provider = new class implements InertiaFlashProviderInterface {
            public int $persists = 0;

            public function pull(ServerRequestInterface $request): array
            {
                return [];
            }

            public function persist(ServerRequestInterface $request, array $flash): void
            {
                ++$this->persists;
            }

            public function preserve(ServerRequestInterface $request): void {}
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $recursiveFlash         = [];
        $recursiveFlash['self'] = &$recursiveFlash;
        $handler                = new class($recursiveFlash) implements RequestHandlerInterface {
            /** @param array<string, mixed> $flash */
            public function __construct(private readonly array $flash) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('private', $this->flash);

                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
            }
        };

        try {
            (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);
            self::fail('Unserializable flash must not be persisted.');
        } catch (InertiaSerializationException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
            self::assertSame(JSON_ERROR_RECURSION, $exception->getPrevious()->getCode());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }

        self::assertSame(0, $provider->persists);
    }

    public function testRedirectDoesNotPersistFlashWhenJsonSerializableThrows(): void
    {
        $request  = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory');
        $provider = new class implements InertiaFlashProviderInterface {
            public int $persists = 0;

            public function pull(ServerRequestInterface $request): array
            {
                return [];
            }

            public function persist(ServerRequestInterface $request, array $flash): void
            {
                ++$this->persists;
            }

            public function preserve(ServerRequestInterface $request): void {}
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $failure = new RuntimeException('Flash serialization failure');
        $flash   = new class($failure) implements JsonSerializable {
            public function __construct(private readonly RuntimeException $failure) {}

            public function jsonSerialize(): mixed
            {
                throw $this->failure;
            }
        };
        $handler = new class($flash) implements RequestHandlerInterface {
            public function __construct(private readonly mixed $flash) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('private', $this->flash);

                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
            }
        };

        try {
            (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);
            self::fail('Unserializable flash must not be persisted.');
        } catch (InertiaSerializationException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringNotContainsString('Flash serialization failure', $exception->getMessage());
        }

        self::assertSame(0, $provider->persists);
    }

    public function testExternalLocationPersistsPendingFlashAndPreservesIncomingFlash(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $provider = new class implements InertiaFlashProviderInterface {
            /** @var array<string, mixed> */
            public array $persisted = [];
            public int $pulls       = 0;
            public int $preserves   = 0;

            public function pull(ServerRequestInterface $request): array
            {
                ++$this->pulls;

                return [];
            }

            public function persist(ServerRequestInterface $request, array $flash): void
            {
                $this->persisted = $flash;
            }

            public function preserve(ServerRequestInterface $request): void
            {
                ++$this->preserves;
            }
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('message', 'Saved');

                return $inertia->location('https://example.test/projects');
            }
        };

        $response = (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame([
            'message' => 'Saved',
        ], $provider->persisted);
        self::assertSame(0, $provider->pulls);
        self::assertSame(1, $provider->preserves);
    }

    public function testFragmentRedirectPersistsPendingFlashAndPreservesIncomingFlash(): void
    {
        $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory', [
            'X-Inertia' => 'true',
        ]);
        $provider = new class implements InertiaFlashProviderInterface {
            /** @var array<string, mixed> */
            public array $persisted = [];
            public int $pulls       = 0;
            public int $preserves   = 0;

            public function pull(ServerRequestInterface $request): array
            {
                ++$this->pulls;

                return [];
            }

            public function persist(ServerRequestInterface $request, array $flash): void
            {
                $this->persisted = $flash;
            }

            public function preserve(ServerRequestInterface $request): void
            {
                ++$this->preserves;
            }
        };
        $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
            public function __invoke(Page $page): string
            {
                return '<html></html>';
            }
        });
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var InertiaInterface $inertia */
                $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                $inertia->flash('message', 'Saved');

                return (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects#summary');
            }
        };

        $response = (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('/projects#summary', $response->getHeaderLine('X-Inertia-Redirect'));
        self::assertSame([
            'message' => 'Saved',
        ], $provider->persisted);
        self::assertSame(0, $provider->pulls);
        self::assertSame(1, $provider->preserves);
    }

    public function testProviderFailuresAreWrappedWithTheOperationAndOriginalException(): void
    {
        foreach (['pull', 'persist', 'preserve'] as $operation) {
            $request = new ServerRequest([], [], '/projects', RequestMethodInterface::METHOD_POST, 'php://memory', [
                'X-Inertia' => 'true',
            ]);
            $failure  = new RuntimeException('Provider failure');
            $provider = new class($operation, $failure) implements InertiaFlashProviderInterface {
                public function __construct(private readonly string $operation, private readonly Throwable $failure) {}

                public function pull(ServerRequestInterface $request): array
                {
                    if ('pull' === $this->operation) {
                        throw $this->failure;
                    }

                    return [];
                }

                public function persist(ServerRequestInterface $request, array $flash): void
                {
                    if ('persist' === $this->operation) {
                        throw $this->failure;
                    }
                }

                public function preserve(ServerRequestInterface $request): void
                {
                    if ('preserve' === $this->operation) {
                        throw $this->failure;
                    }
                }
            };
            $factory = new InertiaFactory(new ResponseFactory(), new StreamFactory(), new class implements RootViewProviderInterface {
                public function __invoke(Page $page): string
                {
                    return '<html></html>';
                }
            });
            $handler = new class($operation) implements RequestHandlerInterface {
                public function __construct(private readonly string $operation) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    /** @var InertiaInterface $inertia */
                    $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);
                    if ('pull' === $this->operation) {
                        return $inertia->render('Projects');
                    }

                    $inertia->flash('message', 'Saved');

                    return 'preserve' === $this->operation
                        ? $inertia->location('/projects')
                        : (new Response())->withStatus(StatusCodeInterface::STATUS_FOUND)->withHeader('Location', '/projects');
                }
            };

            try {
                (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);
                self::fail('The provider failure should have been wrapped.');
            } catch (InertiaFlashException $exception) {
                self::assertSame($operation, $exception->operation());
                self::assertSame($failure, $exception->getPrevious());
                self::assertStringNotContainsString('Provider failure', $exception->getMessage());
            }
        }
    }

    public function testFlashProviderFailsFastForAnInertiaImplementationWithoutFlashState(): void
    {
        $request = new ServerRequest();
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->once())->method('fromRequest')->with($request, null)->willReturn($inertia);
        $provider = $this->createMock(InertiaFlashProviderInterface::class);
        $handler  = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(UnsupportedInertiaImplementationException::class);
        (new InertiaMiddleware($factory, flashProvider: $provider))->process($request, $handler);
    }
}
