# Milestone: Howto Library Sprint

## Goal

Build a comprehensive, tested howto guide library covering the most common JSON API patterns,
so that any developer (or AI agent) using NENE2 can implement production-ready features
by following a self-contained guide with a reference field trial implementation.

## Theme: Pattern Coverage × Security by Default

Every guide ships with a reference field trial app (in `../NENE2-FT/<name>/`) and a
corresponding PHPUnit test suite. Security assessments and attack tests are woven into
the loop on a fixed cadence, ensuring guides document safe patterns by default.

---

## Phase I — FT96–FT120: Documentation Catch-up Sprint

Goal: backfill howto guides for all FTs completed before the sprint began.

| Range | Focus |
|---|---|
| FT96–FT105 | Content negotiation, SQL injection, file upload, CSRF/idempotency, pagination, nested JSON validation, transactions, mass assignment, webhook signature, optimistic locking |
| FT106–FT115 | ETag, rate limiting, soft delete, password hashing, JWT auth, RBAC, multi-tenant isolation, JWT refresh token rotation, audit trail, API versioning |
| FT116–FT120 | Background job queue, API key management, signed URLs, circuit breaker, outbound webhook delivery |

Status: **✅ Complete** — howto guides merged into `docs/howto/`.

---

## Phase II — FT121–FT156: Implementation Sprint

Goal: run full field trial implementations (code + tests + howto) for 36 new patterns,
integrated with security assessments and MySQL integration tests.

### Completed patterns

| FT | Pattern | Tests | Security |
|---|---|---|---|
| FT121 | Feature flags (rollout_pct, kill switch) | 21/21 | — |
| FT122 | Distributed locking (owner, stale claim) | 16/16 | — |
| FT123 | Personal data export (opaque token, PII) | 19/19 | Vuln |
| FT124 | User invitation (256-bit token, cancel ownership) | 26/26 | Cracker |
| FT125 | Tagging system (M:N, atomic replace) | 20/20 | — |
| FT126 | Password reset (hash, constant-time) | 15/15 | Vuln |
| FT127 | Threaded comments (depth, soft delete) | 20/20 | — |
| FT128 | Account lockout (brute-force defense) | 32/32 | Cracker + MySQL |
| FT129 | Event sourcing (append-only, replay) | 17/17 | Vuln |
| FT130 | Notification inbox (idempotent mark-read) | 23/23 | — |
| FT131 | Comment voting (toggle, VoteDirection enum) | 20/20 | — |
| FT132 | User profile management | 32/32 | Cracker + Vuln |
| FT133 | Bookmark system | 22/22 | MySQL |
| FT134 | User follow (mutual follow, self-follow guard) | 20/20 | — |
| FT135 | Direct messaging (conversation isolation) | 31/31 | Vuln |
| FT136 | Access token management (scoped, rotate) | 29/29 | Cracker |
| FT137 | Subscription management | 20/20 | — |
| FT138 | Group membership (MemberRole enum) | 38/38 | Vuln + MySQL |
| FT139 | Guest order (price snapshot, stock check) | 23/23 | — |
| FT140 | Flash sale (COUNT residual, ISO 8601 compare) | 29/29 | Cracker |
| FT141 | Leaderboard ranking | 29/29 | Vuln |
| FT142 | Content draft lifecycle (ArticleStatus enum) | 20/20 | — |
| FT143 | Emoji reactions (UNIQUE, GROUP BY) | 23/23 | MySQL |
| FT144 | Passwordless auth (Magic Link) | 43/43 | Vuln + Cracker |
| FT145 | User preferences (PreferenceKey enum, upsert) | 20/20 | — |
| FT146 | Content pinning (position compaction) | 19/19 | — |
| FT147 | Content reporting / moderation (RBAC) | 32/32 | Vuln |
| FT148 | OTP authentication (3-attempt lockout) | 35/35 | Cracker + MySQL |
| FT149 | Content collections | 20/20 | — |
| FT150 | Coupon / promo code (admin RBAC) | 34/34 | Vuln |
| FT151 | Wishlist management | 23/23 | — |
| FT152 | Points / loyalty (transaction history, idempotency) | 30/30 | Cracker |
| FT153 | Activity feed (follow-based, cursor pagination) | 57/57 | Vuln + MySQL |
| FT154 | Product review system (1-user-1-product) | 29/29 | — |
| FT155 | Shopping cart (UNIQUE, quantity accumulation) | 28/28 | — |
| FT156 | File metadata sharing (3-tier access, IDOR) | 59/59 | Vuln + Cracker |

Status: **✅ Complete** — v1.5.90, 87 howto guides in `docs/howto/`.

---

## Phase III — FT157–FT170: Library Completion

Goal: cover the remaining core patterns to complete the initial howto library.

### Planned patterns

| FT | Pattern | App | Security cadence |
|---|---|---|---|
| FT157 | Search & Autocomplete | searchlog | — |
| FT158 | CSV Bulk Import | importlog | MySQL |
| FT159 | TOTP Two-Factor Auth | totplog | Vuln |
| FT160 | OAuth2 / Social Login pattern | oauthlog | Cracker |
| FT161 | Application Caching (key design, invalidation) | cachelog | — |
| FT162 | Content Versioning (history, diff, rollback) | versionlog | — |
| FT163 | Payment Webhook (idempotent payment) | paymentlog | — |
| FT164 | Geolocation (distance queries, bounding box) | geoloclog | MySQL |
| FT165 | A/B Testing (experiment assignment, metrics) | ablog | Vuln |
| FT166 | Multi-step Workflow (state machine, approval) | workflowlog | Cracker |
| FT167 | Inbound Webhook Receiver | inboundlog | MySQL |
| FT168 | Admin Report Aggregation | reportlog | — |
| FT169 | Data Masking (PII field masking) | masklog | Vuln |
| FT170 | Request Deduplication (at-most-once processing) | deduplog | Cracker |

Status: **🔄 In progress** — starts FT157.

---

## Post-Sprint Actions

After FT170:

1. **Howto index overhaul** — `docs/howto/README.md` full 90+ guide index with search tags
2. **VitePress site update** — add new howto pages to the documentation site
3. **v2.0 design discussion** — review friction points accumulated across FT96–FT170
   to decide what should move into the framework core vs. remain as howto-only patterns
4. **Milestone: Howto Library v1** — tag the library as a usable reference corpus

---

## Security Cadence Summary

| Type | Frequency | Next |
|---|---|---|
| 脆弱性診断 | Every 3rd FT from FT114 | FT159 |
| クラッカー攻撃試験 | Every 4th FT from FT120 | FT160 |
| MySQL 統合テスト | Every ~5th FT | FT158 |

---

## Acceptance Criteria (Phase III)

- [ ] FT157–FT170 each merged to `main` with passing tests
- [ ] 101+ howto guides in `docs/howto/`
- [ ] All security assessments: VULN-A〜L Pass
- [ ] All attack tests: ATK-01〜12 Pass
- [ ] `docs/howto/README.md` updated with all new entries
- [ ] `docs/todo/current.md` updated after each FT

## Tracked by

- Roadmap phases: 70–73 in `docs/roadmap.md`
- Related milestones: `docs/milestones/2026-05-v1.1.md`
