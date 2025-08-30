<?php

declare(strict_types=1);

namespace InertiaPsr15Test\Service;

use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Sirix\InertiaPsr15\Service\Inertia;
use Sirix\InertiaPsr15\Service\RootViewProviderInterface;

class InertiaTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testRenderReturnPsr7ResponseWithJsonWhenInertiaHeaderIsPresent(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
            ['X-Inertia-Partial-Data', false],
        ]);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/');
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $expectedJson = '{"component":"component","props":[],"url":"\/","version":null}';
        $capturedPayload = null;

        $streamFactory
            ->expects($this->once())
            ->method('createStream')
            ->with($this->callback(function(string $payload) use (&$capturedPayload, $expectedJson) {
                $capturedPayload = $payload;

                return $payload === $expectedJson;
            }))
            ->willReturn($stream)
        ;

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response
            ->expects($this->once())
            ->method('withBody')
            ->with($stream)
            ->willReturn($response)
        ;

        $response
            ->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($response)
        ;

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render('component');

        $this->validateResponseInstance($returnedResponse);
        $this->assertSame($expectedJson, $capturedPayload);
    }

    /**
     * @throws JsonException
     */
    public function testRenderReturnPsr7ResponseWithHtmlWhenInertiaHeaderIsNotPresent(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', false],
            ['X-Inertia-Partial-Data', false],
        ]);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/');
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $expectedHtml = '<html>ok</html>';
        $capturedPayload = null;

        $streamFactory
            ->expects($this->once())
            ->method('createStream')
            ->with($this->callback(function(string $payload) use (&$capturedPayload, $expectedHtml) {
                $capturedPayload = $payload;

                return $payload === $expectedHtml;
            }))
            ->willReturn($stream)
        ;

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);
        $rootViewProvider->method('__invoke')->willReturn($expectedHtml);

        $response
            ->expects($this->once())
            ->method('withBody')
            ->with($stream)
            ->willReturn($response)
        ;

        $response
            ->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html; charset=UTF-8')
            ->willReturn($response)
        ;

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render('component');

        $this->validateResponseInstance($returnedResponse);
        $this->assertSame($expectedHtml, $capturedPayload);
    }

    /**
     * @throws JsonException
     */
    public function testRenderReturnPartialDataWhenHeaderContainsPartialData(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
            ['X-Inertia-Partial-Data', true],
        ]);
        $request->method('getHeaderLine')->willReturnMap([
            ['X-Inertia-Partial-Component', 'component'],
            ['X-Inertia-Partial-Data', 'key2'],
        ]);
        $json = '{"component":"component","props":{"key2":"value2"},"url":"callback()","version":null}';
        $jsonResponse = null;

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('callback()');
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function(string $data) use (&$jsonResponse, $stream) {
            $jsonResponse = $data;

            return $stream;
        });

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render(
            'component',
            [
                'key1' => fn () => 'value1',
                'key2' => fn () => 'value2',
            ]
        );

        $this->validateResponseInstance($returnedResponse);
        $this->assertSame($json, $jsonResponse);
    }

    /**
     * @throws JsonException
     */
    public function testRenderReturnResponseWithRequestedUrl(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
            ['X-Inertia-Partial-Data', false],
        ]);
        $invalidJson = '{"component":"component","props":{"key1":"value1","key2":"value2"},"url":"callback()","version":null}';
        $validJson = '{"component":"component","props":{"key1":"value1","key2":"value2"},"url":"\/test\/url","version":null}';
        $jsonResponse = null;

        $uri = $this->createMock(UriInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function(string $data) use (&$jsonResponse, $stream) {
            $jsonResponse = $data;

            return $stream;
        });

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render(
            'component',
            [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
            '/test/url'
        );

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame($invalidJson, $jsonResponse);
        $this->assertSame($validJson, $jsonResponse);
    }

    /**
     * @throws JsonException
     */
    public function testRenderReturnResponseWithoutLazyProps(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
            ['X-Inertia-Partial-Data', false],
        ]);
        $invalidJson = '{"component":"component","props":{"key1":"value1","key2":"value2"},"url":"callback()","version":null}';
        $validJson = '{"component":"component","props":{"key2":"value2"},"url":"callback()","version":null}';
        $jsonResponse = null;

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('callback()');
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function(string $data) use (&$jsonResponse, $stream) {
            $jsonResponse = $data;

            return $stream;
        });

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render(
            'component',
            [
                'key1' => Inertia::lazy(fn () => 'value1'),
                'key2' => fn () => 'value2',
            ]
        );

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame($invalidJson, $jsonResponse);
        $this->assertSame($validJson, $jsonResponse);
    }

    /**
     * @throws JsonException
     */
    public function testRenderReturnResponseWithLazyProps(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
            ['X-Inertia-Partial-Data', true],
        ]);
        $request->method('getHeaderLine')->willReturnMap([
            ['X-Inertia-Partial-Component', 'component'],
            ['X-Inertia-Partial-Data', 'key1'],
        ]);
        $invalidJson = '{"component":"component","props":{"key2":"value2"},"url":"callback()","version":null}';
        $validJson = '{"component":"component","props":{"key1":"value1"},"url":"callback()","version":null}';
        $jsonResponse = null;

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('callback()');
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function(string $data) use (&$jsonResponse, $stream) {
            $jsonResponse = $data;

            return $stream;
        });

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->render(
            'component',
            [
                'key1' => Inertia::lazy(fn () => 'value1'),
                'key2' => fn () => 'value2',
            ]
        );

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame($invalidJson, $jsonResponse);
        $this->assertSame($validJson, $jsonResponse);
    }

    public function testLocationReturnResponseWithLocationAsStringWithNotExistingInertiaHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $htmlResponse = null;

        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(fn (string $data) => $stream);

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $response->method('withStatus')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->location('new-location');

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame('', $htmlResponse);
    }

    public function testLocationReturnResponseWithLocationAsStringWithExistingInertiaHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
        ]);
        $htmlResponse = null;

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(fn (string $data) => $stream);

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $response->method('withStatus')->willReturn($response);

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->location('new-location');

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame('', $htmlResponse);
    }

    public function testLocationReturnResponseWithLocationAsResponseInterfaceWithExistingInertiaHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturnMap([
            ['X-Inertia', true],
        ]);
        $htmlResponse = null;

        $response = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(fn (string $data) => $stream);

        $rootViewProvider = $this->createMock(RootViewProviderInterface::class);

        $response->method('withBody')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $response->method('withStatus')->willReturn($response);

        $locationResponse = $this->createMock(ResponseInterface::class);
        $locationResponse->method('getHeaderLine')->with('Location')->willReturn('new-location');

        $inertia = new Inertia(
            $request,
            $responseFactory,
            $streamFactory,
            $rootViewProvider
        );

        $returnedResponse = $inertia->location($locationResponse);

        $this->validateResponseInstance($returnedResponse);
        $this->assertNotSame('', $htmlResponse);
    }

    public function validateResponseInstance(?ResponseInterface $returnedResponse): void
    {
        $this->assertInstanceOf(ResponseInterface::class, $returnedResponse);
    }
}
