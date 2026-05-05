# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Current milestone: `docs/milestones/2026-05-nene2-foundation.md`
- Current GitHub Issue: none
- Current branch: `main`
- Handoff for next chat: `docs/todo/handoff-2026-05-04-implementation-start.md`
- First implementation task: `docs/todo/first-task-2026-05-04-http-runtime-foundation.md`

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
- [x] Define RFC 9457 Problem Details API error response policy. `#18`
- [x] Define middleware and security baseline policy. `#19`
- [x] Define quality tools and documentation comments policy. `#20`
- [x] Define request validation policy. `#21`
- [x] Define frontend integration and toolchain policy. `#22`
- [x] Define release, versioning, and CI policy. `#23`
- [x] Define ADR operation policy. `#24`
- [x] Define logging and observability policy. `#33`
- [x] Define self-review checklist policy. `#37`
- [x] Define implementation readiness guardrails. `#39`
- [x] Add implementation-start handoff and first task instructions. `#41`
- [x] Implement HTTP runtime foundation. `#43`
- [x] Choose concrete PSR-7 / PSR-17 packages and initial router strategy. `#43`
- [x] Write ADR 0001 for concrete HTTP runtime and router selections. `#43`
- [x] Choose concrete PSR-11 container package or adapter strategy. `#45`
- [x] Write ADR 0002 for concrete container selections. `#45`
- [x] Add minimal PSR-11 container foundation. `#45`
- [x] Implement typed config loader and `.env.example`. `#47`
- [x] Add OpenAPI Problem Details schemas. `#49`
- [x] Add validation error mapping and readonly DTO examples. `#51`
- [x] Add request id, CORS, security headers, and request size middleware skeletons. `#53`
- [x] Add PSR-3 logging adapter and request id log context. `#55`
- [x] Add PHP-CS-Fixer configuration and Composer scripts. `#57`
- [x] Add the first native PHP view renderer and escaping helper. `#59`
- [x] Add release checklist and first `v0.x.y` release preparation. `#61`

## Next Candidates

- [ ] Publish or redirect `https://nene2.dev/problems/*` before public error contracts are stable.
- [ ] Keep self-review checklists updated as new implementation areas are introduced.
- [ ] Add React + TypeScript frontend starter and ESLint / Prettier baseline.
- [ ] Add npm package metadata, Node engines, package lock, and frontend check scripts.
- [ ] Add Dependabot or Renovate policy for PHP and frontend dependencies.
- [ ] Add first GitHub Actions workflow for backend Composer checks.
- [ ] Expand the HTTP runtime skeleton beyond the smoke endpoint.
- [ ] Add OpenAPI contract validation to `composer check`.
- [ ] Choose and wire the migration runner when the database adapter layer starts.
- [ ] Add metrics and error tracking adapter policy details when integrations start.

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
