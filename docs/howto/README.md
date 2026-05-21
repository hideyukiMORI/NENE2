# Howto Guides Index

Task-focused guides for building with NENE2. Each guide is self-contained and links to related topics.

---

## Getting Started

| Guide | Description |
|---|---|
| [Add a custom route](add-custom-route.md) | Register a new GET/POST/PUT/DELETE route |
| [Add a database-backed endpoint](add-database-endpoint.md) | Repository + executor + migration |
| [Add a second entity](add-second-entity.md) | FK relationships, JOIN queries |
| [Add a health check](add-health-check.md) | Liveness probe endpoint |
| [Add an HTML view](add-html-view.md) | Server-side rendering with native PHP templates |
| [Quality tools](quality-tools.md) | PHPStan, CS Fixer, PHPUnit setup |

---

## Authentication & Authorization

| Guide | Description |
|---|---|
| [JWT authentication](jwt-authentication.md) | Bearer token with LocalBearerTokenVerifier |
| [Use Bearer auth](use-bearer-auth.md) | Apply BearerTokenMiddleware |
| [RBAC](rbac.md) | Role-based access control via JWT claims |
| [Multi-tenant isolation](multi-tenant-isolation.md) | Per-tenant query filtering |
| [JWT Refresh Token Rotation](refresh-token-rotation.md) | Secure token refresh, replay detection |
| [API key management](api-key-management.md) | SHA-256 key storage, scoped access |
| [OTP authentication](otp-authentication.md) | Time-based or single-use OTP |
| [Passwordless auth (Magic Link)](passwordless-auth-magic-link.md) | Email token login |
| [Password hashing](password-hashing.md) | Argon2id, timing-safe compare |
| [Password reset](password-reset.md) | Secure token flow, constant-time response |
| [Access token management](access-token-management.md) | Scoped tokens, rotate, revoke |

---

## Security

| Guide | Description |
|---|---|
| [SQL injection prevention](sql-injection.md) | Parameterized queries, RouterParam casting |
| [Mass assignment defense](mass-assignment.md) | Allowlist via DTO |
| [CSRF and JSON APIs](csrf-and-json-api.md) | CORS ≠ CSRF; Content-Type lock |
| [Idempotency](idempotency.md) | Idempotency keys, 201/200 design |
| [Webhook signature verification](webhook-signature.md) | HMAC-SHA256, hash_equals |
| [Enforce resource ownership](enforce-resource-ownership.md) | 404 vs 403 for IDOR prevention |
| [Signed URLs](signed-urls.md) | Stateless HMAC tokens, expiry |
| [Account lockout](account-lockout.md) | Failed login counting, locked_until |

---

## Database

| Guide | Description |
|---|---|
| [Database transactions](transactions.md) | transactional() pattern overview |
| [Use database transactions](use-transactions.md) | Step-by-step with rollback testing |
| [Optimistic locking](optimistic-locking.md) | version column, 409 on conflict |
| [Soft delete](soft-delete.md) | deleted_at filter, purge guard |
| [Prevent double booking](prevent-double-booking.md) | Time-range overlap queries |
| [Use FTS5 search](use-fts5-search.md) | SQLite full-text search |
| [Use PostgreSQL](use-postgresql.md) | Switch adapter to pgsql |
| [Migrations](add-database-endpoint.md) | Phinx migrations (see add-database-endpoint) |

---

## API Design

| Guide | Description |
|---|---|
| [Pagination](pagination.md) | OFFSET vs cursor trade-offs |
| [Add pagination](add-pagination.md) | Implement cursor or offset pagination |
| [API versioning](api-versioning.md) | URI prefix, Deprecation/Sunset headers |
| [Content negotiation](content-negotiation.md) | Accept header handling |
| [ETag and conditional requests](etag-conditional-requests.md) | 304, 412, 428 |
| [Rate limiting](rate-limiting.md) | ThrottleMiddleware, per-user limits |
| [Nested JSON validation](nested-json-validation.md) | Dot-notation errors, deep validation |
| [Implement bulk endpoint](implement-bulk-endpoint.md) | Batch create/update patterns |
| [Implement PATCH endpoint](implement-patch-endpoint.md) | Partial updates |
| [Handle timezones](handle-timezones.md) | UTC storage, ISO 8601 |
| [Validate unicode input](validate-unicode-input.md) | mb_strlen, grapheme clusters |
| [Request scoped state](request-scoped-state.md) | Pass data through middleware pipeline |

---

## Background & Infrastructure

| Guide | Description |
|---|---|
| [Job queue](job-queue.md) | Priority queue, retry, idempotency key |
| [Circuit breaker](circuit-breaker.md) | 3-state, lazy Half-Open, DB persistence |
| [Webhook delivery](webhook-delivery.md) | Outbound SSRF-safe, signed, retry |
| [Event sourcing](event-sourcing.md) | Append-only log, replay |
| [Feature flags](feature-flags.md) | Rollout %, targeting, kill switch |
| [Distributed locking](distributed-locking.md) | Owner enforcement, stale claim |
| [Audit trail](audit-trail.md) | Before/after snapshot, immutable log |
| [File upload](file-upload.md) | Base64, MIME detection, path traversal |
| [Add MCP tools](add-mcp-tools.md) | Expose API as MCP tool |
| [Deploy to production](deploy-production.md) | Environment, secrets, health check |

---

## Product Features (Recipe Patterns)

| Guide | Description |
|---|---|
| [Activity feed](activity-feed.md) | Follow-based feed, cursor pagination, privacy |
| [User follow system](user-follow-system.md) | Idempotent follow/unfollow, mutual follow |
| [Direct messaging](direct-messaging-system.md) | Conversation model, participant access |
| [Notification inbox](notification-inbox.md) | Idempotent mark-read, bulk, IDOR |
| [Comment threads](threaded-comments.md) | Parent-child, MAX_DEPTH, soft delete |
| [Voting system](voting-system.md) | Upvote/downvote toggle, score |
| [Emoji reactions](emoji-reaction-system.md) | UNIQUE constraint, GROUP BY count |
| [Bookmark system](bookmark-system.md) | Idempotent add, collection filter |
| [Wishlist management](wishlist-management.md) | Privacy, priority metadata, IDOR |
| [Tagging system](tagging-system.md) | M:N join, atomic replace, tag search |
| [User profile](user-profile-management.md) | Avatar URL, duplicate email 409 |
| [User preferences](user-preferences-management.md) | Typed upsert, enum keys |
| [Content drafts](content-draft-lifecycle.md) | Status enum transitions, 404 hide |
| [Content pinning](content-pinning.md) | Position management, reorder |
| [Content collection](content-collection.md) | Idempotent add, position fill |
| [Content moderation](content-report-moderation.md) | RBAC, idempotent report, state machine |
| [Leaderboard](leaderboard-ranking-system.md) | Best score, COUNT rank, cursor |
| [Point/loyalty system](point-loyalty-system.md) | Ledger model, reference_id idempotency |
| [Flash sale](flash-sale-system.md) | Inventory race, UNIQUE prevention |
| [Guest order](guest-order-system.md) | Price snapshot, cart, stock check |
| [Subscription plan](subscription-plan-management.md) | Plan lifecycle, re-subscribe |
| [User invitation](user-invitation.md) | Token, expiry, cancel ownership |
| [Group membership](group-membership-management.md) | Roles, owner auto-join, self-leave |
| [Coupon/promo codes](coupon-promo-code.md) | Admin RBAC, per-user limit |
| [Password-based auth flow](password-hashing.md) | End-to-end with lockout (see also account-lockout) |
| [Personal data export](personal-data-export.md) | Opaque token, PII expiry |
| [Access token scopes](access-token-management.md) | Scope hierarchy, rotate |
| [Signed URL delivery](signed-urls.md) | Download with expiry, 410 Gone |
