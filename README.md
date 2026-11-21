# Sirix inertia-psr15

[![Latest Stable Version](http://poser.pugx.org/sirix/inertia-psr15/v)](https://packagist.org/packages/sirix/inertia-psr15) [![Total Downloads](http://poser.pugx.org/sirix/inertia-psr15/downloads)](https://packagist.org/packages/sirix/inertia-psr15) [![Latest Unstable Version](http://poser.pugx.org/sirix/inertia-psr15/v/unstable)](https://packagist.org/packages/sirix/inertia-psr15) [![License](http://poser.pugx.org/sirix/inertia-psr15/license)](https://packagist.org/packages/sirix/inertia-psr15) [![PHP Version Require](http://poser.pugx.org/sirix/inertia-psr15/require/php)](https://packagist.org/packages/sirix/inertia-psr15)

PSR-15 server-side adapter for [Inertia.js](https://inertiajs.com/). It works with [Mezzio](https://mezzio.dev/), [Slim](https://www.slimframework.com/) or any framework built on PSR-7 and PSR-15, and targets Inertia v3 clients.

New to Inertia? Read [what it is](https://inertiajs.com/#top), [who it is for](https://inertiajs.com/who-is-it-for) and [how it works](https://inertiajs.com/how-it-works) first.

The adapter is a PSR-15 middleware that detects Inertia requests and prepares the response that the Inertia front-end components render.

## Requirements

- PHP `~8.3` to `~8.6`
- A PSR-11 container, PSR-17 factories, and an Inertia v3 client-side adapter ([client-side setup](https://inertiajs.com/docs/v3/installation/client-side-setup))

## Installation

```shell
composer require sirix/inertia-psr15
```

Pipe the middleware before routing:

```php
$app->pipe(\Sirix\InertiaPsr15\Middleware\InertiaMiddleware::class);
$app->pipe(RouteMiddleware::class);
```

A complete Mezzio + Twig + Vite walkthrough, including the root template, is in [docs/mezzio-twig-setup.md](docs/mezzio-twig-setup.md). A [demo application](https://github.com/sirix777/mezzio-inertia-svelte-demo) is also available.

## Usage

Fetch the request-scoped Inertia service in a handler and render a front-end component:

```php
declare(strict_types=1);

namespace App\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\InertiaPsr15\Middleware\InertiaMiddleware;
use Sirix\InertiaPsr15\Service\InertiaInterface;

class HomePageHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var InertiaInterface $inertia */
        $inertia = $request->getAttribute(InertiaMiddleware::INERTIA_ATTRIBUTE);

        return $inertia->render('Home', [
            'greeting' => 'Hello Inertia PSR-15',
        ]);
    }
}
```

### Props

Use `Inertia::optional()` for props that should only be included when explicitly requested in a partial reload:

```php
use Sirix\InertiaPsr15\Service\Inertia;

$inertia->render('Users/Index', [
    'users' => Inertia::optional(fn () => $users),
]);
```

Use `Inertia::always()` for a top-level prop that must be included on every partial response, regardless of `only` and `except`:

```php
$inertia->render('Users/Index', [
    'users' => $users,
    'csrf' => Inertia::always(fn () => $csrfToken),
]);
```

Deferred, merge, scroll, once and history props are supported as well; see the [capability matrix](docs/inertia-v3-capabilities.md). `Inertia::lazy()` and `LazyProp` were removed in 2.x; use `optional()`.

### Flash data

Use `InertiaInterface::flash()` to put one-time data into Inertia v3's top-level `page.flash` field:

```php
$inertia->flash('message', 'Saved');

return $inertia
    ->flash(['id' => 42])
    ->render('Users/Index');
```

`page.flash` is distinct from an ordinary `props.flash` value. Direct flash needs no session integration. To carry pending flash across redirects, register an `InertiaFlashProviderInterface` implementation in the container: the core package deliberately provides no session implementation, and a redirect with pending flash and no provider throws `MissingFlashProviderException` instead of silently discarding the data. Custom `InertiaInterface` implementations used together with a provider must also implement `InertiaFlashStateInterface`. See [the provider contract](docs/inertia-v3-migration.md#6-configure-flash-storage-when-you-need-redirect-flash).

### Asset versioning

Bind an application-specific `InertiaVersionProviderInterface` to enable asset-version checks. It is the only source of the page version: the middleware calls it before the downstream handler and passes the validated result to `InertiaFactoryInterface::fromRequest($request, $version)`. A stale Inertia `GET` then receives a `409` with `X-Inertia-Location` and `X-Inertia-Version` before the handler runs, without consuming application flash state.

The provider is optional only for applications that deliberately work without asset versioning: without one (or when it returns `null`) pages render with `version: null` and mismatch checks are disabled. `InertiaInterface::version()` was removed in 3.x, and custom `InertiaFactoryInterface` implementations must accept the `?string $version` parameter. See [the migration guide](docs/inertia-v3-migration.md#5-move-asset-versioning-into-a-version-provider).

## Configuration

The built-in factories resolve container services strictly through PSR-11. Register these services with values implementing their declared interfaces: `ResponseFactoryInterface`, `StreamFactoryInterface`, `RootViewProviderInterface`, `TemplateRendererInterface`, and `InertiaFactoryInterface`. `InertiaVersionProviderInterface` and `InertiaFlashProviderInterface` are optional.

The `config` service is optional. When present, it must be an array; `inertia_psr15.root_view` is an optional non-empty string and defaults to `app.html.twig`:

```php
return [
    'inertia_psr15' => [
        'root_view' => 'inertia/app.html.twig',
    ],
];
```

Missing services, wrong service types, and invalid configuration fail with an exception that identifies the factory and required value.

## Exceptions

Package boundary exceptions implement `Sirix\InertiaPsr15\Exception\InertiaExceptionInterface`; catch it to handle adapter failures as a group. Concrete exceptions keep the original throwable in `getPrevious()` where the package wraps a provider, prop resolver, renderer, serializer, or container failure. Non-rescued prop resolver failures are wrapped in `InertiaPropResolutionException` rather than rethrown unchanged.

## Documentation

- [Mezzio + Twig + Vite setup](docs/mezzio-twig-setup.md)
- [Migrating to Inertia v3 / 3.x](docs/inertia-v3-migration.md)
- [Inertia v3 capability matrix](docs/inertia-v3-capabilities.md)

## Credits and license

Originally created by Mohammed Cherif BOUCHELAGHEM ([cherifGsoul/inertia-psr15](https://github.com/cherifGsoul/inertia-psr15), 2021); maintained by Sirix since 2025.

Licensed under BSD-3-Clause, see [LICENSE](LICENSE).
