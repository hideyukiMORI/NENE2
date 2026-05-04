# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Current milestone: `docs/milestones/2026-05-nene2-foundation.md`
- Current GitHub Issue: none
- Current branch: `main`

## Foundation Completed

- [x] Create GitHub Issue for initial governance docs. `#1`
- [x] Create Issue branch for initial governance docs. `docs/1-initial-governance`
- [x] Add README, AGENTS, workflow, roadmap, milestone, TODO, coding standards, and integration policy docs. `#1`
- [x] Add Cursor rules that summarize the docs. `#1`
- [x] Merge the initial governance PR. `#1`
- [x] Define standard project layout and create initial directories. `#3`
- [x] Add root Composer metadata, PHP runtime policy, PHPUnit, and PHPStan. `#5`
- [x] Add Docker-based PHP development environment and first smoke endpoint. `#7`
- [x] Add initial OpenAPI contract and Swagger UI. `#9`
- [x] Define thin HTML view and optional template engine policy. `#11`
- [x] Define database migration layout and tool selection policy. `#13`
- [x] Define PSR-first HTTP runtime and routing policy. `#15`
- [x] Define PSR-11 DI container and explicit wiring policy. `#16`
- [x] Define typed config and environment policy. `#17`

## Next Candidates

- [ ] Choose concrete PSR-7 / PSR-17 packages and router implementation.
- [ ] Choose concrete PSR-11 container package or adapter strategy.
- [ ] Implement typed config loader and `.env.example`.
- [ ] Expand the HTTP runtime skeleton beyond the smoke endpoint.
- [ ] Add OpenAPI contract validation to `composer check`.
- [ ] Add the first native PHP view renderer and escaping helper.
- [ ] Choose and wire the migration runner when the database adapter layer starts.
- [ ] Choose the first frontend starter direction or keep both React/Vue documented as optional.

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
