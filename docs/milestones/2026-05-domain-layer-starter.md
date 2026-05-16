# Domain Layer Starter

## Period

May 2026 and after the First LLM Field Trial milestone.

## Goal

Add a minimal, conventional domain layer pattern to NENE2 so that application code has a clear, documented path from HTTP handler to use case to repository adapter.

This milestone should turn "the infrastructure is in place" into "there is a working pattern for building real features on top of it."

## Theme

The field trial exposed the central gap: NENE2's infrastructure layer (routing, DI, config, database, MCP) is in place, but a client project that clones the repository has no example of how a real endpoint should delegate to a use case and repository. The only examples are `getHealth` and `getFrameworkSmoke`, which test infrastructure rather than application logic.

The next work should add a domain layer convention without making NENE2 a full-stack framework:

- a documented UseCase interface and invocation pattern
- a documented RepositoryInterface convention for database-backed adapters
- at least one working example endpoint that uses these patterns end to end
- updated workflow docs so endpoint scaffold and self-review checklists cover domain concerns
- unit tests for use cases and integration tests for repository adapters

## Scope

- write a domain layer policy doc (`docs/development/domain-layer.md`) covering:
  - UseCase interface and single-responsibility invocation
  - RepositoryInterface convention and adapter naming
  - readonly input and output DTOs for cross-layer data passing
  - where application code lives relative to `src/` framework primitives
  - how to register use cases and repositories in the PSR-11 container
- add at least one example use case, repository interface, and PDO adapter in `src/`
- add at least one example handler that delegates to the use case (replacing or augmenting a smoke-only endpoint)
- add OpenAPI schema entries for the example endpoint
- add PHPUnit tests: unit tests for the use case, integration tests for the PDO adapter
- update `docs/development/endpoint-scaffold.md` to reference domain layer patterns
- update `docs/development/client-project-start.md` to point at the domain layer doc
- update self-review checklists to cover domain layer concerns (no business logic in handlers, no raw SQL outside adapters)

## Acceptance Criteria

- `docs/development/domain-layer.md` exists and defines the UseCase/RepositoryInterface/DTO conventions.
- At least one working example endpoint demonstrates the full UseCase → Repository → Handler path.
- The example endpoint has PHPUnit coverage: use case unit tests and repository adapter integration tests.
- OpenAPI schema for the example endpoint is present and passes `composer openapi`.
- `docs/development/endpoint-scaffold.md` references the domain layer policy.
- `docs/development/client-project-start.md` references the domain layer policy.
- Self-review checklists include domain layer checkpoints.
- `docs/todo/current.md` points at this milestone and lists concrete next candidates.

## Candidate Issues

- Define the Phase 9 milestone for Domain Layer Starter. `#180`
- Write the domain layer policy doc (`docs/development/domain-layer.md`).
- Add a minimal UseCase interface and example use case in `src/`.
- Add a RepositoryInterface convention and example PDO adapter.
- Add an example handler that delegates to the use case.
- Add OpenAPI schema entries for the example domain endpoint.
- Add PHPUnit unit tests for the example use case.
- Add PHPUnit integration tests for the example PDO adapter.
- Update `docs/development/endpoint-scaffold.md` to reference domain layer patterns.
- Update `docs/development/client-project-start.md` to reference the domain layer doc.
- Update self-review checklists with domain layer checkpoints.
- Decide whether Phase 9 warrants a `v0.1.2` patch release.

## Non-Goals

- Laravel-style Eloquent models or active record patterns.
- Automatic code generation or scaffolding commands.
- Event sourcing or CQRS patterns in the first pass.
- Multiple example domains or complex aggregate roots.
- Production deployment patterns.
- Full test coverage of all infrastructure boundaries (only the example domain path).

## Completion Record

All acceptance criteria were met by the end of May 2026.

- `docs/development/domain-layer.md` defines the UseCase/RepositoryInterface/DTO conventions. `#182`
- `GET /examples/notes/{id}` demonstrates the full UseCase → Repository → Handler path. `#184`
- `GetNoteByIdUseCaseTest` covers the use case with an in-memory repository. `#184`
- `PdoNoteRepositoryTest` covers the PDO adapter with SQLite in-memory. `#184`
- OpenAPI schema for the example endpoint is present and passes `composer openapi`. `#184`
- `docs/development/endpoint-scaffold.md` references domain layer patterns. `#182`
- `docs/development/client-project-start.md` references the domain layer policy. `#182`
- Self-review checklists include domain layer checkpoints. `#182`
- `docs/todo/current.md` is up to date. `#186`

`v0.1.2` patch release preparation is recorded in `docs/development/release-v0.1.2-prep.md`.

## Related Work

- Previous milestone: `docs/milestones/2026-05-field-trial.md`
- Database adapter boundaries: `src/Database/`
- PSR-11 container and wiring: `src/DependencyInjection/`
- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Client project start guide: `docs/development/client-project-start.md`
- Database test strategy: `docs/development/test-database-strategy.md`
- Coding standards: `docs/development/coding-standards.md`
- GitHub Issue: `#180`
