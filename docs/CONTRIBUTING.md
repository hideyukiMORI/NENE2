# Contributing

NENE2 is built through small, Issue-driven changes. This document is the shared entry point for humans and AI agents.

## Required Reading

| Topic | Document |
| --- | --- |
| Workflow | `docs/workflow.md` |
| Coding standards | `docs/development/coding-standards.md` |
| Commit messages | `docs/development/commit-conventions.md` |
| AI tools | `docs/integrations/ai-tools.md` |
| Roadmap | `docs/roadmap.md` |
| Current work | `docs/todo/current.md` |

## Collaboration Policy

- Start work from a GitHub Issue.
- Use one branch and one PR per focused work unit.
- Keep `docs/milestones/`, `docs/roadmap.md`, and `docs/todo/current.md` updated when the direction changes.
- Explain intent, impact, verification, and remaining risk in PRs.
- Prefer documentation that helps the next developer or AI agent decide what to do without rereading chat history.

## Secrets

Do not commit passwords, tokens, private URLs, production credentials, or local `.env` files. Commit only non-secret examples such as `.env.example` when needed.

## Engineering Theme

NENE2 should be modern PHP without becoming heavy:

- strict, typed, explicit boundaries
- decoupled use cases and infrastructure
- API contracts before client assumptions
- tests that make refactoring safe
- simple structure that remains readable to humans and AI agents
