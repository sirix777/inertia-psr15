# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - Unreleased

### Added
- Optional, storage-neutral `InertiaFlashProviderInterface` integration for Inertia v3 top-level `page.flash` data.
- `InertiaFactoryInterface::fromRequest()` accepts a required nullable `?string $version` second parameter; the factory creates the request-scoped Inertia service with the canonical provider version already applied to the page.
- `InertiaInterface::flash()` for direct response flash and redirect-persisted flash.
- `InertiaExceptionInterface` and package-specific configuration, flash, version-provider, prop-resolution, serialization, rendering, and container exception types.
- Direct `psr/http-factory` production dependency for the PSR-17 interfaces used by the core service.

### Changed
- Flash is emitted as top-level `page.flash`; it is independent from an ordinary `props.flash` value and omitted when empty.
- Registering an `InertiaFlashProviderInterface` now requires a custom `InertiaInterface` implementation to support the public `InertiaFlashStateInterface`; unsupported implementations fail fast when the middleware creates the request's Inertia service.
- Flash providers are optional. Without one, the adapter does not access session/storage; direct flash still renders, while redirecting pending flash throws `MissingFlashProviderException`.
- Non-rescued application prop resolver failures are wrapped in `InertiaPropResolutionException`. The original throwable is available through `getPrevious()`.
- Provider, serialization, rendering, and container boundary failures now use package-specific exceptions and preserve their original throwable through `getPrevious()`.
- `InertiaVersionProviderInterface` is now the only source of the page version. The middleware resolves and validates the provider version before creating the Inertia service and passes it through `InertiaFactoryInterface::fromRequest()`; handlers can no longer set or override the version.
- A version provider returning `null` now means the application does not use asset versioning: the handler runs, the page carries `version: null`, and no mismatch check is performed.
- A missing client `X-Inertia-Version` now mismatches a configured version provider and triggers the required full reload. Providers must return a non-empty version or `null` to disable versioning.
- `Inertia::merge()` now registers root merge metadata as a fallback only when no append/prepend path is configured; `deepMerge()` takes precedence over merge-path metadata.
- Page URLs are derived exclusively from the request URI and are always relative.
- `InertiaFlashStateInterface` is now a public extension contract for custom Inertia implementations.

### Removed
- `InertiaInterface::version()` and `getVersion()`; register an `InertiaVersionProviderInterface` instead.
- `InertiaVersionProviderAwareInterface` and `Inertia::setVersionFromProvider()`; the provider-version lock is unnecessary now that the version is fixed at service creation.
- The legacy late version mismatch check that ran after the handler when no provider version was available.
- The third `$url` argument of `InertiaInterface::render()`; use the request URI as the single page-URL source.
- Unused `MissingInertiaConfigException`, `Page::withProps()`, `RootViewProviderDecorator::render()`, partial-props debug output, and unavailable Composer scripts.

## [2.1.1] - 2026-08-15

### Fixed
- Partial reload filtering now replaces the page props after applying `only` and `except`, preventing excluded shared and nested props from being merged back into the response.

## [2.1.0] - 2026-08-15

### Added
- `InertiaVersionProviderInterface` for resolving the current asset version before the downstream handler runs.
- `InertiaVersionProviderAwareInterface`, an internal capability that keeps a non-null provider version from being overwritten by a downstream `version()` call.
- `Inertia::always()` for top-level props that bypass partial-reload filters.
- An Inertia v3 protocol capability matrix.
- `InvalidInertiaArgumentException` as the package-specific validation exception.

### Changed
- Stale Inertia `GET` requests configured with a version provider now short-circuit with `409`, `X-Inertia-Location`, and `X-Inertia-Version` before application code runs.
- Partial reloads now apply `X-Inertia-Partial-Data` before `X-Inertia-Partial-Except`, including nested paths.
- The legacy late mismatch response now includes `X-Inertia-Version`.
- Protocol-input validation now throws `InvalidInertiaArgumentException`; it remains catch-compatible with `InvalidArgumentException`.
- A version provider returning `null` now delegates to the backward-compatible late mismatch check.
- Invalid UTF-8 bytes in initial page markup are replaced using `JSON_INVALID_UTF8_SUBSTITUTE`.
- Application prop-resolver exceptions continue to be passed through unchanged for 2.x compatibility.

## [2.0.2] - 2026-08-14

### Changed
- Updated the `sirix/container-resolver` dependency constraint to support version `1.x`.

## [2.0.1] - 2026-08-10

### Changed
- Updated `sirix/container-resolver` to version `0.2.0`.
- Verified compatibility with the updated container resolver API.

## [2.0.0] - 2026-08-05

### Added
- Inertia v3 partial reload support for `X-Inertia-Partial-Except` and nested prop paths.
- `Inertia::optional()` for props that are only included when explicitly requested.
- Optional `inertia_psr15.root_view` configuration, defaulting to `app.html.twig`.
- `sirix/container-resolver` to resolve factory services and configuration consistently.

### Changed
- Twig initial-page markup now uses the Inertia v3 JSON `<script data-page="app">` element and a separate application mount point.
- `InertiaMiddleware` is now stateless, preventing request state from being retained or shared by long-running and concurrent server workers.
- Container factories now report missing and invalid required PSR-11 services consistently, with factory context.

### Removed
- `Inertia::lazy()` and `LazyProp`; use `Inertia::optional()` instead.

## [1.1.3] - 2026-05-16

### Added
- InertiaInterface: Added new `location()` method for redirecting to external URLs.

## [1.1.2] - 2026-05-11

### Added
- Tools: Integration of `bamarni/composer-bin-plugin` for better dependency management of development tools.
- PHP Support: Added support for PHP 8.5.

### Changed
- PHP Support: Minimum supported PHP version bumped to 8.2 (dropped 8.1).
- Tooling: Updated PHP-CS-Fixer and Rector configurations to target PHP 8.2.
- Refactoring: Internal code cleanup using PHP 8.2 features (first-class callables).
- Tests: Rename default testsuite to `unit` in PHPUnit configuration.

## [1.1.1] - 2025-09-03

### Changed
- README: Update demo link to sirix/mezzio-inertia-svelte-demo and recommend Vite instead of Webpack, including configuration examples using Sirix\TwigViteExtension. 

## [1.1.0] - 2025-08-31

### Added
- Twig: InertiaExtensionFactory to enable creating the InertiaExtension via a container factory.

### Changed
- ConfigProvider: Registers the Twig InertiaExtension and its factory in the container configuration for easier integration.

## [1.0.1] - 2025-08-30

### Changed
- Twig: InertiaExtension now uses htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE and explicit UTF-8 when rendering the data-page attribute for safer output and correct encoding.

## [1.0.0] - 2025-08-30

### Added
- GitHub Actions workflow for CI (coding standards, static analysis, tests).
- Tooling configurations: PHP-CS-Fixer, PHPStan, Rector.
- Documentation updates in README to reflect the maintained fork, usage, and setup.

### Changed
- Refactored tests to improve structure, readability, and maintainability.
- Namespace updated to `Sirix\\InertiaPsr15\\`.
- Package name updated to `sirix/inertia-psr15`.
- Supported PHP versions updated to `~8.1` through `~8.4`.

### Notes
- Fork initialization for sirix/inertia-psr15
- Original project by Mohammed Cherif BOUCHELAGHEM (2021). 
- This repository is a maintained fork initiated and maintained by Sirix (2025).
