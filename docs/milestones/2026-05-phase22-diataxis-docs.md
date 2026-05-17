# Milestone: Phase 22 — Diátaxis Documentation

## Period

May 2026, after Phase 21 (Field Trial 5) completion.

## Goal

Make NENE2 accessible to developers who know JavaScript or Python but have not used PHP before. Produce Markdown documentation structured around the Diátaxis framework (Tutorial → HOWTO → Reference → Explanation), starting with the highest-ROI pieces.

HTML generation is deferred to a later phase. This phase targets Markdown only.

## Target Reader

A developer who:
- Can write JavaScript or Python
- Has not used PHP, Composer, or Docker before (or only minimally)
- Understands what a JSON API is
- Wants to build a working API with minimal ceremony

Key analogies that help:
- `composer` = `npm` / `pip`
- `docker compose up` = `npm start` / `docker run`
- PSR-7 Request/Response = Express `req` / `res`
- `declare(strict_types=1)` = TypeScript strict mode
- `.env` file = same concept as Node.js dotenv

## Diátaxis Structure

| Type | Purpose | First document |
|---|---|---|
| Tutorial | Learning-oriented, step-by-step, produces a result | "Your first API in 10 minutes" |
| HOWTO | Goal-oriented, solves a specific problem | "Add a custom route", "Add a database-backed endpoint" |
| Reference | Information-oriented, look up while working | OpenAPI (already exists), class index |
| Explanation | Understanding-oriented, concept depth | "Why PSR-7?", "Domain layer design" |

## Acceptance Criteria

- [ ] Tutorial: "Your first API" — `composer require` → running JSON endpoint (#250)
- [ ] HOWTO: "Add a custom route" — registrar pattern, path parameters (#251)
- [ ] HOWTO: "Add a database-backed endpoint" — UseCase → Repository → Handler (#252)
- [ ] `docs/` index or README updated to surface the new docs
- [ ] `docs/todo/current.md` updated

## Out of Scope

- HTML site generation (separate phase)
- Full class reference generation
- Explanation docs (lower priority)
- Translation
