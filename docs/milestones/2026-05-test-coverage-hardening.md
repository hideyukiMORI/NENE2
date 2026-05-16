# Milestone: Test Coverage Hardening (Phase 12)

## Goal

Close the gap between unit-level use case tests and HTTP-level integration by adding
end-to-end tests for the Note endpoints through the full middleware stack.

Phase 9/10 added UseCase and PDO adapter tests. Phase 11 moved domain exception
mapping to `ErrorHandlerMiddleware`. Neither phase added HTTP-level tests that verify
the complete middleware → router → handler → middleware (error) path for Note endpoints.

## Scope

- Add Note endpoint HTTP-level tests in `HttpRuntimeTest` or a dedicated `NoteHttpTest`
- Cover every meaningful response variation for the three Note endpoints
- Ensure `DomainExceptionHandlerInterface` integration is exercised end-to-end

## Deliverables

### HTTP-level test cases

| Endpoint | Scenario | Expected |
|---|---|---|
| `GET /examples/notes/{id}` | valid id, note exists | 200 + JSON body |
| `GET /examples/notes/{id}` | valid id, note absent | 404 Problem Details |
| `GET /examples/notes/0` | invalid id (≤ 0) | 404 Problem Details |
| `POST /examples/notes` | valid body | 201 + Location header |
| `POST /examples/notes` | missing title | 422 Problem Details with errors array |
| `POST /examples/notes` | missing body field | 422 Problem Details with errors array |
| `DELETE /examples/notes/{id}` | note exists | 204 No Content |
| `DELETE /examples/notes/{id}` | note absent | 404 Problem Details |

### Documentation

- Update `docs/todo/current.md` to reflect Phase 12

## Completion Record

- [ ] All test cases above pass under `composer check`
- [ ] No new PHPStan errors
- [ ] `docs/todo/current.md` updated
