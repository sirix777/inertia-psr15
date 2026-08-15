# Inertia v3 protocol capability matrix

This matrix describes the protocol surface implemented by `sirix/inertia-psr15` 2.1. It is verified against the [official Inertia v3 protocol](https://inertiajs.com/docs/v3/core-concepts/the-protocol).

| Area | Status | Evidence |
| --- | --- | --- |
| Initial JSON script and separate mount element | Supported | `InertiaExtensionTest` |
| Inertia JSON responses and `Vary: X-Inertia` | Supported | `InertiaMiddlewareTest` |
| Asset-version mismatch | Supported with `InertiaVersionProviderInterface` | Early short-circuit, current-version control header, and page-version tests |
| Background/prefetch mismatch | Supported | The adapter returns the protocol control response; the v3 client keeps background visits from forcing a navigation |
| Partial `only`, `except`, and nested paths | Supported | `InertiaTest` and `InertiaV3Test` |
| Combined `only` then `except` | Supported | Top-level and nested contract tests |
| Optional and deferred props | Supported | `InertiaV3Test` |
| Always props | Supported through `Inertia::always()` at the top-level prop boundary | `InertiaV3Test` |
| Merge, deep merge, prepend, matching, and scroll metadata | Supported | `InertiaV3Test` |
| Once, reset, history, shared, and rescued metadata | Supported | `InertiaV3Test` |
| External locations, fragment redirects, and redirect normalization | Supported | `InertiaMiddlewareTest` |
| Validation errors and `X-Inertia-Error-Bag` | Application-owned | The generic adapter always emits an empty `errors` object by default; applications may supply scoped errors as props. It does not read framework sessions or error bags. |
| Top-level flash page field and flash event semantics | Unsupported | `Page` has no top-level `flash` field. A prop named `flash` is ordinary application data and does not implement the v3 flash event contract. |
| Nested `ProvidesInertiaProperties`-style contract | Not provided | Return arrays or closures. Apply `Inertia::always()` to the top-level prop; nested wrappers inside arbitrary containers are not inspected before partial filtering. |
| Validation exceptions | Supported | Invalid protocol values throw `InvalidInertiaArgumentException`, a package-specific subclass of PHP's `InvalidArgumentException`. |

`InertiaVersionProviderInterface` is optional for backwards compatibility. When it is absent or returns `null`, a handler may set the page version through `InertiaInterface::version()`, but mismatch detection occurs after the handler and cannot provide flash-preservation or work-avoidance guarantees. A non-null provider version enables the early short-circuit path.
