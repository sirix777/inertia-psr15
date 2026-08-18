# Inertia v3 protocol capability matrix

This matrix describes the protocol surface implemented by `sirix/inertia-psr15` 3.0. It is verified against the [official Inertia v3 protocol](https://inertiajs.com/docs/v3/core-concepts/the-protocol).

| Area | Status | Evidence |
| --- | --- | --- |
| Initial JSON script and separate mount element | Supported | `InertiaExtensionTest` |
| Inertia JSON responses and `Vary: X-Inertia` | Supported | `InertiaMiddlewareTest` |
| Asset-version mismatch | Supported with `InertiaVersionProviderInterface` | Early short-circuit, current-version control header, and page-version tests |
| Background/prefetch mismatch | Supported | The adapter returns the protocol control response; the v3 client keeps background visits from forcing a navigation |
| Partial `only`, `except`, and nested paths | Supported | `InertiaTest` and `InertiaV3Test` |
| Combined `only` then `except` | Supported | Top-level and nested contract tests |
| Optional and deferred props, including wrappers returned from closures | Supported | `InertiaV3Test` |
| Always props | Supported through `Inertia::always()` at the top-level prop boundary | `InertiaV3Test` |
| Merge, deep merge, prepend, matching, and scroll metadata | Supported | `InertiaV3Test` |
| Once, reset, history, shared, and rescued metadata | Supported | `InertiaV3Test` |
| External locations, fragment redirects, and redirect normalization | Supported | `InertiaMiddlewareTest` |
| Validation errors and `X-Inertia-Error-Bag` | Application-owned | The generic adapter always emits an empty `errors` object by default; applications may supply scoped errors as props. It does not read framework sessions or error bags. |
| Top-level flash page field and flash event semantics | Supported, optional storage integration | `InertiaInterface::flash()` emits non-empty data as top-level `page.flash`, never as `props.flash`. Register `InertiaFlashProviderInterface` to pull incoming flash and persist pending flash across redirects. |
| Nested `ProvidesInertiaProperties`-style contract | Not provided | Return arrays or closures. Apply `Inertia::always()` to the top-level prop; nested wrappers inside arbitrary containers are not inspected before partial filtering. |
| Package exception contract | Supported | All adapter-created boundary exceptions implement `InertiaExceptionInterface`. Wrapped resolver, provider, serializer, renderer, and container exceptions retain the original throwable in `getPrevious()`. |

`InertiaVersionProviderInterface` is the only source of the page version. The middleware validates the provider version and passes it to `InertiaFactoryInterface::fromRequest()`, so the request-scoped service is created with the canonical version and handlers cannot override it. A non-null provider version enables the early short-circuit path and preserves incoming flash without pulling it before the client reloads. The provider is optional only for applications that deliberately run without asset versioning: when it is absent or returns `null`, pages carry `version: null` and no mismatch detection is performed.

`InertiaFlashProviderInterface` is optional. Without it, the adapter does not read a session or any other storage: direct `flash()` values can still be rendered, but redirecting pending flash fails with `MissingFlashProviderException`. With a provider, incoming flash is read lazily for a Page response or consumed for a normal redirect before the middleware persists newly pending flash. A `props.flash` value remains ordinary application data and does not trigger the Inertia v3 flash contract.
