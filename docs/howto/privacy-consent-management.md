# How to Build Privacy Consent Management

> **Pattern proven by FT189 consentlog** — GDPR-style consent tracking with immutable history, IDOR prevention, and user enumeration resistance. VULN-A〜L 全 Pass。

---

## What This Covers

A privacy consent management flow:

1. **Grant consent** — user grants consent for a named purpose
2. **Withdraw consent** — user withdraws consent
3. **List consents** — current consent state for all purposes
4. **History** — immutable append-only audit log per purpose

Security guarantees:

| Concern | Technique |
|---|---|
| IDOR — another user's consents | All queries scope `WHERE user_id = :user_id` |
| Mass assignment (granted field) | `granted` is server-controlled; body cannot override |
| SQL injection in purpose | `ctype_alnum()` — alphanumeric only |
| ReDoS in purpose | `ctype_alnum()` O(n) — no regex |
| Type confusion | `is_string()` before `ctype_alnum()` |
| User enumeration | Unknown user returns empty array, not 404 |
| Race condition on grant/withdraw | UPSERT atomicity on `UNIQUE(user_id, purpose)` |
| Consent replay | History is append-only; each change is a new entry |

---

## Schema

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- alphanumeric slug: 'marketing', 'analytics', etc.
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=granted, 0=withdrawn
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=granted, 0=withdrawn
    created_at TEXT    NOT NULL    -- when this change occurred
);
```

`UNIQUE(user_id, purpose)` enables atomic upsert. `consent_history` is append-only — never updated.

---

## API

| Method | Path | Header | Description |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Grant consent (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Withdraw consent (200) |
| `GET` | `/consents` | `X-User-Id` | List current consents |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Audit history (append-only) |

---

## Core Pattern: Idempotent UPSERT

```php
// Grant — idempotent: re-granting an already-granted purpose is safe
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// Withdraw — same pattern
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

UPSERT on `UNIQUE(user_id, purpose)` is atomic — prevents race conditions where simultaneous grant+withdraw could create a duplicate row.

---

## Core Pattern: Immutable History

```php
// Always append to history — even re-granting is recorded
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

History is **never updated** — it is an audit log of every consent change. This enables regulators to verify when consent was given and when it was withdrawn.

---

## Core Pattern: Purpose Validation

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G: type confusion — must be a string
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I: ctype_alnum is O(n) — no regex, no ReDoS
    // VULN-D: alphanumeric only — no HTML, no SQL special chars
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` accepts `[a-zA-Z0-9]` only — rejecting spaces, hyphens, SQL metacharacters, and HTML tags in a single O(n) pass.

---

## Core Pattern: User Enumeration Prevention

```php
// VULN-F: return empty array for unknown user — no 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

Returning 404 for an unknown user leaks "this user_id does not exist." Always return 200 with empty data.

---

## Core Pattern: IDOR Prevention

```php
// VULN-B: all reads and writes scope to the authenticated user
// Even if an attacker sends X-User-Id: 999, they see only user 999's data
WHERE user_id = :user_id AND purpose = :purpose
```

No cross-user query ever touches another user's record.

---

## Core Pattern: Server-Controlled granted Field

```php
// VULN-C/E: granted is controlled by the endpoint — never from body
// POST /consents → always grants (granted = 1)
// DELETE /consents/{purpose} → always withdraws (granted = 0)
// Body { "granted": false } on POST is silently ignored
```

The endpoint itself determines the `granted` value. A body field can never override it.

---

## Response Design

| Scenario | Status | Body |
|---|---|---|
| Grant successful | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| Withdraw successful | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| List consents | 200 | `{data: [...], total: N}` |
| History | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| Unknown user | 200 | `{data: [], total: 0}` — not 404 |

`user_id` is **never** included in any response — it is implicit from `X-User-Id`.

---

## VULN-A〜L 全 Pass

| VULN | 攻撃 | 防御 |
|---|---|---|
| A | SQL injection in X-User-Id | `ctype_digit()` + strlen > 18 guard |
| B | IDOR — 他ユーザーの同意を操作 | 全クエリに `WHERE user_id = :user_id` |
| C | Mass assignment (granted フィールド改ざん) | granted はエンドポイントが決定 — body 非採用 |
| D | XSS in purpose | `ctype_alnum()` — 英数字のみ |
| E | 同意状態の直接書き換え | grant/withdraw は独立エンドポイント |
| F | ユーザー列挙 | 不明 user_id は空配列 200 を返す |
| G | Type confusion (purpose as int/array/null) | `is_string()` + `ctype_alnum()` |
| H | 同意リプレイ | history は append-only、再付与は新エントリ |
| I | ReDoS in purpose | `ctype_alnum()` O(n) |
| J | Integer overflow in X-User-Id | strlen > 18 guard |
| K | 同時 grant+withdraw race condition | `UNIQUE(user_id, purpose)` UPSERT 原子性 |
| L | CRLF injection in headers | PSR-7 が HTTP 層で拒否 |

---

## Test Results (FT189)

```
51 tests / 142 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
VULN-A〜L 全 Pass
```

Source: [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
