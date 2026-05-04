# ADR 0002: Use a Minimal PSR-11 Container

## Status

accepted

## Context

NENE2 needs a dependency injection foundation that exposes PSR-11 at composition boundaries while keeping explicit wiring as the default. The first container step should not make autowiring, attributes, compile-time generation, or a broad framework runtime part of the standard path.

The decision must preserve testability, static analysis friendliness, low dependency footprint, and clear replacement paths.

## Decision

Use `psr/container` as the only external dependency for the first dependency injection foundation.

Add a small internal container, builder, and service provider contract in `src/DependencyInjection/`.

The internal container will:

- implement `Psr\Container\ContainerInterface`
- resolve explicitly registered factories
- cache resolved entries
- expose predictable `has()` and `get()` behavior
- map missing entries and factory failures to PSR-11 compatible exceptions

Do not adopt an external concrete container package in this step.

Alternatives considered:

- `php-di/php-di`: mature and widely adopted, but its core value is richer definition formats and autowiring that NENE2 does not want as the initial default.
- `league/container`: small and PSR-11 compatible, but still adds event-dispatcher dependency and more behavior than the first explicit wiring step needs.
- `laminas/laminas-servicemanager`: mature and factory-driven, but broader and heavier than the first NENE2 container boundary requires.

## Consequences

NENE2 keeps the public dependency injection boundary standard while retaining full control over the first wiring conventions.

Framework code can depend on `Psr\Container\ContainerInterface` at composition boundaries, and tests can use the internal builder without hidden autowiring behavior.

If future applications need richer features such as delegated containers, compiled definitions, or opt-in autowiring, NENE2 can add an adapter or replace the internal implementation behind PSR-11 through a follow-up ADR.

## Related

- Issue: `#45`
- PR: `#000`
- Supersedes: none
- Superseded by: none
