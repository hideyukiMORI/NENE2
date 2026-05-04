# Package Selection Policy

NENE2 chooses dependencies conservatively and records major package choices before implementation.

## Position

Dependencies shape the framework as much as code does. Package selection should favor interoperability, replaceability, and long-term maintainability over novelty alone.

The standard direction is:

- Prefer PHP-FIG, W3C, OpenAPI, and ecosystem standards where they exist.
- Prefer small, focused packages over broad frameworks.
- Choose packages that are actively maintained.
- Keep concrete packages behind NENE2 adapters when they are infrastructure choices.
- Avoid dependencies that hide control flow from tests, static analysis, or AI tools.
- Record major package selections in ADRs.

This policy applies before selecting concrete PSR-7 / PSR-17, router, container, dotenv, logger, migration, OpenAPI, and frontend tooling packages.

## Evaluation Criteria

Evaluate candidate packages against:

- standards compliance
- maintenance activity
- release history
- license compatibility with MIT
- dependency footprint
- runtime weight
- testability
- static analysis friendliness
- documentation quality
- framework lock-in risk
- adapter or replacement strategy
- security history
- community adoption

No package needs to be perfect, but trade-offs should be explicit for major choices.

## Preferred Shape

Prefer packages that:

- implement standard interfaces such as PSR-7, PSR-11, PSR-15, PSR-17, or PSR-3
- work without global state
- can be configured explicitly
- are easy to test with fake implementations
- can be replaced without rewriting domain or use-case code
- are understandable from small examples

Avoid packages that:

- require hidden global containers
- depend on heavy framework runtime assumptions
- force unrelated conventions into NENE2 core
- require broad magic or code generation before the project needs it
- make public API behavior hard to trace

## ADR Requirement

Create an ADR when selecting a package that affects:

- HTTP runtime
- routing
- dependency injection
- configuration loading
- logging or observability
- database migrations
- OpenAPI validation or generation
- frontend starter architecture
- release or dependency automation

The ADR should compare the selected package with at least one reasonable alternative unless there is an obvious standard choice.

## Implementation Boundary

Use adapters or explicit providers for concrete infrastructure packages.

Framework domain and use-case code should not depend directly on package-specific implementations when a standard interface or local boundary is available.

Examples:

- depend on PSR-11 instead of a concrete container inside use cases
- depend on PSR-3 instead of Monolog outside logging adapters
- depend on PSR-7 interfaces at HTTP boundaries
- keep dotenv access inside configuration loading

## Version Policy

When adding a package:

- use the latest stable compatible major by default
- document intentional lag behind current stable releases
- avoid unstable pre-release packages unless the feature explicitly requires them
- commit lockfile updates when the package manager uses a lockfile

Dependency update automation should keep packages current after initial adoption.

## Non-Goals

- Rejecting every dependency in favor of custom code.
- Choosing packages only because they are new.
- Wrapping stable standard interfaces with unnecessary abstractions.
- Recording an ADR for every small development-only tool.
