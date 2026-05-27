# How-to: Feature-Flags-API

> **FT-Referenz**: FT270 (`NENE2-FT/featureflaglog`) — Feature-Flag-API: Prioritätsketten-Auswertung (Benutzer-Target → Mandanten-Target → globally_enabled → rollout_pct-Hash), crc32-basierte deterministische Bucket-Zuweisung, Benutzer-/Mandanten-Kill-Switches, UNIQUE-Name-Constraint für Flags, 21 Tests / 31 Assertions PASS.

Feature-Flags ermöglichen das Umschalten von Funktionalität zur Laufzeit ohne Code-Deployment. Die Kernentscheidungen sind: wo der Zustand gespeichert wird (DB vs. Konfiguration), wie die Priorität ausgewertet wird wenn mehrere Regeln zutreffen, und wie Rollout-Prozentsätze ohne Pro-Benutzer-Tracking behandelt werden.

---

## Routen

| Methode   | Pfad | Beschreibung |
|----------|---------------------------------------|------------------------------------------|
| `POST`   | `/flags`                              | Ein neues Feature-Flag erstellen |
| `GET`    | `/flags/{name}`                       | Flag-Details mit Targets abrufen |
| `POST`   | `/flags/{name}/toggle`                | globally_enabled ein/ausschalten |
| `PUT`    | `/flags/{name}/rollout`               | Rollout-Prozentsatz setzen (0–100) |
| `PUT`    | `/flags/{name}/targets`               | Benutzer- oder Mandanten-Target-Override einrichten |
| `DELETE` | `/flags/{name}/targets/{type}/{id}`   | Einen bestimmten Target-Override entfernen |
| `POST`   | `/flags/{name}/evaluate`              | Flag für einen Benutzer/Mandanten auswerten |

---

## Kernkomponenten

- **Feature-Flag-Registry**: eine Zeile pro Flag mit Name, globalem Ein/Aus-Schalter und Rollout-Prozentsatz.
- **Flag-Targets**: Pro-Benutzer- oder Pro-Mandanten-Overrides, die den globalen Zustand überschreiben.
- **Evaluator**: wendet die Prioritätskette an und gibt einen Boolean für einen gegebenen Benutzer zurück.

## Schema

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## Auswertungspriorität

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. Explizites Benutzer-Level-Target gewinnt zuerst
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. Mandanten-Level-Target
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. Globaler Schalter
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. Rollout-Prozentsatz: deterministischer Bucket via crc32-Hash
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. Standard: aus
        return false;
    }
}
```

Prioritätsreihenfolge (höchste gewinnt):
1. Benutzer-Level-Target (`target_type = 'user'`)
2. Mandanten-Level-Target (`target_type = 'tenant'`)
3. `globally_enabled = 1`
4. `rollout_pct > 0` mit hash-basiertem Bucket
5. `false`

## Rollout-Prozentsatz — deterministischer Bucket

`crc32($userId . '.' . $flagName) % 100` erzeugt einen stabilen Bucket pro (Benutzer, Flag)-Paar. Derselbe Benutzer landet immer im selben Bucket, sodass seine Erfahrung über Anfragen hinweg konsistent ist. Das Anhängen des Flag-Namens verhindert, dass alle Flags bei `pct = 10` denselben Benutzern ausgerollt werden.

Wichtig: `crc32()` kann auf 64-Bit-Systemen negative Werte zurückgeben — `abs()` verwenden.

## Targets als Overrides

Ein Target mit `enabled = false` ist ein Kill-Switch: Es deaktiviert das Flag für diesen Benutzer oder Mandanten, auch wenn `globally_enabled = 1`. Dies ist der kanonische Weg, einen bestimmten Benutzer von einem Rollout auszuschließen, der bereits global aktiviert ist.

```php
// Benutzer-Level-Kill-Switch (überschreibt globale Aktivierung)
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// Mandanten-Früh-Zugang (überschreibt globale Deaktivierung)
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## Upsert-Muster für Targets

Targets verwenden `INSERT OR REPLACE`-/Upsert-Semantik — zweimaliges Aufrufen desselben Endpunkts mit unterschiedlichen `enabled`-Werten aktualisiert die bestehende Zeile statt ein Duplikat zu erstellen:

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

Der UNIQUE-Constraint auf `(flag_id, target_type, target_id)` stellt sicher, dass es höchstens einen Override pro (Flag, Target)-Paar gibt.

## Konfliktantwort bei doppelten Flag-Namen

`feature_flags.name` hat einen UNIQUE-Constraint. Bei doppelter Erstellung wirft die DB eine `RuntimeException`. Diese abfangen und 409 Conflict statt 500 zurückgeben:

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // Aufrufer bildet null auf 409 ab
}
```

## Designentscheidungen

**Warum DB-gestützt statt Konfigurationsdatei?**
Konfigurationsdateien erfordern ein Deploy zum Ändern eines Flags. DB-gestützte Flags können live umgeschaltet werden, ohne Code zu ändern oder Prozesse neu zu starten.

**Warum deterministischer Hash für Rollout statt zufällig?**
Zufällige Auswahl bedeutet, dass derselbe Benutzer zwischen aktiviert/deaktiviert über Anfragen wechselt. Ein stabiler Hash gibt jedem Benutzer eine konsistente Erfahrung für die Lebensdauer des Flags.

**Warum `enabled = false`-Targets erlauben?**
Ein Flag-System ohne Kill-Switches ist unvollständig. `enabled = false` ist der sicherste Weg, einen Benutzer von einem bereits global aktivierten Rollout auszuschließen — keine Code-Änderung, kein Deploy.

**Warum `globally_enabled` und `rollout_pct` trennen?**
`globally_enabled = 1` ist ein expliziter Alles-oder-Nichts-Schalter. `rollout_pct` ist für graduellen Rollout. Sie zu trennen vermeidet das Überladen eines Feldes mit zwei verschiedenen Bedeutungen.

---

## Beispielantworten

**POST /flags** (201 Created):
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}** (200 OK):
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate** (200 OK):
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Zufällige Zahl pro Request für Rollout verwenden | Derselbe Benutzer wechselt zwischen aktiviert/deaktiviert über Anfragen — inkonsistente UX |
| `abs()` auf `crc32()` vergessen | crc32 kann auf 64-Bit-PHP negative Werte zurückgeben — Modulo ergibt falschen Bucket |
| Beliebige `target_type`-Werte erlauben | Unkontrolliertes Enum macht Auswertungslogik unbegrenzt; auf `'user'` und `'tenant'` beschränken |
| Kein `UNIQUE (flag_id, target_type, target_id)` | Doppelte Targets machen Auswertung mehrdeutig — erste Zeile gewinnt beliebig |
| Flag-Name als `target_id` verwenden | Flag-Name kann sich ändern; stabile IDs für Benutzer-/Mandanten-Targeting verwenden |
| 500 bei doppeltem Flag-Namen zurückgeben | Die Namens-Eindeutigkeitsverletzung ist ein Domain-Fehler, kein Server-Fehler; auf 409 Conflict abbilden |
