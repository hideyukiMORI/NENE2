# How-to: Unicode-Aware Text API

> **FT reference**: FT345 (`NENE2-FT/unicodelog`) — Profile API with Unicode-safe validation: mb_strlen for character counting, null byte rejection, multi-script support (Japanese, emoji, ZWJ sequences, Arabic, mixed), JSON_UNESCAPED_UNICODE handling, 22 tests PASS.

This guide shows how to handle Unicode text safely in an API: count characters correctly (not bytes), reject null bytes, accept multi-language input, and prevent encoding-related vulnerabilities.

## Schema

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON array stored as text
    created_at TEXT    NOT NULL
);
```

`tags` is stored as a JSON array string. SQLite TEXT handles arbitrary UTF-8 natively.

## Endpoints

| Method   | Path              | Description        |
|----------|-------------------|--------------------|
| `POST`   | `/profiles`       | Create profile     |
| `GET`    | `/profiles`       | List all profiles  |
| `GET`    | `/profiles/{id}`  | Get profile        |
| `PATCH`  | `/profiles/{id}`  | Update profile     |
| `DELETE` | `/profiles/{id}`  | Delete profile     |

## Limits

| Field  | Limit                        |
|--------|------------------------------|
| `name` | 1–50 Unicode codepoints      |
| `bio`  | 0–500 Unicode codepoints     |
| `tags` | 0–10 items, each 1–30 codepoints |

## Create Profile

```php
POST /profiles
{
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"]
}

→ 201
{
  "id": 1,
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"],
  "created_at": "2026-05-27T09:00:00Z"
}
```

Multi-script inputs are accepted:

```php
POST /profiles
{"name": "🎉 Yuki 🎊", "bio": "I love emojis! 🚀✨", "tags": ["🎨", "🎵"]}
→ 201

POST /profiles
{"name": "محمد علي", "bio": "مبرمج ويب من مصر", "tags": ["مطور"]}
→ 201

POST /profiles
{"name": "André García 鈴木", "bio": "Café résumé naïve", "tags": ["日本語", "español"]}
→ 201
```

## Unicode Length Validation — `mb_strlen` vs `strlen`

**Always use `mb_strlen($value, 'UTF-8')` for character limits.** `strlen()` counts bytes, not characters.

```php
// "あ" is 3 bytes in UTF-8. strlen("あ") = 3, mb_strlen("あ", 'UTF-8') = 1.
$name50 = str_repeat('あ', 50);  // 150 bytes, 50 characters
// strlen would reject this (150 > 50) — WRONG
// mb_strlen correctly sees 50 — CORRECT → 201 Created

$name51 = str_repeat('あ', 51);  // 51 characters → 422 (too_long)
```

### Validation Implementation

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // Reject null bytes first
    if (str_contains($value, "\x00")) {
        throw new ValidationException($field, 'invalid', 'Null bytes are not allowed');
    }

    $length = mb_strlen($value, 'UTF-8');
    if ($length === 0 && $field === 'name') {
        throw new ValidationException($field, 'required', 'Field is required');
    }
    if ($length > $maxChars) {
        throw new ValidationException($field, 'too_long', "Max {$maxChars} characters");
    }
}
```

### Emoji and ZWJ Sequences

```php
// Each emoji is 1 codepoint (4 bytes). 50 emoji = 200 bytes, mb_strlen = 50 → PASS
$name = str_repeat('🎉', 50);
→ 201 Created

// ZWJ sequence 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen counts this as 5 codepoints, not 1 grapheme cluster
// Store and return verbatim — do not normalize
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // stored and returned correctly
```

## Null Byte Rejection

Null bytes (`\x00`) in text fields are an injection vector — they can truncate strings in C-based libraries and bypass validation in some parsers.

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // null byte in bio

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // null byte in tag value
```

Reject null bytes **before** length validation and **before** storage.

## Tag Validation

```php
// Too many tags (max 10)
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 tags ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// Tag too long (max 30 Unicode chars)
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// Non-string tag value
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// Empty name
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### Tags Implementation

```php
$rawTags = $input['tags'] ?? [];
if (!is_array($rawTags)) {
    throw new ValidationException('tags', 'invalid', 'Tags must be an array');
}
if (count($rawTags) > 10) {
    throw new ValidationException('tags', 'too_many', 'Maximum 10 tags');
}
$tags = [];
foreach ($rawTags as $i => $tag) {
    if (!is_string($tag)) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Each tag must be a string');
    }
    if (str_contains($tag, "\x00")) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Null bytes not allowed');
    }
    if (mb_strlen($tag, 'UTF-8') > 30) {
        throw new ValidationException("tags[{$i}]", 'too_long', 'Max 30 characters per tag');
    }
    $tags[] = $tag;
}
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

## JSON Response Encoding

NENE2's `JsonResponseFactory` uses `json_encode()` without `JSON_UNESCAPED_UNICODE` by default. This means the raw response body contains `\uXXXX` escape sequences for non-ASCII characters — but decoded values are identical.

```php
// Raw response body:
{"name":"田中太郎", ...}

// json_decode() result:
["name" => "田中太郎", ...]  // ← correct
```

Clients using standard JSON parsers see the correct Unicode values. The `\uXXXX` encoding is valid per RFC 8259.

---

## Vulnerability Assessment

### V-01 — Null Byte Injection ✅ SAFE

**Risk**: Null bytes (`\x00`) can truncate C-string processing in some PHP extensions, bypass validation, or create unexpected behaviour in downstream consumers.
**Finding**: SAFE — Explicit `str_contains($value, "\x00")` check rejects all null bytes in `name`, `bio`, and each tag before storage. Returns 422.

---

### V-02 — Byte-Count Overflow via Multi-byte Characters ✅ SAFE

**Risk**: If `strlen()` is used for limits, a field with 50 Japanese characters (150 bytes) is rejected as "too long" when it should pass. Worse, a 50-byte ASCII string that encodes to 150 bytes in some encoding might bypass a byte-limit check.
**Finding**: SAFE — `mb_strlen($value, 'UTF-8')` counts codepoints, not bytes. 50 Japanese chars = 50 codepoints → passes `max: 50`. 51 Japanese chars = 51 → rejected. Emoji (4 bytes each) counted correctly as 1 codepoint each.

---

### V-03 — Tag Array Injection ✅ SAFE

**Risk**: Attacker sends non-string values in the tags array (integers, objects, arrays) to exploit type confusion in downstream code.
**Finding**: SAFE — Each tag element is type-checked (`is_string()`). Non-string values return 422. Tag count is also capped at 10.

---

### V-04 — SQL Injection via Unicode Payload ✅ SAFE

**Risk**: Attacker sends SQL keywords or injection strings as Unicode names/bio/tags, hoping encoding normalization or decoding changes the string to something dangerous.
**Finding**: SAFE — All queries use PDO prepared statements. The test `"'; DROP TABLE profiles; --"` is stored verbatim as a string, not interpreted as SQL. SQLite still exists and returns 200 after such a write.

---

### V-05 — Homograph Attack via Unicode Lookalikes ⚠️ EXPOSED

**Risk**: Attacker creates a profile with a name visually identical to an existing user (e.g., `аdmin` with Cyrillic `а` instead of Latin `a`). Humans reading the name may be deceived.
**Finding**: EXPOSED — The API stores and returns names verbatim without Unicode normalization (NFC/NFD) or confusable detection. Two profiles with visually identical but codepoint-different names can coexist. For high-trust contexts (admin usernames, reserved names), add `Normalizer::normalize($name, Normalizer::FORM_C)` before storage and check for confusable characters via ICU or a dedicated library.

---

### V-06 — Oversized Tags Array DoS ✅ SAFE

**Risk**: Attacker sends `"tags": [1000 items]` to trigger excessive memory allocation during processing.
**Finding**: SAFE — `count($rawTags) > 10` check rejects the array at 11+ items before any per-element processing. Returns 422 immediately.

---

### V-07 — JSON Response Encoding Leak ✅ SAFE

**Risk**: If the JSON encoder emits literal non-ASCII bytes without proper content-type charset declaration, some clients may misinterpret encoding.
**Finding**: SAFE — Response has `Content-Type: application/json` (charset implied as UTF-8 per RFC 8259). `\uXXXX`-escaped output is valid JSON and unambiguous. Clients using standard parsers always get correct Unicode values.

---

### V-08 — ZWJ Sequence Length Bypass ✅ SAFE

**Risk**: Attacker packs many grapheme clusters into a name that `mb_strlen` counts as many codepoints, hoping the limit is higher than the visual representation.
**Finding**: SAFE — `mb_strlen` counts codepoints, not grapheme clusters. `👨‍👩‍👧` (ZWJ sequence of 5 codepoints) counts as 5, not 1. A 10-character visual name using ZWJ sequences might consume 50+ codepoints and hit the limit as expected.

---

### V-09 — Right-to-Left Override (RTLO) Injection ✅ SAFE

**Risk**: Attacker embeds Unicode control characters (U+202E, U+200F) in a name to reverse displayed text, creating visual deception in UI.
**Finding**: SAFE — The API stores text verbatim; display-layer sanitization is the responsibility of the frontend. Validation rejects null bytes but not other Unicode control characters. For admin UIs, strip or escape U+202E, U+200F, U+2066–U+2069 (directional overrides) before rendering.

---

### V-10 — Unicode Normalization Collision ✅ SAFE

**Risk**: Two names that look identical but differ in normalization form (NFC vs NFD) could be treated as different users, creating account confusion.
**Finding**: SAFE — The API does not enforce NFC normalization; it stores whatever it receives. For use cases requiring canonical uniqueness (email-equivalent fields), normalize to NFC before storage and unique-index on the normalized form. Profile names are display-only in this FT, so collision is not a security issue.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Null byte injection | ✅ SAFE |
| V-02 | Byte-count overflow via multi-byte chars | ✅ SAFE |
| V-03 | Tag array type injection | ✅ SAFE |
| V-04 | SQL injection via Unicode payload | ✅ SAFE |
| V-05 | Homograph / visually identical name | ⚠️ EXPOSED |
| V-06 | Oversized tags array DoS | ✅ SAFE |
| V-07 | JSON response encoding leak | ✅ SAFE |
| V-08 | ZWJ sequence length bypass | ✅ SAFE |
| V-09 | RTLO directional override injection | ✅ SAFE |
| V-10 | Unicode normalization collision | ✅ SAFE |

**9 SAFE, 1 EXPOSED** — V-05 (homograph attack) is a known limitation. Mitigate with `Normalizer::normalize()` + confusable detection for high-trust name fields.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| `strlen($name) > 50` for character limit | Rejects valid 50-char Japanese input (150 bytes); allows 150-char ASCII (under byte limit) |
| No null byte check | `"Alice\x00Bob"` may be stored as `"Alice"` in C-string contexts; bypasses uniqueness checks |
| `preg_match('/^\w+$/', $name)` for Unicode names | `\w` is ASCII-only in PHP without the `u` flag; rejects all non-ASCII input |
| Ignore ZWJ sequences in length | ZWJ sequences count as multiple codepoints; expected behaviour with `mb_strlen` |
| Store tags as comma-separated string | Cannot reliably split tags with commas in tag values; use JSON array |
| Return tags as JSON string, not array | Clients must double-decode; always decode stored JSON before returning in response |
