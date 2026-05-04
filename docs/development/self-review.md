# Self-Review Checklist Policy

NENE2 uses self-review checklists to make important rules hard to miss before pushing or opening a PR.

## Position

As the project grows, rules spread across focused policy documents. That is healthy, but it also makes it easy to miss required checks. Self-review checklists are the final, task-oriented reminder layer.

The standard direction is:

- Store task-specific checklists in `docs/review/`.
- Keep checklists short and linked to the source policy docs.
- Use checklists before pushing, opening a PR, or merging.
- Mark non-applicable items as `N/A` in judgment, not by deleting the rule.
- Record which checklist was used in PR descriptions when practical.
- Use checklists as a safety net, not as a replacement for policy docs.

This Issue defines the policy and first checklist set only. Checklists should evolve as implementation work reveals missing review points.

## Goals

Self-review checklists should:

- reduce missed mandatory rules
- make AI and human review more consistent
- connect scattered policy documents to concrete work types
- keep PR descriptions more honest about verification
- help future contributors know what "done" means for each kind of change

## Non-Goals

Self-review checklists should not:

- duplicate every rule from every policy document
- become long compliance documents
- replace tests, static analysis, or CI
- block small documentation fixes with irrelevant checks
- hide decisions that should be recorded in ADRs

## Directory

Checklists live in:

```text
docs/review/
```

Initial checklist files:

```text
docs/review/
├── backend-api.md
├── database.md
├── docs-policy.md
├── frontend.md
├── middleware-security.md
└── release-ci.md
```

Add new checklist files only when a repeated work type has enough unique risk to justify one.

## How to Use

Before pushing or opening a PR:

1. Identify the work type.
2. Open the matching checklist.
3. Review every applicable item.
4. Run the narrowest useful verification.
5. Mention the checklist in the PR body when practical.

Example PR note:

```text
Self-review: backend-api, middleware-security
```

If an item is not applicable, it can be treated as `N/A`. Do not edit the checklist just to make the current PR pass.

## Checklist Design Rules

Each checklist should:

- focus on mistakes that would be costly if missed
- link to source policy docs instead of copying full explanations
- stay short enough to review quickly
- include verification commands when they are stable
- avoid vague items like "code is clean"

Prefer:

```markdown
- [ ] Validation failures use Problem Details `validation-failed`.
```

Avoid:

```markdown
- [ ] Follow all coding standards.
```

## AI Agent Responsibilities

AI agents should use the relevant checklist before finalizing code or documentation changes.

For normal lifecycle work, an agent should:

- pick one or more relevant checklist files
- verify checklist-specific policy risks
- run available checks
- include the checklist names and verification result in the PR body
- avoid claiming a checklist item passed when it was not checked

If no checklist matches the task, use `docs/workflow.md`, `docs/development/coding-standards.md`, and the relevant policy docs directly.

## Updating Checklists

Update a checklist when:

- a policy document adds a mandatory rule
- review repeatedly catches the same issue
- a new work type becomes common
- a checklist item becomes obsolete

Checklist updates should happen in the same PR as the policy change when possible.

## Relationship to ADRs

Checklists catch repeatable review risks. ADRs explain major one-time decisions.

If a self-review item raises a major architectural choice, create or update an ADR instead of expanding the checklist into a design document.
