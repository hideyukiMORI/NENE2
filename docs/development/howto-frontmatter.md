# Howto Frontmatter Schema

Every guide in `docs/howto/` carries a small YAML frontmatter block so that
category and tag indexes can be regenerated from data instead of hand-curated
lists. The schema is intentionally minimal — rich schemas do not get filled in.

See `docs/todo/howto-curation-strategy.md` for the rollout plan (Phase B).

## Fields

```yaml
---
title: Add JWT Authentication
category: auth
tags: [jwt, bearer, authentication]
difficulty: intermediate
related: [use-bearer-auth, refresh-token-rotation]
ft: FT102
---
```

| Field | Required | Type | Rule |
|---|---|---|---|
| `title` | yes | string | Human-readable title; mirror the page `# H1`. |
| `category` | yes | enum | Exactly one of the categories below. |
| `tags` | yes | list of strings | 1–6 lowercase kebab-case tags (`[a-z0-9-]+`). |
| `difficulty` | yes | enum | `beginner` \| `intermediate` \| `advanced`. |
| `related` | no | list of slugs | Other howto file names without `.md`; each must exist. |
| `ft` | no | string | Field-trial id, `FT` followed by digits (cross-references `docs/ft-registry.md`). |

Unknown keys are rejected to keep the schema tight.

## Category values

The seven categories mirror the manual sections in `docs/howto/README.md`:

| Slug | README section |
|---|---|
| `getting-started` | Getting Started |
| `auth` | Authentication & Authorization |
| `security` | Security |
| `database` | Database |
| `api-design` | API Design |
| `infrastructure` | Background & Infrastructure |
| `product` | Product Features (Recipe Patterns) |

A guide has exactly one primary `category`. Cross-cutting topics are expressed
through `tags`, not by inventing new categories.

## Validation

```bash
composer howto:frontmatter                 # strict on annotated guides, lenient on missing
composer howto:frontmatter -- --require-all # also fail when any guide is unannotated
```

Every guide is now annotated, so CI runs the `--require-all` form: a new guide
without a frontmatter block fails the build. `README.md` and `by-tag.md` are
indexes, not guides, and are excluded from validation.

## Generated indexes

The frontmatter is the data source for `composer howto:index`
(`tools/build-howto-index.php`), which regenerates:

- `docs/howto/README.md` — the "Browse by category" section (between the
  `AUTO-INDEX` markers), grouping every guide by `category` with its
  `difficulty` and `tags`. The hand-picked "I want to…" finder table above the
  markers is left untouched.
- `docs/howto/by-tag.md` — every guide grouped by `tag`.
- Other locales' `README.md` — a flat, `# H1`-based index (translations carry
  no frontmatter).

CI re-runs the generator and fails on any drift, so the indexes never go stale.
