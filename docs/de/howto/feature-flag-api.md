# How-to: Feature-Flag-API

> **FT-Referenz**: FT313 (`NENE2-FT/flaglog`) — Feature-Flag-Verwaltung: Pro-Umgebungs-Flags, rollout_percent für graduellen Rollout, Pro-Benutzer-Überschreibungen, Evaluate-Endpunkt mit Override-Auflösung, snake_case-Schlüsselvalidierung, 18 Tests / 29 Assertions PASS.

Diese Anleitung zeigt, wie ein Feature-Flag-System aufgebaut wird, das Pro-Umgebungs-Konfiguration, graduellen Rollout nach Prozentsatz und Pro-Benutzer-Überschreibungen unterstützt.

## Schema

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` muss `^[a-z][a-z0-9_]*$` (snake_case) entsprechen. `rollout_percent` ist 0–100.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `PUT` | `/flags/{key}` | Flag erstellen oder aktualisieren |
| `GET` | `/flags` | Alle Flags auflisten (optional `?environment=`) |
| `GET` | `/flags/{key}/evaluate` | Flag für einen Benutzer auswerten (`?user_id=`) |
| `PUT` | `/flags/{key}/overrides/{userId}` | Pro-Benutzer-Override setzen |
| `DELETE` | `/flags/{key}/overrides/{userId}` | Pro-Benutzer-Override entfernen |

## Flag-Upsert — PUT /flags/{key}

```php
// Request-Body
{
    "enabled": true,
    "rollout_percent": 50,   // optional, Standard 100
    "environment": "staging" // optional, Standard "production"
}

// Antwort 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

Derselbe Endpunkt erstellt oder aktualisiert (UPSERT nach `key + environment`). Zweimaliges Senden von `PUT` mit unterschiedlichen Werten aktualisiert das Flag.

## Schlüsselvalidierung

```php
// Gültige Schlüssel (snake_case: a-z, 0-9, Unterstrich, beginnt mit Buchstabe)
dark_mode, beta_ui, new_feature_v2

// Ungültig — gibt 422 zurück
Dark-Mode   // Großbuchstaben + Bindestrich
123flag     // beginnt mit Ziffer
my flag     // Leerzeichen
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## Rollout-Prozent-Validierung

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## Pro-Umgebungs-Flags

```php
// Gleicher Schlüssel, unterschiedliche Umgebungen
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// Nach Umgebung auflisten
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## Auswertung — Rollout + Override

```
GET /flags/{key}/evaluate?user_id={userId}
```

Auflösungsreihenfolge:
1. **Override gewinnt**: wenn eine `flag_overrides`-Zeile für `(key, environment, user_id)` existiert → Override-Wert verwenden
2. **Flag deaktiviert**: wenn `enabled = false` → `false` unabhängig vom Rollout zurückgeben
3. **Rollout-Prüfung**: `user_id` deterministisch hashen → mit `rollout_percent` vergleichen

```php
// 1. Override prüfen
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. Flag deaktiviert
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. Rollout-Prozent
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

Antwort:
```json
{"enabled": true, "override": null}   // Rollout-Entscheidung
{"enabled": true, "override": true}   // Override aktiviert
{"enabled": false, "override": false} // Override deaktiviert
```

## Pro-Benutzer-Überschreibungen

```php
// Für einen bestimmten Benutzer aktivieren (auch wenn Flag aus / Rollout 0%)
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// Für einen bestimmten Benutzer deaktivieren (auch wenn Flag an / Rollout 100%)
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// Override entfernen — kehrt zu globalem Flag + Rollout-Logik zurück
DELETE /flags/my_flag/overrides/alice
```

Override erfordert das `enabled`-Feld (Boolean). Fehlendes Feld → 422.
Override auf nicht-existierendem Flag → 404.
Nicht-existierenden Override löschen → 404.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Beliebiges Schlüsselformat erlauben (z. B. Bindestriche, Großbuchstaben) | Inkonsistente Schlüssel zwischen Teams; schwer zu grep/referenzieren im Code |
| Rollout-Prozent > 100 | Logikfehler; 110% Rollout bedeutet immer aktiviert, auch wenn als graduell beabsichtigt |
| Keine Umgebungstrennung | Staging-Flags bluten in die Produktion; Canary-Deployments brechen |
| Auswerten ohne `user_id`-Prüfung | `crc32(null)` oder leerer String ergibt deterministisches aber falsches Bucketing |
| 200 für Auswertung eines fehlenden Flags zurückgeben | Aufrufer nimmt an, Flag existiert; behandelt es stillschweigend als deaktiviert statt Alarm auszulösen |
| Globalen Flag-Zustand im Speicher/Cache ohne TTL | Veraltete Flags nach Rollout-Prozent-Änderung; Änderungen propagieren nicht |
