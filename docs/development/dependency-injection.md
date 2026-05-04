# Dependency Injection and Wiring Policy

NENE2 uses PSR-11 as the container boundary and explicit wiring as the standard application style.

## Position

Dependency injection should make the application easier to understand, not hide how it works.

The standard direction is:

- Use PSR-11 for container interoperability.
- Prefer explicit wiring through factories and service providers.
- Keep autowiring optional and outside the initial default.
- Keep framework core separate from application service definitions.
- Make dependency graphs readable to humans and AI agents.

This Issue defines the policy and directories only. Container packages and implementations should be added in a later Issue.

## Standard Directories

```text
src/
└── DependencyInjection/      # container contracts, providers, factories, and wiring helpers

config/
└── services.php             # future application service definitions
```

`config/services.php` is a future convention and should not be added until there is a concrete container implementation.

## PSR-11 Boundary

NENE2 should expose or consume containers through `Psr\Container\ContainerInterface`.

Framework code should not depend on a concrete container implementation. Runtime code may accept a PSR-11 container at composition boundaries, such as:

- front controller bootstrap
- application factory
- middleware pipeline factory
- route handler resolver

## Explicit Wiring First

Explicit wiring is the default because it is:

- easy to review
- easy to test
- friendly to static analysis
- easy for AI agents to trace
- predictable during refactoring

Factories and service providers should show dependency construction directly. Avoid hidden class-name conventions as the primary mechanism.

## Initial Implementation

The first implementation uses `psr/container` as the PSR-11 interface package and a small internal container in `src/DependencyInjection/`.

The internal container is intentionally limited:

- services are registered explicitly with factories or values
- resolved entries are cached
- service providers group related registrations
- missing entries and resolution failures use PSR-11 compatible exceptions

NENE2 does not adopt a full external container package or autowiring as the initial default. If richer container behavior becomes necessary, it should be added through an adapter or a later ADR.

## Autowiring Policy

Autowiring is optional and not part of the first standard runtime.

If autowiring is introduced later:

- make it an application opt-in
- keep explicit wiring available
- document how parameters and interfaces are resolved
- test failure messages
- avoid making controller behavior depend on invisible magic

## Service Provider Policy

Service providers should be small and focused.

Recommended responsibilities:

- register related services
- bind interfaces to factories
- configure framework modules
- avoid reading request-specific state

Provider execution order must be explicit if ordering matters.

## HTTP Runtime Relationship

The HTTP runtime should not require a container for simple route handlers.

Container integration can be added at these edges:

- route handler resolution
- middleware construction
- error handler construction
- response factory creation

Do not make the router or middleware pipeline impossible to test without a container.

## Non-Goals for the First DI Step

- Full autowiring.
- Attribute-based service registration.
- Compile-time container generation.
- Global service locator usage inside domain or use-case code.
- Container-dependent domain objects.

## Future Implementation Issues

Follow-up work should decide:

- concrete PSR-11 package or minimal adapter
- service provider interface
- factory conventions
- application bootstrap shape
- test helpers for container wiring
