# How-to: Benutzereinstellungen-API

> **FT-Referenz**: FT329 (`NENE2-FT/preflog`) — Benutzerspezifischer Einstellungsspeicher mit typisierter Wertvalidierung, Standard-Fallback, Ablehnung unbekannter Schlüssel, Nur-Eigentümer-Mutation, 20 Tests / 70 Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Benutzereinstellungssystem aufgebaut wird, bei dem Einstellungen typisierte Domains, Standards und `is_default`-Flags haben, um angepasste von Standardwerten zu unterscheiden.

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

Standardwerte leben im Anwendungscode, nicht in der DB.

## Einstellungsschlüssel & Validierung

| Schlüssel | Typ | Standard | Erlaubte Werte |
|-----------|-----|----------|----------------|
| `theme` | enum | `"light"` | `light`, `dark`, `system` |
| `language` | enum | `"en"` | `en`, `ja`, `fr` |
| `notifications_enabled` | Boolean-String | `"true"` | `"true"`, `"false"` |
| `items_per_page` | Integer-String | `"20"` | `"5"` – `"100"` |
| `timezone` | string | `"UTC"` | beliebige IANA-Zeitzone |

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/users/{id}/preferences` | Alle abrufen (mit Standards) |
| `PUT` | `/users/{id}/preferences/{key}` | Einen setzen (nur Eigentümer) |

## Alle Einstellungen abrufen

Gibt alle 5 Schlüssel zurück — gespeicherter Wert falls gesetzt, sonst Standard:

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

// Nach dem Setzen des Themes auf dark:
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

Benutzer nicht gefunden → 404.

## Einstellung setzen

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// Existierende aktualisieren (UPSERT)
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // nur eine Zeile pro (user_id, pref_key)
```

### Unbekannter Schlüssel

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### Ungültiger Wert

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // muss "true"/"false" sein
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // max 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // min 5
```

### Autorisierung

```php
// Anderer Benutzer kann Einstellungen nicht ändern
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// Benutzer nicht gefunden
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## Implementierungsmuster

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
        return null;  // unbekannter Schlüssel
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

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|-------------|--------|
| Beliebigen String für `theme` akzeptieren | UI stürzt beim Rendern unbekannter Themes ab; Enum validieren |
| Standards in DB speichern | Jeder neue Benutzer erfordert einen DB-Insert für jeden Standard; Code-seitige Standards verwenden |
| Leeres Array zurückgeben, wenn keine Prefs gespeichert | Client muss "nicht gesetzt"-Fall behandeln; alle Schlüssel mit Standards zurückgeben |
| `is_default`-Flag weglassen | Client kann Benutzerabsicht nicht von Systemstandard unterscheiden |
| Änderung der Einstellungen anderer Benutzer erlauben | Datenschutzverletzung; Eigentümerprüfung ist obligatorisch |
| `"yes"/"no"` für Boolean-Pref akzeptieren | Inkonsistent; auf `"true"/"false"`-Strings normalisieren |
