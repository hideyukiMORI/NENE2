# Language Policy

NENE2 uses English for public-facing project contracts and allows Japanese for local operational support.

## Position

The repository should be easy to publish, easy to maintain, and comfortable for the current development workflow.

The standard direction is:

- Public project documentation is written in English.
- Code, API contracts, OpenAPI, and public error metadata are written in English.
- Local operational notes, Cursor rules, and AI collaboration summaries may use Japanese.
- Mixed language is acceptable only when the boundary is intentional and documented.

This policy prevents accidental language drift while keeping local development practical.

## English by Default

Use English for:

- `README.md`
- `docs/development/`
- `docs/integrations/`
- `docs/adr/`
- `docs/review/`
- OpenAPI documents
- public API descriptions
- Problem Details `title`, `type`, and validation `code`
- code identifiers, class names, method names, and variable names
- package metadata
- release notes intended for public users

English public docs make the framework easier to share, index, translate, and inspect with external tooling.

## Japanese Allowed

Japanese is allowed for:

- `.cursor/rules/`
- local TODO and handoff notes when they are primarily for the current maintainers
- GitHub Issues and PR bodies when the audience is the current project team
- AI collaboration notes
- comments in chat or review discussion

When a Japanese local note introduces a durable technical policy, update the English source-of-truth document as part of the same work.

## Source of Truth

Policy source-of-truth documents should be English.

Cursor rules may summarize the policy in Japanese, but they should not become the only place where a durable technical rule exists.

If a Japanese rule and an English policy conflict, update the English policy first, then adjust the local rule or note.

## Public Error and API Text

Public API text should be stable and English:

- Problem Details `title`
- validation `code`
- OpenAPI operation summaries
- schema descriptions
- public examples

Applications built with NENE2 may localize user-facing UI or application messages separately.

## Non-Goals

- Forcing every local project note to be English.
- Translating all historical Issues or chat summaries.
- Blocking Japanese discussion during design.
- Allowing local Japanese notes to override public English policy docs.
