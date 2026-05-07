# Branch Protection Readiness

`main` should be protected before NENE2 releases become public.

This checklist documents the expected protection shape. It does not change GitHub repository settings by itself.

## Required Checks

The current required check candidates are:

- `Composer Check` from `.github/workflows/backend.yml`
- `npm Check` from `.github/workflows/frontend.yml`

Do not require a check before the workflow has run successfully on both pull requests and `main` pushes.

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

- [ ] `docs/workflow.md` describes the PR-first workflow.
- [ ] `.github/workflows/backend.yml` runs on pull requests and pushes to `main`.
- [ ] `.github/workflows/frontend.yml` runs on pull requests and pushes to `main`.
- [ ] The latest `main` push has passing Backend and Frontend workflow runs.
- [ ] `composer check` passes locally or in CI.
- [ ] `npm run check --prefix frontend` and `npm run build --prefix frontend` pass in CI.
- [ ] `docs/todo/current.md` does not list an incomplete CI setup task as complete.
- [ ] The team agrees whether merge commits remain the default.

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
