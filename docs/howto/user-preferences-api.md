---
title: "How-to: User Preferences API"
category: product
tags: [preferences, settings, typed-values, defaults]
difficulty: intermediate
related: [user-preferences-management]
---

# How-to: User Preferences API

> **FT reference**: FT329 (`NENE2-FT/preflog`) — Per-user preference store with typed value validation, default fallback, unknown-key rejection, owner-only mutation, 20 tests / 70 assertions PASS.

This guide shows how to build a user preferences system where settings have typed domains, defaults, and `is_default` flags to distinguish customised vs default values.

## Schema

```sql
CREATE TABLE user_preferences (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    pref_key   TEXT    NOT NULL,
    pref_value TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, pref_key)
);
```

Default values live in application code, not the DB.

## Preference Keys & Validation

| Key | Type | Default | Allowed Values |
|-----|------|---------|----------------|
| `theme` | enum | `"light"` | `light`, `dark`, `system` |
| `language` | enum | `"en"` | `en`, `ja`, `fr` |
| `notifications_enabled` | boolean string | `"true"` | `"true"`, `"false"` |
| `items_per_page` | integer string | `"20"` | `"5"` – `"100"` |
| `timezone` | string | `"UTC"` | any IANA timezone |

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/users/{id}/preferences` | Get all (with defaults) |
| `PUT` | `/users/{id}/preferences/{key}` | Set one (owner only) |

## Get All Preferences

Returns all 5 keys — stored value if set, otherwise default:

```php
GET /users/1/preferences
→ 200
{
  "user_id": 1,
  "preferences": [
    {"key": "theme",                 "value": "light", "is_default": true,  "updated_at": null},
    {"key": "language",              "value": "en",    "is_default": true,  "updated_at": null},
    {"key": "notifications_enabled", "value": "true",  "is_default": true,  "updated_at": null},
    {"key": "items_per_page",        "value": "20",    "is_default": true,  "updated_at": null},
    {"key": "timezone",              "value": "UTC",   "is_default": true,  "updated_at": null}
  ]
}

// After setting theme to dark:
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

User not found → 404.

## Set Preference

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// Update existing (UPSERT)
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // only one row per (user_id, pref_key)
```

### Unknown Key

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### Invalid Value

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // must be "true"/"false"
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // max 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // min 5
```

### Authorization

```php
// Other user cannot change your preferences
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// User not found
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## Implementation Pattern

```php
private const SCHEMA = [
    'theme'                 => ['type' => 'enum',    'values' => ['light','dark','system']],
    'language'              => ['type' => 'enum',    'values' => ['en','ja','fr']],
    'notifications_enabled' => ['type' => 'bool_str','values' => ['true','false']],
    'items_per_page'        => ['type' => 'int_str', 'min' => 5, 'max' => 100],
    'timezone'              => ['type' => 'string'],
];

private function validate(string $key, string $value): ?string
{
    $schema = self::SCHEMA[$key] ?? null;
    if ($schema === null) {
        return null;  // unknown key
    }

    return match ($schema['type']) {
        'enum'     => in_array($value, $schema['values'], true) ? $value : throw ValidationException,
        'bool_str' => in_array($value, ['true','false'], true) ? $value : throw ValidationException,
        'int_str'  => $this->validateIntStr($value, $schema['min'], $schema['max']),
        default    => $value,
    };
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept any string for `theme` | UI crashes rendering unknown theme; validate enum |
| Store defaults in DB | Every new user requires a DB insert for each default; use code-side defaults |
| Return empty array when no prefs stored | Client must handle "not set" case; return all keys with defaults |
| Omit `is_default` flag | Client cannot distinguish user intent from system default |
| Allow changing other users' preferences | Privacy violation; owner check is mandatory |
| Accept `"yes"/"no"` for boolean pref | Inconsistent; normalise to `"true"/"false"` strings |
