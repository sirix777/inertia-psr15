<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Middleware;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\InertiaPsr15\Exception\InvalidInertiaArgumentException;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Model\Page;
use Sirix\InertiaPsr15\Service\InertiaFactory;
use Sirix\InertiaPsr15\Service\InertiaFactoryInterface;
use Sirix\InertiaPsr15\Service\InertiaInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;
use Sirix\InertiaPsr15\View\RootViewProviderInterface;

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
        $factory->method('fromRequest')->with($this->identicalTo($request))->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->with('Vary')->willReturn('');
        $response->method('withAddedHeader')->with('Vary', 'X-Inertia')->willReturn($response);
        $handler  = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testDoesntChangeHandlerResponseForTheSameVersion(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('12345');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn('GET');

        $factory->method('fromRequest')->with($this->identicalTo($request))->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(202);
        $response->method('withAddedHeader')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testAddsInertiaLocationToResponseWhenVersionChanges(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('forbarbaz');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/some-path');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn('GET');

        $factory->method('fromRequest')->with($this->identicalTo($request))->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(202);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('withStatus')->with(409)->willReturn($response);
        $headers = [];
        $response->method('withHeader')->willReturnCallback(function(string $name, string $value) use ($response, &$headers): ResponseInterface {
            $headers[$name] = $value;

            return $response;
        });
        $response->method('withoutHeader')->with('X-Inertia')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
        self::assertSame([
            'X-Inertia-Location' => '/some-path',
            'X-Inertia-Version'  => 'forbarbaz',
        ], $headers);
    }

    public function testConfiguredVersionProviderShortCircuitsMismatchingGetBeforeTheHandler(): void
    {
        $request = new ServerRequest([], [], '/projects?tab=open', 'GET', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->expects($this->once())->method('version')->with('current');
        $inertia->expects($this->once())->method('location')->with('/projects?tab=open')->willReturn(
            new Response('php://memory', 409, [
                'X-Inertia'          => 'true',
                'X-Inertia-Location' => '/projects?tab=open',
            ])
        );

        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->expects($this->once())->method('fromRequest')->with($request)->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->expects($this->once())->method('currentVersion')->with($request)->willReturn('current');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('/projects?tab=open', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
    }

    public function testConfiguredVersionProviderUsesItsVersionInThePageResponse(): void
    {
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory', [
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
                $inertia->version('legacy');

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
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory', [
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
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testMissingClientVersionDoesNotCauseAMismatch(): void
    {
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory', [
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
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testNullVersionFromProviderUsesTheLateVersionCheck(): void
    {
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory', [
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
                $inertia->version('current');

                return $inertia->render('Projects');
            }
        };

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(1, $handler->calls);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('/projects', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testVersionMismatchOnAPrefetchIsStillReportedAsAControlResponse(): void
    {
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
            'Purpose'           => 'prefetch',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('version');
        $inertia->method('location')->willReturn(
            (new Response())->withStatus(409)->withHeader('X-Inertia-Location', '/projects')
        );
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('current', $response->getHeaderLine('X-Inertia-Version'));
    }

    public function testVersionMismatchDoesNotShortCircuitNonGetRequests(): void
    {
        $request = new ServerRequest([], [], '/projects', 'POST', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->expects($this->once())->method('version')->with('current');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn('current');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Response());

        $response = (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('X-Inertia'));
    }

    public function testVersionProviderRejectsUnsafeHeaderValuesBeforeTheHandlerRuns(): void
    {
        $request = new ServerRequest([], [], '/projects', 'GET', 'php://memory');
        $inertia = $this->createMock(InertiaInterface::class);
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $provider = $this->createMock(InertiaVersionProviderInterface::class);
        $provider->method('currentVersion')->willReturn("current\r\nX-Evil: true");
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(InvalidInertiaArgumentException::class);
        (new InertiaMiddleware($factory, versionProvider: $provider))->process($request, $handler);
    }

    public function testVersionMismatchNeverUsesAnUntrustedAuthorityOrUnsafePathInItsLocationHeader(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('current');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('//attacker.example/path');
        $uri->method('getQuery')->willReturn('next=1');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('stale');
        $request->method('getMethod')->willReturn('GET');
        $factory->method('fromRequest')->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('withStatus')->with(409)->willReturn($response);
        $headers = [];
        $response->method('withHeader')->willReturnCallback(function(string $name, string $value) use ($response, &$headers): ResponseInterface {
            $headers[$name] = $value;

            return $response;
        });
        $response->method('withoutHeader')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        self::assertSame($response, (new InertiaMiddleware($factory))->process($request, $handler));
        self::assertSame('/attacker.example/path?next=1', $headers['X-Inertia-Location']);
        self::assertSame('current', $headers['X-Inertia-Version']);
    }

    public function testVersionMismatchNormalizesBackslashNetworkPathsToAnInternalPath(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('current');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/\attacker.example/path');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withAttribute')->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('stale');
        $request->method('getMethod')->willReturn('GET');
        $factory->method('fromRequest')->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('withStatus')->with(409)->willReturn($response);
        $headers = [];
        $response->method('withHeader')->willReturnCallback(function(string $name, string $value) use ($response, &$headers): ResponseInterface {
            $headers[$name] = $value;

            return $response;
        });
        $response->method('withoutHeader')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        self::assertSame($response, (new InertiaMiddleware($factory))->process($request, $handler));
        self::assertSame('/attacker.example/path', $headers['X-Inertia-Location']);
        self::assertSame('current', $headers['X-Inertia-Version']);
    }

    public function testItChangesResponseCodeTo303WhenRedirectHappensForPutPatchDelete(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('12345');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn('PUT');

        $factory->method('fromRequest')->with($this->identicalTo($request))->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('getStatusCode')->willReturn(302);
        $response->expects($this->once())->method('withStatus')->with(303)->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($this->identicalTo($request))->willReturn($response);

        $middleware = new InertiaMiddleware($factory);
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testItRemovesInertiaHeaderForExternalRedirects(): void
    {
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('12345');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->with(InertiaMiddleware::INERTIA_ATTRIBUTE, $this->identicalTo($inertia))->willReturn($request);
        $request->method('hasHeader')->with('X-Inertia')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Inertia-Version')->willReturn('12345');
        $request->method('getMethod')->willReturn('POST');

        $factory->method('fromRequest')->with($this->identicalTo($request))->willReturn($inertia);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withAddedHeader')->willReturn($response);
        $response->method('hasHeader')->with('X-Inertia-Location')->willReturn(true);
        $response->method('getStatusCode')->willReturn(409);
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
            new Response('php://memory', 204), new Response('php://memory', 302, [
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
        $inertia->method('getVersion')->willReturn('v1');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        $request = new ServerRequest([], [], '/', 'GET', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'v1',
        ]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://memory', 302, [
                    'Location' => '/next#section',
                ]);
            }
        };
        $result = $middleware->process($request, $handler);
        self::assertSame(409, $result->getStatusCode());
        self::assertSame('/next#section', $result->getHeaderLine('X-Inertia-Redirect'));
        self::assertFalse($result->hasHeader('Location'));

        $prefetch = $request->withHeader('Purpose', 'prefetch');
        $result   = $middleware->process($prefetch, $handler);
        self::assertSame(302, $result->getStatusCode());
        self::assertSame('/next#section', $result->getHeaderLine('Location'));
    }

    public function testKeepsOrdinaryInertiaRedirectsAsRedirects(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('v1');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        $request = new ServerRequest([], [], '/', 'GET', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'v1',
        ]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://memory', 302, [
                    'Location' => '/next',
                ]);
            }
        };
        $result = $middleware->process($request, $handler);

        self::assertSame(302, $result->getStatusCode());
        self::assertSame('/next', $result->getHeaderLine('Location'));
        self::assertFalse($result->hasHeader('X-Inertia-Redirect'));

        $put    = $request->withMethod('PUT');
        $result = $middleware->process($put, $handler);
        self::assertSame(303, $result->getStatusCode());
        self::assertFalse($result->hasHeader('X-Inertia-Redirect'));
    }

    public function testVersionMismatchHasOneVaryTokenAndIsAnExternalReloadResponse(): void
    {
        $inertia = $this->createMock(InertiaInterface::class);
        $inertia->method('getVersion')->willReturn('current');
        $factory = $this->createMock(InertiaFactoryInterface::class);
        $factory->method('fromRequest')->willReturn($inertia);
        $middleware = new InertiaMiddleware($factory);

        $request = new ServerRequest([], [], '/users?filter=active', 'GET', 'php://memory', [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => 'stale',
        ]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = $middleware->process($request, $handler);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('/users?filter=active', $response->getHeaderLine('X-Inertia-Location'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('X-Inertia'));
    }
}
