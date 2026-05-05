# View Rendering Self-Review

Use this checklist for native PHP templates, HTML response helpers, escaping, and future template adapters.

Source policies:

- `docs/development/view-rendering.md`
- `docs/development/http-runtime.md`
- `docs/development/coding-standards.md`
- `docs/development/documentation-comments.md`

## Checklist

- [ ] Server HTML remains thin and optional.
- [ ] Template source stays under `templates/`, not `public_html/`.
- [ ] Framework view abstractions stay under `src/View/`.
- [ ] Template variables are escaped through an explicit helper before output.
- [ ] Templates do not contain business logic.
- [ ] HTML responses set `text/html; charset=utf-8`.
- [ ] Missing or unsafe template paths fail predictably.
- [ ] Tests cover escaping behavior and response metadata for stable rendering behavior.
