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

---

<!-- AUTO-INDEX:START (generated by `composer howto:index` — do not edit by hand) -->

## Full Index (auto-generated)

Every guide in this directory, sorted by file name. Regenerate with `composer howto:index`.

| Guide | Title |
|---|---|
| [ab-testing.md](ab-testing.md) | How-to: A/B Testing Framework |
| [access-token-management.md](access-token-management.md) | How to Build Access Token Management with NENE2 |
| [account-lockout.md](account-lockout.md) | Account Lockout (Brute-Force Protection) |
| [activity-feed.md](activity-feed.md) | How-to: Activity Feed / Timeline API |
| [add-custom-route.md](add-custom-route.md) | Add a Custom Route |
| [add-database-endpoint.md](add-database-endpoint.md) | Add a Database-backed Endpoint |
| [add-domain-exception-handler.md](add-domain-exception-handler.md) | How to add a domain exception handler |
| [add-health-check.md](add-health-check.md) | Add a Health Check |
| [add-html-view.md](add-html-view.md) | Add HTML Views |
| [add-jwt-authentication.md](add-jwt-authentication.md) | Add JWT Authentication |
| [add-mcp-tools.md](add-mcp-tools.md) | Add MCP Tools |
| [add-optimistic-locking.md](add-optimistic-locking.md) | How to add optimistic concurrency control (ETag / If-Match) |
| [add-pagination.md](add-pagination.md) | Add pagination |
| [add-rate-limiting.md](add-rate-limiting.md) | Add Rate Limiting |
| [add-second-entity.md](add-second-entity.md) | Add a Second Domain Entity |
| [admin-report-aggregation.md](admin-report-aggregation.md) | How to Add Admin Report Aggregation |
| [aggregate-reporting.md](aggregate-reporting.md) | How-to: Aggregate Reporting API |
| [api-key-management.md](api-key-management.md) | API Key Management |
| [api-usage-metering.md](api-usage-metering.md) | How-to: API Usage Metering & Quota Management |
| [api-versioning.md](api-versioning.md) | How-to: API Versioning |
| [application-caching.md](application-caching.md) | Application Caching の実装ガイド |
| [approval-workflow.md](approval-workflow.md) | How-to: Approval Workflow API |
| [article-relations-api.md](article-relations-api.md) | How-to: Article Relations API |
| [article-versioning-api.md](article-versioning-api.md) | How-to: Article Versioning API |
| [asset-checkout.md](asset-checkout.md) | How-To: Asset Check-out / Check-in Management |
| [audit-trail.md](audit-trail.md) | HOWTO: Audit Trail — Recording Who Changed What |
| [batch-api-partial-success.md](batch-api-partial-success.md) | How-to: Batch API with Partial Success |
| [bearer-token-middleware.md](bearer-token-middleware.md) | How-to: Bearer Token Middleware (JWT Auth Edge Cases) |
| [bookmark-api.md](bookmark-api.md) | How-to: Bookmark API |
| [bookmark-system.md](bookmark-system.md) | Bookmark System |
| [budget-tracking.md](budget-tracking.md) | How-to: Budget Tracking API |
| [bulk-operations-partial-success.md](bulk-operations-partial-success.md) | How-to: Bulk Operations with Partial-Success Semantics |
| [bulk-status-update.md](bulk-status-update.md) | How-to: Bulk Status Update API |
| [category-hierarchy-api.md](category-hierarchy-api.md) | How-to: Category Hierarchy Tree API |
| [circuit-breaker.md](circuit-breaker.md) | How-to: Circuit Breaker |
| [collection-api.md](collection-api.md) | How-to: Collection API (User Curated Lists) |
| [comment-thread.md](comment-thread.md) | How-to: Comment Thread API |
| [contact-management.md](contact-management.md) | How-to: Contact Management API |
| [content-approval-workflow.md](content-approval-workflow.md) | How-to: Content Approval Workflow |
| [content-collection.md](content-collection.md) | コンテンツコレクション |
| [content-draft-lifecycle.md](content-draft-lifecycle.md) | How to Build a Content Draft Lifecycle (Draft → Published → Archived) with NENE2 |
| [content-negotiation-api.md](content-negotiation-api.md) | How-to: Content Negotiation — JSON API |
| [content-negotiation.md](content-negotiation.md) | Content negotiation |
| [content-pinning.md](content-pinning.md) | Content Pinning |
| [content-relations.md](content-relations.md) | Content Relations — Typed M:N Self-Referential Links |
| [content-report-moderation.md](content-report-moderation.md) | Content Report & Moderation |
| [content-reporting.md](content-reporting.md) | How-to: Content Reporting System |
| [content-scheduling.md](content-scheduling.md) | Content Scheduling — Time-Based Publish with Lifecycle States |
| [content-versioning.md](content-versioning.md) | Content Versioning の実装ガイド |
| [coupon-discount-api.md](coupon-discount-api.md) | How-to: Coupon Discount Code API |
| [coupon-promo-code.md](coupon-promo-code.md) | クーポン・プロモコード管理 |
| [coupon-redemption.md](coupon-redemption.md) | How-to: Coupon / Discount Code Redemption API |
| [cqrs-pattern.md](cqrs-pattern.md) | How-to: CQRS Pattern |
| [credit-ledger.md](credit-ledger.md) | How-to: Credit Ledger API |
| [csrf-and-json-api.md](csrf-and-json-api.md) | CSRF and JSON APIs |
| [csv-bulk-import.md](csv-bulk-import.md) | CSV バルクインポート API の実装ガイド |
| [cursor-pagination.md](cursor-pagination.md) | How-to: Cursor-Based Pagination |
| [data-export-api.md](data-export-api.md) | How-to: Data Export API |
| [data-masking.md](data-masking.md) | How to Add Data Masking |
| [dead-letter-queue.md](dead-letter-queue.md) | How-to: Dead Letter Queue (DLQ) |
| [delegated-access-grants.md](delegated-access-grants.md) | How-to: Delegated Access Grants |
| [deploy-production.md](deploy-production.md) | Deploy to Production |
| [direct-messaging-system.md](direct-messaging-system.md) | How to Build a Direct Messaging System with NENE2 |
| [distributed-lock.md](distributed-lock.md) | How-to: Distributed Lock |
| [distributed-locking.md](distributed-locking.md) | Distributed Locking |
| [document-template-engine.md](document-template-engine.md) | How-To: Document Template Engine |
| [document-versioning.md](document-versioning.md) | How-to: Document Versioning API |
| [draft-publish-workflow.md](draft-publish-workflow.md) | How-to: Draft → Publish → Archive Workflow |
| [dynamic-filter-query.md](dynamic-filter-query.md) | How-to: Dynamic Filter Query (Dynamic WHERE Clause) |
| [dynamic-sort-order-injection.md](dynamic-sort-order-injection.md) | How-to: Dynamic Sort & Filter with ORDER BY Injection Prevention |
| [emoji-reaction-system.md](emoji-reaction-system.md) | How to Build an Emoji Reaction System with NENE2 |
| [emoji-reactions-api.md](emoji-reactions-api.md) | How-to: Emoji Reactions API |
| [emoji-reactions-toggle.md](emoji-reactions-toggle.md) | How-to: Emoji Reactions with Toggle and Grouped Counts |
| [encrypted-field-storage.md](encrypted-field-storage.md) | How to Build Encrypted Field Storage |
| [enforce-resource-ownership.md](enforce-resource-ownership.md) | How to enforce resource ownership (IDOR prevention) |
| [etag-conditional-requests.md](etag-conditional-requests.md) | ETag & Conditional Requests |
| [event-analytics-api.md](event-analytics-api.md) | How-to: Event Analytics API |
| [event-analytics.md](event-analytics.md) | How-to: Event Analytics API |
| [event-sourcing-cqrs-api.md](event-sourcing-cqrs-api.md) | How-to: Event Sourcing & CQRS API |
| [event-sourcing-ledger.md](event-sourcing-ledger.md) | How-to: Event Sourcing Ledger |
| [event-sourcing.md](event-sourcing.md) | Event Sourcing (Basic) |
| [event-ticket-booking.md](event-ticket-booking.md) | How-To: Event Ticket Booking |
| [expense-tracker.md](expense-tracker.md) | How-to: Expense Tracker API |
| [expense-tracking-api.md](expense-tracking-api.md) | How-to: Expense Tracking API |
| [feature-flag-api.md](feature-flag-api.md) | How-to: Feature Flag API |
| [feature-flags.md](feature-flags.md) | How-to: Feature Flags API |
| [feedback-collection.md](feedback-collection.md) | How-to: Feedback Collection API |
| [file-metadata-sharing.md](file-metadata-sharing.md) | ファイルメタデータ管理・共有 API の実装ガイド |
| [file-sharing-api.md](file-sharing-api.md) | How-to: File Sharing API |
| [file-upload-metadata.md](file-upload-metadata.md) | How-to: File Upload Metadata API (VULN-A~L) |
| [file-upload.md](file-upload.md) | File upload (base64 JSON) |
| [fixed-window-rate-limiter.md](fixed-window-rate-limiter.md) | How-to: Fixed-window Rate Limiter |
| [flash-sale-api.md](flash-sale-api.md) | How-to: Flash Sale API |
| [flash-sale-system.md](flash-sale-system.md) | How to Build a Flash Sale System with NENE2 |
| [follow-api.md](follow-api.md) | How-to: Follow / Unfollow API |
| [ft-registry.md](ft-registry.md) | FT Registry |
| [game-score-leaderboard-api.md](game-score-leaderboard-api.md) | How-to: Game Score & Leaderboard API |
| [geolocation-api.md](geolocation-api.md) | How-to: Geolocation API |
| [geolocation.md](geolocation.md) | How to Add Geolocation Search |
| [group-member-management.md](group-member-management.md) | How-to: Group Member Management |
| [group-membership-management.md](group-membership-management.md) | How to Build Group Membership Management with NENE2 |
| [guest-order-system.md](guest-order-system.md) | How to Build a Guest Order System (Cart → Order → Order Items) with NENE2 |
| [habit-tracker.md](habit-tracker.md) | How-to: Habit Tracker API |
| [handle-timezones.md](handle-timezones.md) | How to handle timezones |
| [hierarchical-data.md](hierarchical-data.md) | Hierarchical Data — Self-Referential FK + Materialized Path |
| [idempotency-key-api.md](idempotency-key-api.md) | How-to: Idempotency Key API |
| [idempotency-key.md](idempotency-key.md) | How-to: Idempotency Key (Request Deduplication) |
| [idempotency.md](idempotency.md) | How-to: Idempotency-Key Pattern |
| [implement-bulk-endpoint.md](implement-bulk-endpoint.md) | How-to: Implement a Bulk Create Endpoint |
| [implement-patch-endpoint.md](implement-patch-endpoint.md) | How-to: Implement a PATCH Endpoint |
| [inbound-webhook-gateway.md](inbound-webhook-gateway.md) | How-to: Inbound Webhook Gateway |
| [inbound-webhook-receiver.md](inbound-webhook-receiver.md) | How to Add an Inbound Webhook Receiver |
| [inventory-management.md](inventory-management.md) | How-to: Inventory Management API |
| [inventory-stock-management.md](inventory-stock-management.md) | How-to: Inventory Stock Management |
| [invitation-referral.md](invitation-referral.md) | How-to: Invitation / Referral API |
| [invitation-system.md](invitation-system.md) | How-to: Invitation System |
| [iso-datetime-validation.md](iso-datetime-validation.md) | How to Validate ISO 8601 Datetimes with Timezone |
| [job-queue-with-retry.md](job-queue-with-retry.md) | How-to: Background Job Queue with Retry and Idempotency |
| [job-queue.md](job-queue.md) | Background Job Queue with Retry and Idempotency |
| [json-merge-patch.md](json-merge-patch.md) | How-to: JSON Merge Patch & ETag Conflict Detection |
| [jwt-authentication.md](jwt-authentication.md) | How-To: JWT Authentication |
| [jwt-tenant-isolation.md](jwt-tenant-isolation.md) | How-to: JWT Multi-Tenant Isolation |
| [leaderboard-ranking-api.md](leaderboard-ranking-api.md) | How-to: Leaderboard Ranking API |
| [leaderboard-ranking-system.md](leaderboard-ranking-system.md) | How to Build a Leaderboard (Ranking System) with NENE2 |
| [leaderboard-ranking.md](leaderboard-ranking.md) | How-to: Game Leaderboard & Ranking API |
| [leaderboard-scores.md](leaderboard-scores.md) | How-to: Leaderboard & Score Tracking API |
| [live-poll-system.md](live-poll-system.md) | How-to: Live Poll System |
| [magic-link-authentication.md](magic-link-authentication.md) | How-to: Magic Link Authentication |
| [mass-assignment-defence.md](mass-assignment-defence.md) | How-to: Mass Assignment Defence with Explicit DTO |
| [mass-assignment.md](mass-assignment.md) | Mass Assignment Defence |
| [media-watchlist.md](media-watchlist.md) | How-to: Media Watchlist API |
| [money-integer-arithmetic.md](money-integer-arithmetic.md) | How-to: Money and Integer Arithmetic |
| [multi-currency-money-ledger.md](multi-currency-money-ledger.md) | How-to: Multi-Currency Money Ledger with Integer Cents |
| [multi-currency-wallet.md](multi-currency-wallet.md) | How-To: Multi-Currency Wallet |
| [multi-step-workflow.md](multi-step-workflow.md) | How to Add a Multi-step Workflow |
| [multi-tenant-isolation.md](multi-tenant-isolation.md) | How-To: Multi-Tenant Isolation |
| [multi-value-tag-filter.md](multi-value-tag-filter.md) | How-to: Multi-value Tag Filter API |
| [multilingual-content.md](multilingual-content.md) | How-to: Multilingual Content API |
| [nested-json-validation.md](nested-json-validation.md) | How-to: Nested JSON Validation |
| [note-management-ownership.md](note-management-ownership.md) | How-to: Note Management with Ownership |
| [note-management-with-tags.md](note-management-with-tags.md) | How-to: Note Management with Tags |
| [notification-inbox.md](notification-inbox.md) | How-to: Notification Inbox API |
| [notification-queue.md](notification-queue.md) | How-to: Notification Queue API |
| [numeric-verification-code.md](numeric-verification-code.md) | How to Build Numeric Verification Code |
| [oauth2-social-login.md](oauth2-social-login.md) | OAuth2 Social Login の実装ガイド |
| [offset-cursor-pagination.md](offset-cursor-pagination.md) | How-to: Offset & Cursor Pagination |
| [one-time-secrets.md](one-time-secrets.md) | How-To: One-Time Secret API & ATK-01~12 Cracker Attack Test |
| [optimistic-concurrency-version.md](optimistic-concurrency-version.md) | How-to: Optimistic Concurrency Control (Version Field) |
| [optimistic-lock-patch-version.md](optimistic-lock-patch-version.md) | How-to: Optimistic Lock with PATCH + Version Field |
| [optimistic-locking-etag.md](optimistic-locking-etag.md) | How-to: Optimistic Locking with ETag / If-Match |
| [optimistic-locking.md](optimistic-locking.md) | Optimistic Locking |
| [order-management.md](order-management.md) | How-to: Order Management API |
| [otp-authentication.md](otp-authentication.md) | How-to: OTP Authentication System |
| [pagination-boundary-attack.md](pagination-boundary-attack.md) | How-to: Pagination Boundary & Limit Injection |
| [pagination-limit-injection.md](pagination-limit-injection.md) | How-to: Pagination Boundary & Limit Injection Prevention |
| [pagination.md](pagination.md) | Pagination |
| [password-auth-argon2id.md](password-auth-argon2id.md) | How-to: Password Authentication with Argon2id |
| [password-hashing.md](password-hashing.md) | How-To: Password Hashing |
| [password-reset-flow.md](password-reset-flow.md) | How-to: Password Reset Flow |
| [password-reset.md](password-reset.md) | Password Reset Flow |
| [passwordless-auth-magic-link.md](passwordless-auth-magic-link.md) | Passwordless Auth (Magic Link) |
| [patch-partial-update.md](patch-partial-update.md) | How-to: PATCH Partial Update (JSON Merge Patch) |
| [payment-webhook.md](payment-webhook.md) | Payment Webhook 受信の実装ガイド |
| [personal-data-export.md](personal-data-export.md) | Personal Data Export |
| [pii-masking.md](pii-masking.md) | How-to: PII Masking API |
| [pin-bookmark-ordering.md](pin-bookmark-ordering.md) | How-to: Pin / Bookmark with Ordering |
| [pin-verification-lockout.md](pin-verification-lockout.md) | PIN 認証・ロックアウト |
| [point-ledger-api.md](point-ledger-api.md) | How-to: Point Ledger API |
| [point-loyalty-system.md](point-loyalty-system.md) | ポイント・ロイヤルティシステム |
| [poll-survey.md](poll-survey.md) | How-to: Poll / Survey API |
| [prevent-double-booking.md](prevent-double-booking.md) | How to prevent double-booking (reservation and capacity enforcement) |
| [price-history.md](price-history.md) | How-to: Product Price History API |
| [privacy-consent-management.md](privacy-consent-management.md) | How to Build Privacy Consent Management |
| [product-catalog.md](product-catalog.md) | How-to: Product Catalog API (ATK-01~12) |
| [product-review-system.md](product-review-system.md) | Product Review & Rating System |
| [project-task-management.md](project-task-management.md) | How-to: Project and Task Management with Nested Resources |
| [quality-tools.md](quality-tools.md) | Quality Tools |
| [quota-management.md](quota-management.md) | How-to: Quota Management API |
| [rate-limiting.md](rate-limiting.md) | Rate Limiting |
| [rating-review-api.md](rating-review-api.md) | How-to: Rating & Review API |
| [rbac-jwt-auth.md](rbac-jwt-auth.md) | How-to: RBAC + JWT Authentication |
| [rbac.md](rbac.md) | How-To: Role-Based Access Control (RBAC) |
| [refresh-token-pattern.md](refresh-token-pattern.md) | How-to: Refresh Token Pattern |
| [refresh-token-rotation.md](refresh-token-rotation.md) | How-To: JWT Refresh Token Rotation |
| [request-deduplication.md](request-deduplication.md) | How to Add Request Deduplication |
| [request-scoped-state.md](request-scoped-state.md) | How to pass request-scoped state between middleware and handlers |
| [reservation-availability-api.md](reservation-availability-api.md) | How-to: Reservation & Availability API |
| [resource-booking.md](resource-booking.md) | How-to: Resource Booking System |
| [resource-reservation-booking.md](resource-reservation-booking.md) | How-to: Resource Reservation & Booking API |
| [resource-reservation.md](resource-reservation.md) | How-to: Resource Reservation / Time Slot Booking API |
| [scheduled-publish-article.md](scheduled-publish-article.md) | How-to: Scheduled Publish Article |
| [scheduled-reminders.md](scheduled-reminders.md) | How-to: Scheduled Reminders API |
| [search-autocomplete.md](search-autocomplete.md) | 全文検索・オートコンプリート API の実装ガイド |
| [secret-vault.md](secret-vault.md) | How-To: Personal Secret Vault API |
| [service-status-page.md](service-status-page.md) | How-To: Service Status Page API |
| [session-management.md](session-management.md) | How to Build a Multi-Device Session Manager |
| [session-token-management.md](session-token-management.md) | How-to: Session / Token Management API (ATK-01~12) |
| [shift-management.md](shift-management.md) | How-to: Shift Management API |
| [shopping-cart-api.md](shopping-cart-api.md) | How-to: Shopping Cart API |
| [signed-url-download.md](signed-url-download.md) | How-to: Signed URL for Secure Downloads |
| [signed-urls.md](signed-urls.md) | Signed URLs |
| [sliding-window-rate-limiter.md](sliding-window-rate-limiter.md) | How-to: Sliding-Window Rate Limiter |
| [slug-management.md](slug-management.md) | Slug Management — Unique URL Slugs with Collision Resolution and History |
| [slug-url-history.md](slug-url-history.md) | How-to: Slug URL Management with History |
| [soft-delete-restore-permanent.md](soft-delete-restore-permanent.md) | How-to: Soft Delete, Restore, and Permanent Delete |
| [soft-delete-trash-purge.md](soft-delete-trash-purge.md) | How-to: Soft Delete, Trash Bin, and Permanent Purge |
| [soft-delete-trash-restore.md](soft-delete-trash-restore.md) | How-to: Soft Delete, Trash & Restore API |
| [soft-delete.md](soft-delete.md) | Soft Delete (Logical Deletion) |
| [sql-injection-defence.md](sql-injection-defence.md) | How-to: SQL Injection Defence |
| [sql-injection.md](sql-injection.md) | SQL injection defense |
| [sql-orderby-injection.md](sql-orderby-injection.md) | How to Prevent SQL ORDER BY Injection |
| [sqlite-fts5-search.md](sqlite-fts5-search.md) | How-to: SQLite FTS5 Full-Text Search |
| [state-machine-audit-log.md](state-machine-audit-log.md) | How-to: State Machine with Audit Log |
| [state-machine-workflow-api.md](state-machine-workflow-api.md) | How-to: State Machine Workflow API |
| [step-workflow-approval.md](step-workflow-approval.md) | How-to: Step-Based Workflow with Approval |
| [subscription-plan-management.md](subscription-plan-management.md) | How-to: Subscription Plan Management |
| [subscription-plan.md](subscription-plan.md) | How-to: Subscription / Plan Management API (VULN-A~L) |
| [system-announcement-management.md](system-announcement-management.md) | How to Build System Announcement Management |
| [tag-label-api.md](tag-label-api.md) | How-to: Tag / Label API |
| [tagging-system.md](tagging-system.md) | Tagging System (M:N) |
| [tenant-isolation-idor.md](tenant-isolation-idor.md) | How-to: Tenant Isolation & IDOR Prevention |
| [tenant-isolation.md](tenant-isolation.md) | How-to: Tenant Isolation & Cross-Tenant IDOR Prevention |
| [threaded-comments-api.md](threaded-comments-api.md) | How-to: Threaded Comments API |
| [threaded-comments.md](threaded-comments.md) | Threaded Comments |
| [time-tracking.md](time-tracking.md) | How-to: Time Tracking API |
| [timezone-aware-scheduling.md](timezone-aware-scheduling.md) | How-to: Timezone-aware Event Scheduling |
| [token-lifecycle-api.md](token-lifecycle-api.md) | How-to: API Token Lifecycle Management |
| [totp-authentication.md](totp-authentication.md) | TOTP 二要素認証の実装ガイド |
| [transaction-scope-pattern.md](transaction-scope-pattern.md) | How-to: Transaction Scope Pattern |
| [transactions.md](transactions.md) | Database Transactions |
| [unicode-aware-text-api.md](unicode-aware-text-api.md) | How-to: Unicode-Aware Text API |
| [upvote-downvote-api.md](upvote-downvote-api.md) | How-to: Upvote / Downvote API |
| [url-bookmark-api.md](url-bookmark-api.md) | How-to: URL Bookmark API with Tag Filtering |
| [url-shortener-ssrf-prevention.md](url-shortener-ssrf-prevention.md) | How-to: URL Shortener with SSRF Prevention |
| [url-shortener-ssrf.md](url-shortener-ssrf.md) | URL Shortener API & SSRF Prevention |
| [use-bearer-auth.md](use-bearer-auth.md) | How to use Bearer token authentication |
| [use-fts5-search.md](use-fts5-search.md) | Use SQLite FTS5 Full-Text Search |
| [use-postgresql.md](use-postgresql.md) | How to use PostgreSQL |
| [use-transactions.md](use-transactions.md) | Use Database Transactions |
| [user-follow-system.md](user-follow-system.md) | How to Build a User Follow System with NENE2 |
| [user-invitation.md](user-invitation.md) | User Invitation System |
| [user-preferences-api.md](user-preferences-api.md) | How-to: User Preferences API |
| [user-preferences-management.md](user-preferences-management.md) | User Preferences Management |
| [user-profile-api.md](user-profile-api.md) | How-to: User Profile API |
| [user-profile-management.md](user-profile-management.md) | User Profile Management |
| [validate-unicode-input.md](validate-unicode-input.md) | How to validate Unicode input |
| [voting-system.md](voting-system.md) | Voting System (Upvote / Downvote) |
| [waitlist-management.md](waitlist-management.md) | ウェイティングリスト管理 |
| [waitlist-system.md](waitlist-system.md) | How-to: Waitlist System |
| [webhook-delivery-api.md](webhook-delivery-api.md) | How-to: Webhook Delivery API |
| [webhook-delivery-system.md](webhook-delivery-system.md) | How-to: Webhook Delivery System |
| [webhook-delivery.md](webhook-delivery.md) | Outbound Webhook Delivery |
| [webhook-signature-verification.md](webhook-signature-verification.md) | How-to: Webhook Signature Verification with HMAC-SHA256 |
| [webhook-signature.md](webhook-signature.md) | Webhook Signature Verification |
| [wish-list-api.md](wish-list-api.md) | How-to: Wish List API (VULN-A~L Security Assessment) |
| [wishlist-management.md](wishlist-management.md) | ウィッシュリスト管理 |

<!-- AUTO-INDEX:END -->
