# Milestone: Error Handler Systemization (Phase 11)

## Goal

Centralize domain exception → HTTP response mapping in the middleware layer so that
handlers stay thin and adding new domain exceptions does not require handler changes.

## Completed

- `DomainExceptionHandlerInterface` added to `src/Error/`
- `ErrorHandlerMiddleware` accepts `list<DomainExceptionHandlerInterface>`; iterates in `catch(Throwable)` block
- `NoteNotFoundExceptionHandler` added to `src/Example/Note/`; maps `NoteNotFoundException` → 404 Problem Details
- `GetNoteByIdHandler` and `DeleteNoteHandler` no longer inject `ProblemDetailsResponseFactory` or catch domain exceptions
- `NoteServiceProvider` registers `NoteNotFoundExceptionHandler`
- `RuntimeServiceProvider` wires `NoteNotFoundExceptionHandler` into `RuntimeApplicationFactory`
- `NoteNotFoundExceptionHandlerTest` added

## Outcome

Handlers follow a consistent shape: parse input → call use case → return response.
Exception → HTTP mapping is owned entirely by the middleware layer.
New domain exception types require only a new `DomainExceptionHandlerInterface` implementation,
registered in the relevant service provider and wired at the factory level.

Merged via PR #198. Closes #197.
