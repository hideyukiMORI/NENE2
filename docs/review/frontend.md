# Frontend Self-Review

Use this checklist for the React + TypeScript starter, frontend tooling, API client helpers, and built asset integration.

Source policies:

- `docs/development/frontend-integration.md`
- `docs/development/quality-tools.md`
- `docs/development/documentation-comments.md`
- `docs/development/project-layout.md`

## Checklist

- [ ] Framework core does not depend on frontend packages or build output.
- [ ] Frontend source stays under `frontend/`, not `public_html/`.
- [ ] Built assets are generated into `public_html/assets/` only when safe to serve.
- [ ] npm remains the official starter package manager unless policy changes.
- [ ] `package-lock.json` is committed when frontend dependencies exist.
- [ ] `node_modules/` and generated assets are not committed by default.
- [ ] Exported frontend utilities, hooks, and shared types use TSDoc when they expose public semantics.
- [ ] Frontend checks were run when available, usually `npm run check --prefix frontend`.
- [ ] PHP checks were still considered when backend integration behavior changed.
