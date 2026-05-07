# v0.1.x Patch Release Policy

This document records how NENE2 should handle small `v0.1.x` releases after the first `v0.1.0` foundation release.

It does not create a tag.

## Position

`v0.1.x` patch releases are useful when a completed increment makes the existing `v0.1.0` foundation easier to use without changing its overall product shape.

Use a patch release for small, self-contained improvements such as:

- bug fixes in shipped runtime, configuration, tests, or tooling
- documentation corrections that materially improve setup or handoff
- local verification improvements that do not change public contracts
- LLM Delivery Starter increments that complete an already-scoped milestone candidate

Use a later minor release such as `v0.2.0` for changes that broaden the framework surface:

- new public extension points
- substantial runtime behavior changes
- authentication or authorization policy beyond the current machine API-key path
- production MCP deployment behavior
- generated endpoint or MCP tooling that changes the development workflow
- Packagist publication or support expectations for external users

## Candidate `v0.1.x` Baseline

The first post-`v0.1.0` patch release can include the completed LLM Delivery Starter foundation increments if the maintainer wants a checkpoint tag:

- local MCP server for read-only OpenAPI-aligned tools
- API-key middleware for machine-client requests
- endpoint scaffold workflow with the first example endpoint
- Docker Compose MySQL verification as an opt-in real database check

These changes are useful together as a delivery-starter checkpoint, but they do not require an immediate release tag.

The `v0.1.1` checkpoint decision and candidate scope are recorded in `docs/development/release-v0.1.1-prep.md`.

## Before Tagging

Before tagging a `v0.1.x` release:

1. Confirm all included Issues and PRs are merged to `main`.
2. Confirm Backend and Frontend GitHub Actions pass on the release commit.
3. Run the manual release checklist in `docs/development/release-checklist.md`.
4. Draft short GitHub Release notes with highlights, verification, related Issues, and known follow-up work.
5. Get explicit release approval before creating and pushing the tag.

The tag must point to an up-to-date `main` commit and use the `vX.Y.Z` format.

## Non-Goals

- No automatic patch release after every merged PR.
- No release tag without explicit approval.
- No Packagist publication as part of `v0.1.x` by default.
- No promise of `1.0.0` public API stability.

## Related Work

- Release policy: `docs/development/release-ci.md`
- Release checklist: `docs/development/release-checklist.md`
- `v0.1.1` checkpoint preparation: `docs/development/release-v0.1.1-prep.md`
- LLM Delivery Starter milestone: `docs/milestones/2026-05-llm-delivery-starter.md`
- Current TODO: `docs/todo/current.md`
- GitHub Issue: `#146`
