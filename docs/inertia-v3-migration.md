# Migrating to Inertia v3 and `sirix/inertia-psr15` 3.x

This guide covers upgrading an application built with Mezzio, Slim, or another PSR-15 framework to `sirix/inertia-psr15` 3.x. The package adapts the core Inertia v3 protocol to PSR-15.

This package is a server-side adapter. Updating the Inertia JavaScript client, Vite, and UI components must be done in the consuming application, not in this repository.

## What changes

Version 3.x retains the v2 protocol changes and adds these breaking changes:

1. `InertiaInterface` now exposes `flash()` for Inertia v3 flash data.
2. Non-rescued application prop resolver throwables are wrapped in `InertiaPropResolutionException`; the original throwable is available through `getPrevious()`.
3. Package boundary failures implement `InertiaExceptionInterface`, with concrete exceptions for flash, serialization, rendering, configuration, and container failures.
4. A custom `InertiaInterface` implementation must implement the public `InertiaFlashStateInterface` if the container registers an `InertiaFlashProviderInterface`.
5. `InertiaInterface::version()` was removed; configure an `InertiaVersionProviderInterface` instead.
6. Custom `InertiaFactoryInterface` implementations must accept the new required nullable `?string $version` parameter in `fromRequest()`.
7. `InertiaInterface::render()` no longer accepts a page URL; the adapter always derives a relative URL from the request URI.

The package supports deferred, merge/deep-merge/prepend, scroll, once, and history metadata APIs. It also supports `Inertia::always()` and applies simultaneous `only` and `except` partial-reload headers in protocol order: `only` narrows the response and `except` removes paths from that result. The v2 changes to initial JSON markup, `optional()` replacing `lazy()`, and partial reload behavior remain in force.

Automatic framework session integration is intentionally outside this generic PSR-15 adapter. Applications may provide validation errors, named error bags, and framework-specific nested-property data through ordinary shared/page props. For flash across redirects, applications opt in by implementing and registering the storage-neutral provider described below. See the [capability matrix](inertia-v3-capabilities.md) for the complete boundary.

## Requirements

- PHP 8.2 or later.
- An Inertia v3 client-side adapter:

  ```sh
  npm install @inertiajs/vue3@^3
  # or @inertiajs/react@^3 / @inertiajs/svelte@^3
  ```

- React applications require React 19+, and Svelte applications require Svelte 5+. These requirements are defined by Inertia v3 itself.

Review your frontend setup against the official [upgrade guide](https://inertiajs.com/docs/v3/getting-started/upgrade-guide) and [client-side setup](https://inertiajs.com/docs/v3/installation/client-side-setup).

## Migration steps

### 1. Upgrade the server package

Update the version constraint in your application:

```sh
composer require sirix/inertia-psr15:^3.0
```

If your application installs the package from a VCS repository, switch to the 3.x branch or tag according to your established installation method.

### 2. Replace `lazy()` with `optional()`

Before 2.x, optional props were created like this:

```php
use Sirix\InertiaPsr15\Service\Inertia;

return $inertia->render('Users/Index', [
    'users' => Inertia::lazy(fn () => $users),
]);
```

In 2.x, use:

```php
use Sirix\InertiaPsr15\Service\Inertia;

return $inertia->render('Users/Index', [
    'users' => Inertia::optional(fn () => $users),
]);
```

`optional()` does not include its value in a standard response. The value is evaluated only during a partial reload that explicitly requests the corresponding prop through `only`.

If your application imports or type-hints `Sirix\InertiaPsr15\Model\LazyProp` directly, replace it with `Sirix\InertiaPsr15\Model\OptionalProp`. In most cases, no direct model import is needed: call `Inertia::optional()` instead.

### 3. Check the root template

If the template uses the built-in Twig function, the invocation does not change:

```twig
{{ inertia(page) }}
```

In 2.x, it generates v3-compatible HTML automatically:

```html
<script data-page="app" type="application/json">{...}</script>
<div id="app"></div>
```

If you implemented the root template manually, replace the legacy markup:

```html
<div id="app" data-page="{...}"></div>
```

with a `<script type="application/json">` element and a separate `<div id="app"></div>`. Do not insert JSON into HTML manually without appropriate escaping; using the package's Twig function is preferred.

### 4. Check partial reloads

Existing top-level reloads continue to work:

```js
router.reload({ only: ['users'] })
```

Version 2.x also supports nested paths. A server-side handler may return structured props:

```php
return $inertia->render('Dashboard', [
    'auth' => fn () => [
        'user' => $user,
        'notifications' => Inertia::optional(fn () => $notifications),
    ],
]);
```

The client can request only the notifications:

```js
router.reload({ only: ['auth.notifications'] })
```

The response contains only the selected branch:

```json
{
  "props": {
    "auth": {
      "notifications": []
    }
  }
}
```

Excluding props is supported as well:

```js
router.reload({ except: ['auth.notifications'] })
```

If the client sends both `only` and `except`, the adapter first applies `only`, then removes `except`; a path in both lists is excluded. Dot notation works for nested arrays at any depth, including containers returned by closures. Prop keys containing dots are normalized to nested paths before filtering, so use dot paths rather than literal dotted keys.

### 5. Move asset versioning into a version provider

`InertiaInterface::version()` was removed in 3.0, together with the internal `InertiaVersionProviderAwareInterface` and the legacy late mismatch check that ran after the handler. The version provider is now the only source of the page version. Applications that previously called `$inertia->version(...)` in a handler must move that logic into an `InertiaVersionProviderInterface` implementation.

Implement and register `InertiaVersionProviderInterface` when the application has asset versioning. The middleware rejects a stale Inertia `GET` before the downstream handler runs, so application middleware cannot consume flash data, validation errors, or old input before the client performs the full reload.

```php
use Psr\Http\Message\ServerRequestInterface;
use Sirix\InertiaPsr15\Service\InertiaVersionProviderInterface;

final class AssetVersionProvider implements InertiaVersionProviderInterface
{
    public function currentVersion(ServerRequestInterface $request): ?string
    {
        return $this->assetManifest->version();
    }
}
```

Bind the implementation in the PSR-11 container under `InertiaVersionProviderInterface::class`. The middleware validates the provider version and passes it to `InertiaFactoryInterface::fromRequest($request, $version)`, so the request-scoped Inertia service is created with the canonical version already applied to the page. The provider must return a non-blank header-safe version; return `null` (or register no provider) only when the application deliberately runs without asset versioning. The same validation applies to direct `Inertia` construction and custom factories. A missing client `X-Inertia-Version` mismatches a configured provider and causes a full reload. Provider failures are wrapped in `InertiaVersionException` with their original throwable in `getPrevious()`.

Custom `InertiaFactoryInterface` implementations must be updated to the new signature and apply the version when creating the service:

```php
use Psr\Http\Message\ServerRequestInterface;
use Sirix\InertiaPsr15\Service\InertiaInterface;

public function fromRequest(ServerRequestInterface $request, ?string $version): InertiaInterface;
```

### 6. Configure flash storage when you need redirect flash

`InertiaInterface::flash()` supports a direct response without any provider:

```php
return $inertia
    ->flash('message', 'Saved')
    ->render('Users/Index');
```

This produces the Inertia v3 protocol field at the page top level:

```json
{
  "props": {"flash": {"ordinary": "application data"}},
  "flash": {"message": "Saved"}
}
```

The two fields are intentionally independent: `props.flash` is an ordinary prop and does not implement Inertia flash event semantics. Empty flash is omitted from the page object.

For incoming flash and redirects, implement the storage-neutral port and bind it in the PSR-11 container under `InertiaFlashProviderInterface::class`. `ApplicationFlashStore` below represents your framework-specific session/flash adapter; its `pull()` method must return a string-keyed flash map and consume it for the current request.

```php
use Psr\Http\Message\ServerRequestInterface;
use Sirix\InertiaPsr15\Service\InertiaFlashProviderInterface;

final class ApplicationFlashProvider implements InertiaFlashProviderInterface
{
    public function __construct(private readonly ApplicationFlashStore $flashStore)
    {
    }

    public function pull(ServerRequestInterface $request): array
    {
        return $this->flashStore->pull($request);
    }

    public function persist(ServerRequestInterface $request, array $flash): void
    {
        // Store current-request flash for the next request.
    }

    public function preserve(ServerRequestInterface $request): void
    {
        // Keep incoming flash available for one more visit after a control response.
    }
}
```

For a framework bridge, order middleware as: session middleware, the framework's native flash middleware, this Inertia middleware, then routing/handler middleware. This ensures the provider sees the request-local session and flash state, while Inertia can persist or preserve flash before the response leaves the application. Use the bridge package's integration guide when it provides a more framework-specific registration order.

The adapter calls `pull()` lazily and at most once for a request that renders a Page. It calls `persist()` only when pending flash must survive a redirect, and calls `preserve()` for Inertia control responses that cause a new visit. Provider failures are reported as `InertiaFlashException`, with the original failure in `getPrevious()`.

The provider is optional. When it is absent, the core package does not access a session or other storage. Direct `flash()` values still render; however, a redirect carrying pending flash throws `MissingFlashProviderException` so that data is not silently lost.

If your application supplies its own `InertiaInterface` implementation and also registers a flash provider, it must implement the public `InertiaFlashStateInterface`. This lets the middleware attach the lazy resolver and inspect pending flash. Otherwise, the middleware fails fast with `UnsupportedInertiaImplementationException` when it creates the request's Inertia service.

### 7. Update exception handling

Catch `InertiaExceptionInterface` to handle package-created boundary failures as a group. It covers validation, configuration, flash-provider, prop-resolution, serialization, rendering, and container exceptions. Catch a concrete type when recovery differs by operation:

```php
use Sirix\InertiaPsr15\Exception\InertiaExceptionInterface;
use Sirix\InertiaPsr15\Exception\InertiaPropResolutionException;

try {
    return $inertia->render('Users/Index', [
        'users' => fn () => $repository->all(),
    ]);
} catch (InertiaPropResolutionException $exception) {
    $cause = $exception->getPrevious();
    // Report or recover using the original resolver failure.
} catch (InertiaExceptionInterface $exception) {
    // Handle a different adapter boundary failure.
}
```

Before 3.x, a non-rescued prop resolver throwable was rethrown unchanged. In 3.x it is wrapped once in `InertiaPropResolutionException`; its original `Exception`, `Error`, or `TypeError` remains available through `getPrevious()`. Deferred props configured with rescue behavior retain their rescue semantics.

### 8. Verify the application

After upgrading, verify at least the following:

1. The first URL load mounts the client application without an initial-page parsing error.
2. Navigation through `<Link>` or `router.visit()` receives JSON with `X-Inertia: true`.
3. `router.reload({ only: [...] })` returns only the selected props.
4. `router.reload({ except: [...] })` excludes the specified props.
5. An optional prop is absent from a standard response and only appears when explicitly requested through `only`.
6. A stale Inertia `GET` returns `409`, `X-Inertia-Location`, and `X-Inertia-Version` without executing the page handler.
7. A combined `only`/`except` reload excludes any overlapping path.
8. `flash()` appears in top-level `page.flash`, while any `props.flash` value stays unchanged and separate.
9. A render without a flash provider makes no session/storage call; a redirect with pending flash either persists through the configured provider or raises `MissingFlashProviderException`.
10. Existing prop resolver error handling catches `InertiaPropResolutionException` (or the broader `InertiaExceptionInterface`) and reads the original error via `getPrevious()` when needed.

For this package itself, run:

```sh
composer check
```

## Laravel guide steps that do not apply

This package does not use `inertiajs/inertia-laravel`, Blade, Artisan, or `config/inertia.php`. Therefore, the official guide's Laravel-specific steps for publishing the Inertia config, clearing Blade views, and setting Laravel middleware priority do not apply to PSR-15 applications.

Likewise, frontend event renames, the `router.cancel()` replacement, and React/Svelte requirements concern the JavaScript code of the consuming application. The server-side adapter does not call those APIs.

## Additional resources

- [Official Inertia v3 upgrade guide](https://inertiajs.com/docs/v3/getting-started/upgrade-guide)
- [Inertia v3 protocol](https://inertiajs.com/docs/v3/core-concepts/the-protocol)
- [Partial reloads](https://inertiajs.com/docs/v3/data-props/partial-reloads)
