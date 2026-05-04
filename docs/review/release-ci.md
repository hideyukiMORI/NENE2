# Release and CI Self-Review

Use this checklist for GitHub Actions, release policy, versioning, dependency update automation, tags, and Packagist-related work.

Source policies:

- `docs/development/release-ci.md`
- `docs/development/quality-tools.md`
- `docs/development/frontend-integration.md`
- `docs/workflow.md`

## Checklist

- [ ] CI starts from documented local commands before becoming required.
- [ ] Backend CI includes Composer validation, install, and `composer check` when appropriate.
- [ ] Frontend CI uses `npm ci --prefix frontend` only after `frontend/package-lock.json` exists.
- [ ] OpenAPI validation is not made required before schemas and commands exist.
- [ ] Release tags use `vX.Y.Z` and point to commits on `main`.
- [ ] `0.x.y` releases are not described as long-term public API stability promises.
- [ ] Dependency update automation targets are explicit, with Dependabot preferred first.
- [ ] Workflow changes are documented with verification and any manual push limitations.
