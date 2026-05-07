# Release Checklist

Use this checklist for manual `v0.x.y` releases until release automation is introduced.

Preparation notes for the first `v0.1.0` release live in `docs/development/release-v0.1.0-prep.md`.
Patch release criteria for `v0.1.x` live in `docs/development/release-v0.1.x-policy.md`.
Preparation notes for the `v0.1.1` checkpoint candidate live in `docs/development/release-v0.1.1-prep.md`.

## Preconditions

- The release is created from `main`.
- `main` is up to date with `origin/main`.
- The working tree is clean.
- Relevant Issues and PRs are merged or explicitly deferred.
- `docs/todo/current.md` reflects the actual project state.
- Public behavior changes are documented in source-of-truth docs and OpenAPI when applicable.

## Verification

Run the backend verification path before tagging:

```bash
docker compose run --rm app composer validate
docker compose run --rm app composer check
git diff --check
```

If Docker is unavailable, run the equivalent Composer commands in a PHP `8.4` environment and record the limitation in the release notes.

## Version Selection

- Use `vX.Y.Z` tags.
- Use `0.x.y` while public contracts are still forming.
- Use a minor version for meaningful framework surface changes.
- Use a patch version for small fixes or documentation corrections.
- Use a `v0.1.x` patch version for small completed delivery-starter increments only when they do not broaden the public framework surface.
- Do not describe `0.x.y` releases as long-term public API stability promises.

## Tagging

Create tags only from `main` after verification:

```bash
git switch main
git pull --ff-only origin main
git tag v0.x.y
git push origin v0.x.y
```

Do not tag unmerged PR branches or local-only commits.

## GitHub Release Notes

Release notes should include:

- highlights
- breaking changes, or `None`
- migration notes, or `None`
- verification summary
- related Issues and PRs
- remaining known risks or follow-up work

## After Release

- Confirm the GitHub Release points to the expected tag.
- Confirm `docs/todo/current.md` does not list the release as incomplete if it is done.
- Open follow-up Issues for deferred release work.
- Leave Packagist publication deferred until Composer package contracts are stable enough for early users.
