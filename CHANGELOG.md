# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 30/08/2025

### Changed
- Twig: InertiaExtension now uses htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE and explicit UTF-8 when rendering the data-page attribute for safer output and correct encoding.

## [1.0.0] - 30/08/2025 

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
- Original project by Mohammed Cherif BOUCHELAGHEM (2021). This repository is a maintained fork initiated and maintained by Sirix (2025).

