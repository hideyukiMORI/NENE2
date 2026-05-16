# Write Operations Pattern

## Period

May 2026 and after the Domain Layer Starter milestone.

## Goal

Complete the CRUD template by adding POST (create) and DELETE endpoints to the domain layer example. Client projects should have a working write-operation pattern they can copy and adapt without guessing at conventions.

## Theme

Phase 9 proved the read path: UseCase → Repository → Handler with unit and integration tests. The gap is write operations, which introduce new concerns:

- request body parsing and format validation
- `ValidationException` → 422 Problem Details for missing or invalid fields
- 201 Created with `Location` header for resource creation
- 204 No Content for deletion
- write methods on `NoteRepositoryInterface` and `PdoNoteRepository`
- `lastInsertId()` boundary on `DatabaseQueryExecutorInterface`

These are standard but currently undocumented in the framework. Adding them to the notes example gives every client project a concrete, tested reference.

## Scope

- add `post()` and `delete()` methods to `src/Routing/Router`
- add `lastInsertId()` to `DatabaseQueryExecutorInterface` and `PdoDatabaseQueryExecutor`
- add `save(Note $note): int` and `delete(int $id): void` to `NoteRepositoryInterface` and `PdoNoteRepository`
- add `CreateNoteInput` / `CreateNoteOutput` readonly DTOs
- add `CreateNoteUseCaseInterface` / `CreateNoteUseCase`
- add `DeleteNoteByIdInput`
- add `DeleteNoteUseCaseInterface` / `DeleteNoteUseCase`
- add `CreateNoteHandler` — validates body, returns 201 Created with Location header
- add `DeleteNoteHandler` — returns 204 No Content, 404 if note absent
- wire new services in `NoteServiceProvider`
- register POST and DELETE routes in `RuntimeApplicationFactory`
- add OpenAPI paths and schemas for POST and DELETE operations
- add PHPUnit unit tests for `CreateNoteUseCase` and `DeleteNoteUseCase`
- extend `PdoNoteRepositoryTest` with write operation coverage
- extend `InMemoryNoteRepository` with write operation support

## Acceptance Criteria

- `POST /examples/notes` with valid body returns 201 Created, `Location` header, and note JSON.
- `POST /examples/notes` with missing or empty fields returns 422 Validation Failed with structured errors.
- `DELETE /examples/notes/{id}` for an existing note returns 204 No Content.
- `DELETE /examples/notes/{id}` for a missing note returns 404 Not Found.
- `CreateNoteUseCase` and `DeleteNoteUseCase` have PHPUnit unit tests that run without a database.
- `PdoNoteRepositoryTest` covers `save()` and `delete()` with SQLite in-memory.
- OpenAPI schemas for POST and DELETE pass `composer openapi`.
- `composer check` passes.

## Non-Goals

- PUT or PATCH operations.
- Database container required at runtime (SQLite remains the default; MySQL stays opt-in).
- Write MCP tools (deferred to a later milestone).
- Pagination or list endpoints (separate milestone).
- ActiveRecord or heavy ORM patterns.

## Related Work

- Previous milestone: `docs/milestones/2026-05-domain-layer-starter.md`
- Domain layer policy: `docs/development/domain-layer.md`
- Request validation policy: `docs/development/request-validation.md`
- Database adapter boundaries: `src/Database/`
- Example note domain: `src/Example/Note/`
- GitHub Issue: `#188`
