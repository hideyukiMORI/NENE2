# How to Build Encrypted Field Storage

> **Pattern proven by FT187 encryptlog** — AES-256-GCM per-field encryption with HMAC-SHA256 blind index for searchable PII storage.

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
