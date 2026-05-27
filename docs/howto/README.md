# Howto Guides Index

Task-focused guides for building with NENE2. Each guide is self-contained and links to related topics.

**100+ guides** in this directory (excluding this index). VitePress sidebar lists common entry points; use this page for the full catalog.

---

## 🔍 Find by what you want to build

> Can't find the right guide by technical name? Start here.

| I want to… | Guide |
|-----------|-------|
| Filter a list by optional query params (`?status=`, `?price_max=`) | [dynamic-filter-query.md](dynamic-filter-query.md) |
| Filter by multiple tags / skills (AND: must have all) | [multi-value-tag-filter.md](multi-value-tag-filter.md) |
| Sort by a query param safely (`?sort=name&order=asc`) | [dynamic-sort-order-injection.md](dynamic-sort-order-injection.md) |
| Add pagination to a list endpoint | [add-pagination.md](add-pagination.md) |
| Store and calculate money without rounding errors | [money-integer-arithmetic.md](money-integer-arithmetic.md) |
| Manage status transitions (draft → published → archived) | [state-machine-workflow-api.md](state-machine-workflow-api.md) |
| Build a hierarchy (categories, folders, org chart, regions) | [hierarchical-data.md](hierarchical-data.md) |
| Store events / history (append-only, never update) | [event-sourcing-cqrs-api.md](event-sourcing-cqrs-api.md) |
| Prevent double-booking (hotel, meeting room, appointment) | [prevent-double-booking.md](prevent-double-booking.md) |
| Prevent race conditions on limited stock or seats | [flash-sale-api.md](flash-sale-api.md) |
| Record who changed what and when (audit trail) | [audit-trail.md](audit-trail.md) |
| Implement votes / likes (one per user) | [upvote-downvote-api.md](upvote-downvote-api.md) |
| Add threaded comments or nested replies | [threaded-comments-api.md](threaded-comments-api.md) |
| Generate a secure token (invite link, download URL, API key) | [api-key-management.md](api-key-management.md) |
| Handle timezones and UTC storage | [handle-timezones.md](handle-timezones.md) |
| Add JWT authentication | [jwt-authentication.md](jwt-authentication.md) |
| Add multi-tenant isolation (per-tenant data separation) | [jwt-tenant-isolation.md](jwt-tenant-isolation.md) |
| Hash passwords and verify on login | [password-auth-argon2id.md](password-auth-argon2id.md) |
| Add leaderboard / ranking | [game-score-leaderboard-api.md](game-score-leaderboard-api.md) |
| Upload and serve files securely | [file-upload.md](file-upload.md) |
| Full-text search | [sqlite-fts5-search.md](sqlite-fts5-search.md) |
| Use database transactions (wrap multiple writes atomically) | [use-transactions.md](use-transactions.md) |
| Soft-delete (hide without permanently removing) | [soft-delete.md](soft-delete.md) |
| Send a webhook when something happens | [webhook-delivery-api.md](webhook-delivery-api.md) |
| Implement an approval / review workflow | [approval-workflow.md](approval-workflow.md) |
| Manage points, credits, or a balance ledger | [point-ledger-api.md](point-ledger-api.md) |
| Manage coupons and discounts | [coupon-discount-api.md](coupon-discount-api.md) |
| Implement rate limiting | [add-rate-limiting.md](add-rate-limiting.md) |
| Build a subscription / membership | [subscription-plan-management.md](subscription-plan-management.md) |

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
| [Product reviews](product-review-system.md) | 1-user-1-product, rating aggregation |
| [Shopping cart](shopping-cart.md) | UNIQUE constraint, quantity accumulation, quantity=0 delete |
| [File metadata sharing](file-metadata-sharing.md) | 3-tier access control, IDOR prevention, visibility guard |
| [Search & autocomplete](search-autocomplete.md) | LIKE escape, relevance scoring, prefix autocomplete |
| [CSV bulk import](csv-bulk-import.md) | Partial success, batch duplicate detection, CRLF |
| [TOTP two-factor auth](totp-authentication.md) | RFC 6238, Base32, replay prevention |
| [OAuth2 social login](oauth2-social-login.md) | Authorization Code Flow, state CSRF, code replay guard |
| [Application caching](application-caching.md) | Cache-Aside, TTL injection, write invalidation |
| [Content versioning](content-versioning.md) | Append-only history, rollback as new version |
| [Payment webhook](payment-webhook.md) | HMAC signature, event_id idempotency, status guard |
| [Geolocation](geolocation.md) | Haversine distance, bounding box, coordinate validation |
| [A/B testing](ab-testing.md) | Experiment lifecycle, deterministic assignment, CVR |
| [Multi-step workflow](multi-step-workflow.md) | Ordered steps, approve/reject, action history |
| [Inbound webhook receiver](inbound-webhook-receiver.md) | Per-source HMAC, signature→idempotency→persist |
| [Admin report aggregation](admin-report-aggregation.md) | Date validation, from>to guard, limit clamp |
| [Data masking](data-masking.md) | Default mask, admin unmask, append-only audit |
| [Request deduplication](request-deduplication.md) | Idempotency-Key, 24h TTL, replayed flag |
| [Personal data export](personal-data-export.md) | Opaque token, PII expiry |
