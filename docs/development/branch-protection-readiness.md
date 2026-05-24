# Branch Protection Readiness

`main` should be protected before NENE2 releases become public.

This checklist documents the expected protection shape. It does not change GitHub repository settings by itself.

## Required Checks

The current required check candidates are:

- `Composer Check` from `.github/workflows/backend.yml`
- `npm Check` from `.github/workflows/frontend.yml`

Do not require a check before the workflow has run successfully on pull requests at least once.

## CI Trigger Policy

Backend and Frontend workflows run on **pull requests only** (plus `workflow_dispatch`). They do not re-run on `main` push after merge — branch protection required checks on the PR are the merge gate.

The Docs workflow runs on **`main` push only** to build and deploy GitHub Pages.

Both Backend and Frontend use `concurrency` with `cancel-in-progress: true` so only the latest commit on a PR branch is checked.

## Recommended Protection

Recommended `main` protection:

- require pull requests before merging
- require the latest `Composer Check`
- require the latest `npm Check`
- require branches to be up to date before merging when GitHub reports stale checks clearly
- block force pushes
- block branch deletion
- keep merge commits unless the project deliberately changes history policy
- avoid allowing bypasses except for repository administrators during documented emergencies

## Readiness Checklist

Before enabling or tightening branch protection:

- [x] `docs/workflow.md` describes the PR-first workflow.
- [x] `.github/workflows/backend.yml` runs on pull requests.
- [x] `.github/workflows/frontend.yml` runs on pull requests.
- [x] Backend and Frontend workflows pass on pull requests.
- [x] `composer check` passes locally or in CI.
- [x] `npm run check --prefix frontend` and `npm run build --prefix frontend` pass in CI.
- [x] The team agrees merge commits remain the default.

## Current Decision

As of 2026-05-23, `main` is protected via GitHub ruleset **main protection** (active).

Decision:

- Require pull requests before merging to `main`.
- Require `Composer Check` and `npm Check` as status checks on pull requests.
- Block force pushes and branch deletion on `main`.
- Keep merge commits as the default history policy.
- Backend/Frontend CI runs on PR only; Docs CI runs on `main` push for GitHub Pages deploy.
- Avoid bypass except for documented emergencies.

Reason:

- Workflows pass reliably on pull requests.
- Duplicate Backend/Frontend runs on every `main` push were removed to reduce CI usage.
- Branch protection ensures no merge without passing PR checks.

## Rollout Notes

Start with the smallest effective protection:

1. Require pull requests.
2. Require `Composer Check` and `npm Check`.
3. Disable force pushes and branch deletion.
4. Add stricter review or stale-branch rules only after the workflow is stable.

If a required check is renamed, update this document and `docs/development/release-ci.md` in the same PR.

## Emergency Changes

Emergency bypasses should be rare and documented after the fact.

If an emergency change bypasses the normal PR path:

- record the reason in an Issue
- run the normal verification after the change
- open a follow-up PR if docs, tests, or workflow files need cleanup
- confirm `main` returns to passing CI
