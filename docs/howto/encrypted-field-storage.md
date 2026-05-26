# How to Build Encrypted Field Storage

> **FT reference**: FT267 (`NENE2-FT/encryptlog`) — AES-256-GCM field-level encryption: encrypt-on-write / decrypt-on-read, blind index for searchable ciphertext, key separation between encryption and index keys
>
> **VULN assessment**: V-01 through V-10 included at the end of this document.
>
> **Pattern also proven by FT187 encryptlog** — AES-256-GCM per-field encryption with HMAC-SHA256 blind index for searchable PII storage.

---

## What This Covers

Storing sensitive fields (name, email, SSN, credit card) encrypted at rest while keeping them searchable:

1. **AES-256-GCM** — authenticated encryption; every record gets its own nonce
2. **Blind index** — HMAC-SHA256 of field value enables `WHERE email_idx = ?` without decryption
3. **AEAD tamper detection** — tag mismatch causes `\RuntimeException`, not 400
4. **Ciphertext never in API responses** — the VO / toArray() layer always returns plaintext
5. **IDOR prevention** — all reads/writes scope `WHERE id AND user_id`

---

## Ciphertext Format

```
base64( nonce ‖ ciphertext ‖ tag )
```

| Component | Size | Purpose |
|---|---|---|
| `nonce` | 12 bytes | Random per-encryption IV (GCM standard) |
| `ciphertext` | variable | AES-256-GCM encrypted plaintext |
| `tag` | 16 bytes | Authentication tag — detects tampering |

Stored as a single `TEXT` column. Same plaintext → different ciphertext every time (different nonce).

---

## Schema

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- HMAC-SHA256 blind index for search
    notes_enc  TEXT,               -- nullable encrypted field
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` has an index — `WHERE email_idx = ?` is fast. The `email_enc` ciphertext is never searched.

---

## FieldCrypto Helper

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // must be 32 bytes
        private string $indexKey, // must be 32 bytes
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // fresh per-value IV
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * Deterministic — same input always → same output.
     * Allows WHERE email_idx = ? without decrypting stored ciphertext.
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## Core Pattern: Write Encrypts, Read Decrypts

```php
// CREATE — encrypt all sensitive fields before INSERT
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // deterministic for search
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// READ — decrypt transparently in hydration
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## Core Pattern: Blind Index Search

```php
// SEARCH — compute blind index from query parameter, never decrypt rows during search
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // same key → same index
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // rows are then decrypted in hydrateRow()
}
```

**When email changes on update, reindex:**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← must update together
]);
```

---

## Core Pattern: Ciphertext Never in Responses

```php
// VaultRecord::toArray() — only returns decrypted plaintext
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // plaintext
        'email'      => $this->email, // plaintext
        'notes'      => $this->notes, // plaintext or null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc, email_enc, email_idx, notes_enc — never exposed
    ];
}
```

An attacker who reads the API response cannot recover ciphertext to perform offline attacks.

---

## Core Pattern: Tamper Detection is a 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // Tag mismatch = tampered DB row OR wrong key
    // Throw — let the global error handler return 500
    // Do NOT return 400 — a 400 is a client error; this is an internal integrity failure
    throw new \RuntimeException('Decryption failed.');
}
```

Returning 400 would imply the client sent bad data. A 500 correctly signals "server-side integrity problem" and does not leak which field failed or why.

---

## Key Management Guidelines

```php
// Production: derive keys from a KMS or secret manager
$encKey   = random_bytes(32); // 32 bytes = AES-256
$indexKey = random_bytes(32); // separate key — different HMAC domain

// NEVER hardcode keys in source; use env vars or key derivation:
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 hex chars → 32 bytes
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 hex chars → 32 bytes
```

**Two separate keys:**
- `encKey` — AES-256-GCM. Rotatable: re-encrypt rows with new key, update version prefix.
- `indexKey` — HMAC-SHA256. Cannot rotate without rehashing all indexes.

---

## Test Results (FT187)

```
51 tests / 110 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

| Test area | Coverage |
|---|---|
| FieldCrypto unit | encrypt/decrypt round-trip, nonce uniqueness, blind index determinism, tamper detection, short key rejection |
| Happy path | create/get/list/update/delete/search |
| Ciphertext isolation | `name_enc`, `email_enc`, `email_idx`, `notes_enc` not in response |
| IDOR prevention | cross-user get/update/delete all return 404 |
| Mass assignment | `name_enc`, `email_idx`, `user_id` from body ignored |
| Validation | missing/long/type-wrong name, email, notes, limit |
| Blind index reindex | email update keeps index in sync |

Source: [`../NENE2-FT/encryptlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/encryptlog)

---

## VULN Assessment (FT267)

Security assessment of `NENE2-FT/encryptlog` under the field-encryption threat model.

### V-01 — Key Management: Env Loading ✅ BLOCKED

**Threat**: Encryption keys committed to VCS or hard-coded in source.
**Mitigation**: Keys loaded via `getenv()` in `ConfigLoader`, length-validated at boot. The `.env` file is git-ignored. No key material appears in source code.
**Residual**: Key rotation (replacing both keys, re-encrypting all rows) is not implemented. Accept for FT scope; production system needs a rotation plan.

---

### V-02 — Nonce Reuse (GCM) ✅ BLOCKED

**Threat**: If the same nonce is ever used twice under the same key, GCM loses all confidentiality and authenticity guarantees.
**Mitigation**: `random_bytes(12)` is called inside `encrypt()` on every invocation. The 96-bit nonce space and `random_bytes()` make collision probability negligible for any realistic usage volume (< 2^32 encryptions per key lifetime is the safe bound).
**Finding**: Safe.

---

### V-03 — Authentication Tag Verification ✅ BLOCKED

**Threat**: Ciphertext tampering passes undetected; attacker flips bits to manipulate decrypted plaintext.
**Mitigation**: `openssl_decrypt()` verifies the 16-byte GCM authentication tag before returning plaintext. Any single-bit modification returns `false`, which `FieldCrypto::decrypt()` converts to a thrown `\RuntimeException`. The application catches it and returns `500`; no partial plaintext is exposed.
**Finding**: Safe.

---

### V-04 — API Response Leaks Decryption Error Detail ⚠️ EXPOSED

**Threat**: Error handler serializes `\RuntimeException::getMessage()` ("Decryption failed — tag mismatch or corrupt ciphertext.") into the API response, leaking an integrity signal to attackers.
**Finding**: In `APP_DEBUG=true` mode the full message and stack trace may surface. In `APP_DEBUG=false` mode, the default handler may still expose the exception class name.
**Recommendation**: Add a dedicated `DecryptionFailedExceptionHandler` that maps to `500` with a generic `"internal-error"` Problem Details body regardless of debug mode. Tag-verification failure should be logged server-side only.

---

### V-05 — Blind Index Collision / Offline Dictionary ✅ BLOCKED

**Threat**: Attacker builds a dictionary of `blindIndex(candidate)` values offline and compares against the `email_idx` column.
**Mitigation**: HMAC-SHA256 with a 256-bit secret key. Without `VAULT_INDEX_KEY`, precomputing any index value is computationally infeasible. The blind index only supports exact-match (`WHERE email_idx = ?`); wildcard or substring search is not possible.
**Residual**: If `VAULT_INDEX_KEY` is compromised, all email blind indexes become brute-forceable for a finite known-email list. Key confidentiality is essential.

---

### V-06 — No Authentication / Authorization on Endpoints ⚠️ EXPOSED

**Threat**: Any unauthenticated caller can create, read, update, and delete vault records for arbitrary `user_id` values.
**Finding**: The FT exposes `/vault/{userId}/records` with no API key, JWT, or session check. The `user_id` path parameter is caller-supplied.
**Recommendation**: Require authentication (API key or JWT) and derive `$userId` from the verified token — never trust a caller-supplied `user_id`. Add `requireScope()` or an equivalent auth middleware.
**FT note**: Deliberate scope constraint for the FT. Production use requires auth.

---

### V-07 — IDOR on Update / Delete ✅ BLOCKED

**Threat**: Authenticated-but-wrong-user modifies another user's encrypted record.
**Mitigation**: All write queries include `AND user_id = :user_id`. If the record belongs to a different user, `rowCount()` returns 0 and the controller returns 404. The attacker learns only that the record does not exist (for them).
**Finding**: Safe, assuming authentication is present (see V-06).

---

### V-08 — Key Rotation / Re-encryption Gap ⚠️ EXPOSED

**Threat**: When `VAULT_ENC_KEY` is rotated, old ciphertext encrypted under the previous key cannot be decrypted. There is no re-encryption migration strategy.
**Finding**: No key versioning, no re-encryption utility, and no migration documented.
**Recommendation**: Prefix each encrypted blob with a key-version byte (e.g., `v1:<base64>`). On decrypt, read version, select key. Provide a migration script that decrypts under old key and re-encrypts under new key in a transaction.

---

### V-09 — Blind Index Timing Comparison ✅ BLOCKED

**Threat**: Comparing `email_idx` from an untrusted source with `===` leaks character-by-character timing information.
**Mitigation**: `findByEmail()` passes the computed blind index as a SQL parameter. The comparison happens inside SQLite's B-tree index lookup, which is not a timing oracle from the PHP side. No PHP-side string comparison of blind index values occurs.
**Finding**: Safe.

---

### V-10 — Decrypted Data in Memory / Logs ⚠️ EXPOSED

**Threat**: Decrypted plaintext (name, email, notes) appears in: PHP exception traces, request-logging middleware (if body is logged), error output, APM spans.
**Finding**: Request body logging middleware logs the POST body before encryption occurs — plaintext fields are in the log. If `VaultRecord` is included in an exception context, decrypted fields appear in the stack trace.
**Recommendation**:
1. Exclude plaintext vault payloads from request body logging (mask or skip `/vault` routes).
2. Implement `__debugInfo()` on `VaultRecord` to redact sensitive fields from var_dump / exception serialization.
3. Ensure error tracking integrations (Sentry, etc.) scrub plaintext fields before transmission.

---

### VULN Summary

| ID | Threat | Status |
|----|--------|--------|
| V-01 | Key committed to VCS | ✅ BLOCKED |
| V-02 | Nonce reuse (GCM) | ✅ BLOCKED |
| V-03 | Tampered ciphertext accepted | ✅ BLOCKED |
| V-04 | Decryption error detail in response | ⚠️ EXPOSED |
| V-05 | Blind index offline dictionary | ✅ BLOCKED |
| V-06 | No authentication on endpoints | ⚠️ EXPOSED |
| V-07 | IDOR on update/delete | ✅ BLOCKED |
| V-08 | Key rotation / re-encryption gap | ⚠️ EXPOSED |
| V-09 | Blind index timing comparison | ✅ BLOCKED |
| V-10 | Decrypted data in logs/exceptions | ⚠️ EXPOSED |

**Score**: 6 BLOCKED, 4 EXPOSED.

The four exposures are in key rotation strategy (V-08), authentication (V-06, deliberate FT scope), error detail leakage (V-04), and log hygiene (V-10). None represent a flaw in the AES-256-GCM or blind-index cryptographic design — they are operational and integration gaps that must be addressed before production use.
