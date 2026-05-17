# Milestone: Phase 20 — Packagist Field Trial

## Period

May 2026, after Packagist registration of v0.3.0.

## Goal

Validate `composer require hideyukimori/nene2` as a credible starting point for new projects.

All prior field trials used the NENE2 repository itself as the sandbox. This trial starts from a fresh directory with no framework source files — only the installed Composer dependency. The goal is to surface friction that only appears when using the framework as a library consumer.

## Acceptance Criteria

- [ ] A new project directory is created outside the NENE2 repository
- [ ] `composer require hideyukimori/nene2` installs successfully
- [ ] A minimal working HTTP application is wired up manually (front controller, `.env`, server)
- [ ] At least one endpoint returns a JSON response
- [ ] The endpoint scaffold workflow is traced from the consumer perspective
- [ ] A field trial report is recorded in `docs/field-trials/`
- [ ] Follow-up Issues are created for any friction discovered
- [ ] `docs/todo/current.md` is updated

## Scope

- No changes to framework source code during the trial (observations only)
- The trial application is temporary and lives outside this repository
- Friction findings become the input for Phase 21+

## Candidate Follow-up Areas

- Does `client-project-start.md` need a `composer require` path?
- Is `RuntimeApplicationFactory` usable without the full repository scaffolding?
- What minimum files does a consumer need to create?
- Does the front controller pattern work outside the Docker image provided with the NENE2 repo?
