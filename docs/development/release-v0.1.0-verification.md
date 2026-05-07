# v0.1.0 Final Verification

This document records the final verification run for the `v0.1.0` release candidate.

It does not create a release tag.

## Verification Target

- Branch: `main`
- Commit: `a101cab`
- Date: 2026-05-07
- Related Issue: `#131`

## Local Verification

The documented release verification commands were run successfully:

```bash
docker compose run --rm app composer validate
docker compose run --rm app composer check
npm run check --prefix frontend
npm run build --prefix frontend
git diff --check
```

Results:

- Composer metadata is valid.
- PHPUnit, PHPStan, PHP-CS-Fixer, OpenAPI validation, and MCP catalog validation passed through `composer check`.
- Frontend TypeScript, ESLint, Prettier, and Vite production build passed.
- Whitespace diff check passed.

## GitHub Actions

The latest `main` push for the release candidate passed:

- Backend / `Composer Check`
- Frontend / `npm Check`

Run references:

- Backend: `25479856145`
- Frontend: `25479856125`

## Remaining Release Decisions

Before publishing `v0.1.0`:

- get explicit approval to tag `v0.1.0`
- decide whether to enable `main` branch protection before tagging
- create the GitHub Release from the tag using `docs/development/release-v0.1.0-notes.md`
- keep Packagist publication deferred
