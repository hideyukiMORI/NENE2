# Documentation and Policy Self-Review

Use this checklist for policy docs, workflow docs, ADRs, roadmap updates, TODO updates, and Cursor-facing rules.

Source policies:

- `docs/workflow.md`
- `docs/development/adr.md`
- `docs/development/coding-standards.md`
- `docs/development/self-review.md`
- `docs/todo/current.md`
- `docs/roadmap.md`

## Checklist

- [ ] The source-of-truth policy doc was updated instead of only adding a summary elsewhere.
- [ ] Related `docs/roadmap.md` or `docs/todo/current.md` state was updated when project state changed.
- [ ] New major architecture, dependency, public contract, or release decisions considered whether an ADR is needed.
- [ ] The change avoids duplicating full policy text across multiple files.
- [ ] New checklist items link to source policy docs instead of becoming a second source of truth.
- [ ] Issue and PR references are included where useful.
- [ ] The wording is concrete enough for both humans and AI agents to follow.
- [ ] Docs diagnostics and whitespace checks were reviewed.
