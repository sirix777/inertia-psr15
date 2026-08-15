# Migrating to Inertia v3 and `sirix/inertia-psr15` 2.x

This guide covers migrating an application built with Mezzio, Slim, or another PSR-15 framework from `sirix/inertia-psr15` 1.x to 2.x. The package adapts the core Inertia v3 protocol to PSR-15.

This package is a server-side adapter. Updating the Inertia JavaScript client, Vite, and UI components must be done in the consuming application, not in this repository.

## What changes

Version 2.x contains three breaking changes:

1. Initial page data is passed through a JSON `<script>` element instead of the root `<div>` element's `data-page` attribute.
2. `Inertia::lazy()` and the `LazyProp` class have been removed. Use `Inertia::optional()` instead.
3. Partial reloads support `X-Inertia-Partial-Except` and dot-notation paths such as `auth.notifications`.

The package supports deferred, merge/deep-merge/prepend, scroll, once, and history metadata APIs. It also supports `Inertia::always()` and applies simultaneous `only` and `except` partial-reload headers in protocol order: `only` narrows the response and `except` removes paths from that result.

Automatic framework session integration is intentionally outside this generic PSR-15 adapter. Applications may provide validation errors, named error bags, and framework-specific nested-property data through ordinary shared/page props. The dedicated v3 top-level `flash` field and its client event semantics are not implemented; an ordinary prop named `flash` is not equivalent. See the [capability matrix](inertia-v3-capabilities.md) for the complete boundary.

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
composer require sirix/inertia-psr15:^2.0
```

If your application installs the package from a VCS repository, switch to the 2.x branch or tag according to your established installation method.

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

### 5. Configure early asset-version checks

Implement and register `InertiaVersionProviderInterface` when the application has asset versioning. This lets the middleware reject a stale Inertia `GET` before the downstream handler runs, so application middleware cannot consume flash data, validation errors, or old input before the client performs the full reload.

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

Bind the implementation in the PSR-11 container under `InertiaVersionProviderInterface::class`. The provider is optional: without it, the adapter preserves its legacy late version check for applications that set the version in their handler. A non-null provider version is authoritative, so later `version()` calls cannot overwrite it. Returning `null` delegates version selection to the handler and preserves the late check. The early provider path is recommended because it implements the v3 short-circuit contract.

### 6. Verify the application

After upgrading, verify at least the following:

1. The first URL load mounts the client application without an initial-page parsing error.
2. Navigation through `<Link>` or `router.visit()` receives JSON with `X-Inertia: true`.
3. `router.reload({ only: [...] })` returns only the selected props.
4. `router.reload({ except: [...] })` excludes the specified props.
5. An optional prop is absent from a standard response and only appears when explicitly requested through `only`.
6. A stale Inertia `GET` returns `409`, `X-Inertia-Location`, and `X-Inertia-Version` without executing the page handler.
7. A combined `only`/`except` reload excludes any overlapping path.

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
