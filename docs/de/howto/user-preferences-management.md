# Benutzereinstellungen-Verwaltung

Implementierungsanleitung für die Verwaltung von Benutzereinstellungen (Preferences).
Einstellungswerte können für einen vordefinierten Schlüsselsatz mit typisierter Validierung gespeichert, aktualisiert und zurückgesetzt werden.

## Überblick

- Einstellungsschlüssel werden per Enum verwaltet (unbekannte Schlüssel → 422)
- Werte werden pro Schlüssel typvalidiert
- Änderung fremder Einstellungen → 403 (Eigentümerschaftsprüfung)
- Nicht gesetzte Schlüssel geben den Standardwert zurück (`is_default: true`)
- DELETE setzt auf Standardwert zurück

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/users/{id}/preferences` | Einstellungsliste abrufen (alle Schlüssel, inkl. Standards) |
| `PUT` | `/users/{id}/preferences/{key}` | Einstellungswert aktualisieren (Upsert) |
| `DELETE` | `/users/{id}/preferences/{key}` | Einstellung zurücksetzen (auf Standard) |

## Datenbankdesign

```sql
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,   -- immer als String gespeichert
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key), -- Schlüssel pro Benutzer eindeutig
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Werte werden immer als `TEXT` gespeichert. Die Typinterpretation erfolgt auf Client-Seite
(`items_per_page: "20"` → Frontend macht `parseInt()`).

## Einstellungsschlüssel-Enum

```php
enum PreferenceKey: string
{
    case Theme = 'theme';
    case Language = 'language';
    case NotificationsEnabled = 'notifications_enabled';
    case ItemsPerPage = 'items_per_page';
    case Timezone = 'timezone';

    public function defaultValue(): string
    {
        return match ($this) {
            self::Theme => 'light',
            self::Language => 'en',
            self::NotificationsEnabled => 'true',
            self::ItemsPerPage => '20',
            self::Timezone => 'UTC',
        };
    }

    public function validate(string $value): bool
    {
        return match ($this) {
            self::Theme => in_array($value, ['light', 'dark', 'system'], true),
            self::Language => preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) === 1,
            self::NotificationsEnabled => in_array($value, ['true', 'false'], true),
            self::ItemsPerPage => ctype_digit($value) && (int) $value >= 5 && (int) $value <= 100,
            self::Timezone => strlen($value) <= 64 && strlen($value) > 0,
        };
    }
}
```

## GET /users/{id}/preferences Antwort

```json
{
  "preferences": [
    {"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-21T10:00:00+00:00"},
    {"key": "language", "value": "en", "is_default": true, "updated_at": null},
    {"key": "notifications_enabled", "value": "true", "is_default": true, "updated_at": null},
    {"key": "items_per_page", "value": "20", "is_default": true, "updated_at": null},
    {"key": "timezone", "value": "UTC", "is_default": true, "updated_at": null}
  ]
}
```

Alle Schlüssel werden zurückgegeben (gespeicherte mit ihrem Wert, nicht gespeicherte mit Standardwert).

## Upsert-Muster

```php
public function upsertPreference(int $userId, string $key, string $value, string $now): void
{
    $existing = $this->findPreference($userId, $key);
    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = ? WHERE user_id = ? AND pref_key = ?',
            [$value, $now, $userId, $key]
        );
    } else {
        $this->executor->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, ?)',
            [$userId, $key, $value, $now]
        );
    }
}
```

In Kombination mit dem UNIQUE(user_id, pref_key)-Constraint wird 1 Zeile pro Benutzer pro Schlüssel garantiert.

## Eigentümerschaftsprüfung (IDOR-Prävention)

```php
$actorId = (int) $request->getHeaderLine('X-User-Id');
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
}
```

Fremde Einstellungen können nicht geändert oder gelöscht werden. Lesen ist für alle möglich (Einstellungen sind in der Regel öffentlich).

## DELETE = Zurücksetzen (physisches Löschen)

DELETE löscht die Einstellungszeile aus der DB; bei GET wird wieder der Standardwert zurückgegeben:

```php
$this->repository->deletePreference($userId, $prefKey->value);
return $this->responseFactory->create([
    'key' => $prefKey->value,
    'value' => $prefKey->defaultValue(),
    'is_default' => true,
], 200);
```

Auch wenn noch nicht gesetzt (erster DELETE), wird 200 zurückgegeben (Idempotenz).

## Antwort bei unbekanntem Schlüssel

```json
{
  "error": "unknown preference key",
  "valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]
}
```

Die Rückgabe der gültigen Schlüsselliste erhöht die Selbsterklärungsfähigkeit der API.

## Erweiterungsmuster

- **Kategorisierung**: `PreferenceCategory`-Enum hinzufügen und nach UI, Benachrichtigungen, Anzeige etc. gruppieren
- **Benutzertypspezifische Standards**: `defaultValue(UserType $type)` für bedingte Verzweigung
- **Audit-Log**: `updated_at` + Änderungshistorie-Tabelle zur Verfolgung von Einstellungsänderungen
- **Batch-Update**: `PATCH /users/{id}/preferences` zur gleichzeitigen Aktualisierung mehrerer Einstellungen
