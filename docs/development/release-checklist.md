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
git tag vX.Y.Z
git push origin vX.Y.Z
```

Do not tag unmerged PR branches or local-only commits.

## GitHub Release

**Create a GitHub Release for every tag.** Pushing a git tag alone does not reliably trigger the
Packagist webhook — the release creation fires the event that Packagist picks up.

```bash
gh release create vX.Y.Z \
  --title "vX.Y.Z — <short description>" \
  --notes "$(cat <<'EOF'
## What's New
...

## Full Changelog
[vX.Y-1.Z-1...vX.Y.Z](https://github.com/hideyukiMORI/NENE2/compare/vX.Y-1.Z-1...vX.Y.Z)
EOF
)"
```

## Packagist Verification

After creating the GitHub Release, confirm Packagist reflects the new version (allow up to 5 minutes):

```bash
curl -s "https://packagist.org/packages/hideyukimori/nene2.json" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); \
    versions=[v for v in d['package']['versions'].keys() if not v.startswith('dev-')]; \
    print(sorted(versions, reverse=True)[:3])"
```

If Packagist does not update within 5 minutes, trigger manually:

```bash
curl -XPOST -H'content-type:application/json' \
  'https://packagist.org/api/update-package?username=hideyukimori&apiToken=<TOKEN>' \
  -d '{"repository":{"url":"https://github.com/hideyukiMORI/NENE2"}}'
```

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
- Confirm Packagist reflects the new version.
- Confirm `docs/todo/current.md` does not list the release as incomplete if it is done.
- Open follow-up Issues for deferred release work.
- Sync the profile README ([github.com/hideyukiMORI/hideyukiMORI](https://github.com/hideyukiMORI/hideyukiMORI)) with the released version and status: update the relevant rows in At a glance / Runtimes / What shipped recently.
