# ADR 0001: Select HTTP Runtime Packages

## Status

accepted

## Context

NENE2 needs a minimal HTTP runtime that moves the smoke endpoint behind PSR-first request handling without adopting a heavy framework runtime. The first implementation must support PSR-7 HTTP messages, PSR-17 factories, PSR-15 middleware, explicit routing, and a replaceable response emission boundary.

The package selection should favor standards compliance, low dependency footprint, testability, static analysis friendliness, and clear replacement paths.

## Decision

Use `nyholm/psr7` as the initial PSR-7 and PSR-17 implementation.

Use `nyholm/psr7-server` to create PSR-7 server requests from PHP globals in the front controller.

Use `psr/http-server-middleware` and `psr/http-server-handler` for PSR-15 interfaces.

Do not add a router package for the first runtime step. NENE2 will use a small internal route table that matches HTTP method and path only.

Do not add a middleware dispatcher package for the first runtime step. NENE2 will use a small internal dispatcher that composes PSR-15 middleware explicitly.

Do not add a response emitter package for the first runtime step. NENE2 will use a small internal emitter for status, headers, and response body output.

Alternatives considered:

- `laminas/laminas-diactoros`: mature PSR-7 and PSR-17 implementation, but larger than needed for the first skeleton.
- `guzzlehttp/psr7`: widely adopted PSR-7 implementation, but `nyholm/psr7` provides the smallest fit for combined PSR-7 / PSR-17 usage in this stage.
- `nikic/fast-route`: fast and well-known router, but path parameters and advanced dispatch are not required for the smoke endpoint.
- A full framework runtime such as Slim or Symfony HttpKernel: useful for applications, but too much default structure for NENE2 framework-core decisions at this point.

## Consequences

The first runtime remains small and easy to inspect. Public HTTP boundaries use standard PSR interfaces, while concrete package usage stays near the front-controller composition boundary.

NENE2 can replace the PSR-7 implementation later because handlers and middleware depend on interfaces. Routing can grow incrementally or move to a package when path parameters, route groups, or compiled dispatch become necessary.

The internal emitter and dispatcher must stay intentionally small. If they gain broad behavior, a follow-up ADR should reconsider adopting focused packages.

## Related

- Issue: `#43`
- PR: `#000`
- Supersedes: none
- Superseded by: none
