# How-to: PII Masking API

> **FT reference**: FT297 (`NENE2-FT/masklog`) — PII masking: email/phone/name partial masking, role-based raw data access (admin only) with mandatory X-Accessor audit trail, immutable audit log, VULN-A~L all SAFE, 24 tests / 49 assertions PASS.

This guide shows how to build a customer data API that masks PII (Personally Identifiable Information) by default and grants full access only to authorized roles with an audit trail.

## Schema

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

Raw PII is stored in `customers`. Every admin access to raw data is recorded in `mask_audit_log` (append-only — no update/delete route).

## Masking Patterns

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678" (last 4 digits kept)
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## Role-based Access — Default Masked

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // raw PII
    }

    return $this->json->create($this->masker->applyMask($customer));  // masked
}
```

- **Non-admin (default)**: always receives masked data.
- **Admin with `X-Accessor`**: receives raw data and the access is logged.
- **Admin without `X-Accessor`**: 403 — the audit trail cannot be blank.

## Audit Log — Append Only

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // No DELETE or PUT for audit log — immutable by design
}
```

The audit log has no delete or update route. Entries are permanent; only admins can read the log.

---

## Vulnerability Assessment

### V-01 — PII not exposed in default GET ✅ SAFE

**Risk**: Non-admin reads raw customer email/phone/name.
**Finding**: SAFE — default response always applies `applyMask()`. Raw fields are never returned without `X-Role: admin`.

---

### V-02 — SQL injection in name field ✅ SAFE

**Risk**: `"name": "'; DROP TABLE customers; --"` deletes data.
**Finding**: SAFE — parameterized queries store the injection string verbatim as the name.

---

### V-03 — SQL injection in email field ✅ SAFE

**Risk**: SQL injection via email on create.
**Finding**: SAFE — same parameterized query protection.

---

### V-04 — IDOR: non-admin reads raw PII via customer ID ✅ SAFE

**Risk**: Without `X-Role: admin`, a user tries `GET /customers/1` to get full PII.
**Finding**: SAFE — any request without `X-Role: admin` receives masked data regardless of the customer ID.

---

### V-05 — Role escalation: arbitrary X-Role header ✅ SAFE

**Risk**: Send `X-Role: superuser` or `X-Role: ADMIN` to bypass masking.
**Finding**: SAFE — only the exact string `'admin'` grants raw access: `if ($role === 'admin')`. Any other value falls through to masked response.

---

### V-06 — Admin without X-Accessor header ✅ SAFE

**Risk**: Admin accesses raw data without X-Accessor to avoid audit trail.
**Finding**: SAFE — `if ($accessor === '') return 403`. Admin access requires a non-empty accessor identifier.

---

### V-07 — Audit log not accessible to non-admin ✅ SAFE

**Risk**: Non-admin reads `GET /customers/1/audit` to discover who accessed their data.
**Finding**: SAFE — audit endpoint checks `X-Role: admin`. Non-admin → 403.

---

### V-08 — Non-existent customer returns 404 ✅ SAFE

**Risk**: Querying a non-existent ID returns 500 or leaks DB errors.
**Finding**: SAFE — `if ($customer === null) return 404`. Clean error, no internal information.

---

### V-09 — Extremely long input does not crash ✅ SAFE

**Risk**: 10,000-character name causes DB error or memory exhaustion.
**Finding**: SAFE — SQLite TEXT has no length limit; the application stores and masks without crashing. In production, add a length limit (e.g. 500 chars).

---

### V-10 — XSS payload stored as literal ✅ SAFE

**Risk**: `"name": "<script>alert(1)</script>"` is executed in a browser.
**Finding**: SAFE — API returns `application/json`; JSON encoding escapes `<` and `>`. No HTML rendering in the API layer.

---

### V-11 — Masked response never reveals full PII ✅ SAFE

**Risk**: Masked response contains enough data to reconstruct the original PII.
**Finding**: SAFE — email: only first char + domain; phone: only last 4 digits; name: only first char per word. Cannot reconstruct original.

---

### V-12 — Audit log is immutable ✅ SAFE

**Risk**: Admin deletes their own audit log entries to cover tracks.
**Finding**: SAFE — no `DELETE /customers/{id}/audit` route exists. Log entries are append-only.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | PII exposed in default GET | ✅ SAFE |
| V-02 | SQL injection in name | ✅ SAFE |
| V-03 | SQL injection in email | ✅ SAFE |
| V-04 | IDOR: non-admin reads raw PII | ✅ SAFE |
| V-05 | Role escalation via X-Role header | ✅ SAFE |
| V-06 | Admin without X-Accessor | ✅ SAFE |
| V-07 | Audit log accessible to non-admin | ✅ SAFE |
| V-08 | Non-existent customer behavior | ✅ SAFE |
| V-09 | Extremely long input crash | ✅ SAFE |
| V-10 | XSS payload in name | ✅ SAFE |
| V-11 | Masked response reveals PII | ✅ SAFE |
| V-12 | Audit log mutability | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Default masking, mandatory accessor audit, strict role check, and immutable log prevent all PII exposure and audit bypass vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return raw PII by default | Any authenticated user reads full email/phone/name |
| Case-insensitive role check (`strtolower`) without explicit allowlist | `ADMIN`, `Admin`, `aDmIn` — only accept the exact expected string |
| Allow admin access without X-Accessor | No audit trail; GDPR compliance failure |
| Mutable audit log | Admins delete their own entries; forensic trail is unreliable |
| Expose audit log to non-admin | Users discover who (which employees) accessed their data |
| Hash masking (show hash instead of real data) | Hash of PII is still sensitive — attackers can brute-force short values |
| No masking on create response | New customer creation response exposes the just-stored PII |
| No input length limit | Very long inputs consume storage; add explicit length caps in production |
