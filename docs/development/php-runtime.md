# PHP Runtime and Tooling

This document defines the PHP runtime and first development tools for NENE2.

## Runtime Policy

NENE2 targets PHP `>=8.4.1 <9.0`.

The framework should use modern PHP features deliberately, but it should still keep code simple enough for static analysis, tests, and AI tools to follow.

## Composer

`composer.json` lives at the repository root because NENE2 is a PHP framework package first.

Package metadata:

- package name: `hideyukimori/nene2`
- type: `library`
- license: `MIT`
- autoload namespace: `Nene2\\`
- source directory: `src/`
- test namespace: `Nene2\\Tests\\`
- test directory: `tests/`

`composer.lock` is committed for reproducible development tooling. Consumers of the library still resolve their own dependency graph from `composer.json`.

## Development Dependencies

The initial PHP tooling is intentionally small:

- PHPUnit for tests
- PHPStan for static analysis

Tool configuration files:

- `phpunit.xml.dist`
- `phpstan.neon.dist`

## Commands

```bash
composer validate
composer test
composer analyse
composer check
```

`composer check` should become the default local verification command for PHP changes once runtime code and tests exist.

## Local Environment

Use PHP 8.4.1 or newer for local development. Older PHP versions are unsupported even if some non-runtime commands still execute.
