# ADR Policy

NENE2 uses lightweight Architecture Decision Records for decisions that future maintainers should be able to understand without reading old chats.

## Position

ADRs are not meeting minutes. They are a small set of decision records that preserve architectural intent.

The standard direction is:

- Store ADRs in `docs/adr/`.
- Use numbered Markdown files.
- Keep ADRs short and decision-focused.
- Record only decisions that affect architecture, public contracts, dependencies, releases, or long-term maintenance.
- Keep day-to-day task detail in Issues, PRs, roadmap, and TODO files.
- Supersede old ADRs instead of editing history to hide previous context.

This Issue defines the policy and template only. Existing foundation decisions do not need to be backfilled immediately.

## Directory

ADRs live in:

```text
docs/adr/
```

File names should use a four-digit sequence and short kebab-case title:

```text
0001-use-psr-http-runtime.md
0002-use-react-typescript-starter.md
```

The number is stable after creation. Do not renumber ADRs.

## Status

Use one of these statuses:

- `proposed`: under discussion
- `accepted`: active decision
- `superseded`: replaced by a later ADR

If an ADR is superseded, link to the newer ADR. Do not delete the old ADR unless it was created by mistake and never used.

## When to Write an ADR

Write an ADR when a decision affects:

- public PHP APIs
- HTTP runtime architecture
- dependency or package selection
- frontend starter architecture
- OpenAPI contract strategy
- release, versioning, or compatibility policy
- database or migration strategy
- observability or security architecture
- AI / MCP integration boundaries
- a trade-off that future contributors are likely to question

Do not write an ADR for:

- simple bug fixes
- routine documentation edits
- small internal refactors
- obvious tool configuration changes
- work already fully explained by a narrow Issue and PR

## Relationship to Other Docs

ADRs explain why a major decision was made.

Other documents explain how the project currently works:

- `docs/development/`: current policies and implementation guidance
- `docs/roadmap.md`: phase direction
- `docs/todo/current.md`: current task board
- GitHub Issues: work units and discussion
- PRs: concrete changes and verification

If an ADR changes current policy, update the relevant `docs/development/` file in the same PR.

## Template

Use `docs/adr/0000-template.md` as the starting point.

Required sections:

- Title
- Status
- Context
- Decision
- Consequences
- Related

Keep ADRs concise. A good ADR should usually fit on one screen or two.

## First ADR Candidates

Do not backfill every existing decision.

Good first candidates when implementation starts:

- concrete PSR-7 / PSR-17 package and router selection
- concrete PSR-11 container strategy
- logging adapter selection
- frontend starter implementation strategy
- first release / Packagist publication decision

Concrete package selections should create ADRs at the same time as the selection PR. Do not defer ADR creation until after implementation unless the PR is explicitly investigative only.

The already documented foundation policies remain valid in `docs/development/` until a concrete decision needs ADR-level traceability.

## Non-Goals

- Turning every Issue into an ADR.
- Duplicating all development documentation in ADR files.
- Rewriting old decisions just to create a complete historical archive.
- Using ADRs to avoid updating the actual policy docs.
