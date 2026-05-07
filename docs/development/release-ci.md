# Release, Versioning, and CI Policy

NENE2 uses SemVer, small release steps, and GitHub Actions as the standard CI direction.

## Position

Release and CI policy should keep the framework safe to change while the public API is still forming.

Manual release steps are tracked in `docs/development/release-checklist.md`.
The first release preparation notes live in `docs/development/release-v0.1.0-prep.md`.

The standard direction is:

- Use Semantic Versioning.
- Grow the framework through `0.x.y` releases until the public contracts are stable.
- Use Git tags as the release source.
- Use GitHub Releases for release notes.
- Add CI checks incrementally as tools become part of the documented `check` commands.
- Keep Packagist publication as a later step after Composer package contracts are more stable.
- Start dependency update automation with Dependabot before introducing heavier automation.

This Issue defines the policy only. GitHub Actions workflow files should be added in a focused follow-up Issue.

## Versioning

NENE2 follows Semantic Versioning.

During early framework development, use `0.x.y` versions:

- `0.x.y` means public contracts are still forming.
- Minor releases may include meaningful framework surface changes.
- Patch releases should be small fixes or documentation corrections.
- Avoid `1.0.0` until HTTP runtime, DI, config, validation, error responses, and OpenAPI contract conventions are stable enough for users.

After `1.0.0`, these changes are breaking changes and require a major version:

- incompatible public PHP API changes
- incompatible configuration changes
- incompatible CLI behavior
- incompatible documented middleware behavior
- incompatible OpenAPI public contract changes
- removal of documented extension points

## Tags

Release tags should use the `v` prefix:

```text
v0.1.0
v0.2.0
v1.0.0
```

Tags should point to commits on `main`.

Do not tag unreleased local work or unmerged PR branches.

## GitHub Releases

GitHub Releases should be created from tags.

Release notes should include:

- highlights
- breaking changes, if any
- migration notes, if any
- verification summary
- related Issues or PRs

Manual release notes are acceptable at first. Automated release note generation can be introduced when the release process becomes repetitive.

## CI Baseline

The first GitHub Actions workflow should verify backend quality.
The initial backend workflow lives at `.github/workflows/backend.yml`.

Recommended first CI steps:

```text
1. checkout
2. set up PHP 8.4
3. validate composer.json
4. install Composer dependencies
5. run composer check
```

`composer check` is the backend source of truth for the standard local verification path.

Do not add frontend or OpenAPI checks to required CI before their commands and configs are committed.

## Future CI Expansion

As tooling is implemented, CI should expand in this order:

1. PHP-CS-Fixer check after configuration is committed.
2. OpenAPI validation after shared schemas and validation commands exist.
3. Frontend check after `frontend/package.json` and `package-lock.json` exist.
4. Dependency update checks after Dependabot or Renovate is configured.
5. Release workflow after manual release steps are stable.

The initial frontend workflow lives at `.github/workflows/frontend.yml` and runs:

```text
1. set up Node.js active LTS
2. npm ci --prefix frontend
3. npm run check --prefix frontend
4. npm run build --prefix frontend
```

## Branch Protection

`main` should be protected before releases become public.

Readiness details and the current required check candidates live in `docs/development/branch-protection-readiness.md`.

Recommended branch protection:

- require PRs before merging
- require CI checks before merging
- avoid direct commits to `main`
- keep merge commits unless the project deliberately changes history policy
- do not force-push to `main`

Merge commits are acceptable because they preserve the Issue and PR lifecycle clearly.

## Packagist

Packagist publication is a goal, but not required before the framework surface is useful.

Before Packagist publication:

- Composer metadata should be accurate.
- Public PHP namespaces should be stable enough for early users.
- License and README should be complete.
- Tags should follow the versioning policy.
- Release notes should explain support expectations.

## Dependency Updates

Dependabot is the preferred first dependency update tool.

Initial targets:

- Composer dependencies
- GitHub Actions
- npm dependencies

The initial Dependabot configuration is in `.github/dependabot.yml`.

Renovate can be considered later if grouping, scheduling, or advanced dependency policies become important.

Dependency update PRs should still run the same CI checks as normal PRs.

## Release Checklist

Before tagging a release, use `docs/development/release-checklist.md`. At minimum:

- `main` is up to date.
- Required CI checks are passing.
- Local `composer check` passes when practical.
- Documentation for changed public behavior is updated.
- `docs/todo/current.md` does not claim an incomplete release task is done.
- Release notes are prepared.
- The tag follows `vX.Y.Z`.

## Non-Goals

- Publishing to Packagist before the framework surface is useful.
- Requiring frontend CI before the frontend starter exists.
- Requiring OpenAPI validation before shared schemas and commands exist.
- Introducing complex release automation before manual releases are understood.
- Treating `0.x.y` as a promise of long-term public API stability.
