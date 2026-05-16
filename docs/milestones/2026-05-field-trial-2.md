# Milestone: Field Trial 2 (Phase 15)

## Period

May 2026, after the `v0.1.3` checkpoint.

## Goal

Run a second field trial with the enhanced NENE2 stack:
- Structured JSON logging via Monolog (Phase 14)
- Complete Note CRUD + collection endpoint (Phases 8–13)
- Error handler systemization (Phase 11)
- HTTP-level test coverage (Phase 12)

Verify that the improvements from Field Trial 1 friction have been addressed and that the
framework is ready for a v0.2.0 readiness decision.

## Theme

- Confirm that structured logging makes request tracing observable
- Validate that collection endpoint pagination works in a real-use scenario
- Record any remaining friction for Phase 16 (v0.2.0 readiness)

## Scope

- Tag `v0.1.3` as a stable checkpoint on `main`
- Run at least one sandbox session against `v0.1.3`
- Record observations in `docs/field-trials/`
- Create follow-up Issues from new friction

## Acceptance Criteria

- `v0.1.3` tag exists on `main`
- `docs/todo/current.md` updated to Phase 15
- Milestone document exists (this file)
- Field trial session recorded (can be brief)

## Candidate Issues

- After trial: Phase 16 v0.2.0 readiness decisions (#209, TBD)
