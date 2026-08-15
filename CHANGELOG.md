# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
