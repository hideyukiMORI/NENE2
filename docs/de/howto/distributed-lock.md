# How-to: Verteilte Sperre

> **FT-Referenz**: FT288 (`NENE2-FT/distlocklog`) — Verteilte Sperre: UNIQUE(resource)-DB-Constraint, Eigentümerverifizierung, TTL-basiertes Ablaufen, Wiederaneignung abgelaufener Sperren by design, ReleaseResult-Enum (Released/NotFound/Forbidden), 403 bei Eigentümer-Nichtübereinstimmung, 16 Tests / 27 Assertions bestanden.
>
> **ATK-Bewertung**: ATK-01 bis ATK-12 am Ende dieses Dokuments.

Diese Anleitung zeigt, wie eine verteilte Sperr-API implementiert wird — gleichzeitige Operationen auf derselben Ressource durch die Ausgabe von Leased-Locks verhindern.

## Was ist eine verteilte Sperre?

Wenn mehrere Prozesse exklusiven Zugriff auf eine gemeinsame Ressource benötigen (z. B. eine Zahlung, eine Datei, einen Queue-Job), stellt eine verteilte Sperre sicher, dass nur ein Prozess gleichzeitig fortfährt. Sperren haben eine TTL, damit sie automatisch ablaufen, wenn der Halter abstürzt.

## Schema

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource TEXT UNIQUE` — eine Zeile pro Ressource. Das Aneignen fügt diese Zeile ein oder aktualisiert sie.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/locks/{resource}` | Sperre aneignen |
| `GET` | `/locks/{resource}` | Sperrstatus abrufen |
| `DELETE` | `/locks/{resource}` | Sperre freigeben |
| `POST` | `/locks/{resource}/renew` | TTL verlängern |

## Aneignungslogik

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Keine Sperre — INSERT (UNIQUE-Constraint behandelt Race Conditions)
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // Race: ein anderer Prozess hat gleichzeitig eingefügt
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Abgelaufen → wieder aneignen (UPDATE ersetzt die alte Zeile)
        // Gleicher Eigentümer → wieder aneignen (verlängern oder neu sperren)
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // Wird von einem anderen Eigentümer gehalten, noch nicht abgelaufen → kann nicht angeeignet werden
    return null;
}
```

## Freigabe mit Eigentümerverifizierung

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

Nur der Sperr-Eigentümer kann sie freigeben. Falscher `owner` → 403 Forbidden.

## ReleaseResult-Enum

```php
enum ReleaseResult
{
    case Released;   // Sperre gefunden, Eigentümer stimmte überein, Zeile gelöscht
    case NotFound;   // Sperre nicht gefunden oder bereits abgelaufen
    case Forbidden;  // Sperre gefunden, aber Eigentümer stimmt nicht überein
}
```

Die Verwendung eines Enums (keine magischen Strings) stellt erschöpfende Behandlung in `match` sicher.

## Aneignungsantwort

```php
// Erfolg:
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// Misserfolg (von einem anderen gehalten):
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` ist kein Fehler — es bedeutet "später erneut versuchen." Kein 4xx-Status; der Aufrufer sollte es erneut versuchen.

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Von einem anderen Eigentümer gehaltene Sperre aneignen 🚫 BLOCKED

**Angriff**: Angreifer versucht, `locks/payment:42` anzueignen, während ein anderer Prozess sie hält.
**Ergebnis**: BLOCKED — Repository prüft `existing.owner === $caller_owner`. Anderer Eigentümer + nicht abgelaufen → gibt `null` zurück → `{ acquired: false }`. Kein Fehler, kein Absturz — der Angreifer bekommt die Sperre einfach nicht.

---

### ATK-02 — Einem anderen gehörende Sperre freigeben 🚫 BLOCKED

**Angriff**: Angreifer sendet `DELETE /locks/payment:42` mit `{ "owner": "attacker" }`, um eine Sperre gewaltsam freizugeben.
**Ergebnis**: BLOCKED — Repository prüft `lock.owner === $body_owner`. Nichtübereinstimmung → `ReleaseResult::Forbidden` → 403.

---

### ATK-03 — Sperre nach Ablauf stehlen 🚫 BLOCKED (by design)

**Angriff**: Angreifer wartet, bis die Sperre abläuft, und erwirbt sie dann.
**Ergebnis**: BLOCKED (by design) — abgelaufene Sperren können von jedem Eigentümer wieder angeeignet werden. Dies ist das beabsichtigte Verhalten: TTL-basiertes Ablaufen ist die Methode, durch die abgestürzte Halter ihre Sperren verlieren. Die Reduzierung TTL-basierter Angriffe erfordert Koordination (Heartbeat-Erneuerung).

---

### ATK-04 — Einem anderen gehörende Sperre erneuern 🚫 BLOCKED

**Angriff**: Angreifer sendet `POST /locks/payment:42/renew` mit `{ "owner": "attacker", "ttl_seconds": 3600 }`.
**Ergebnis**: BLOCKED — Erneuerung prüft `lock.owner === $body_owner`. Nichtübereinstimmung → 403 Forbidden.

---

### ATK-05 — Null oder negative TTL, um eine bereits abgelaufene Sperre zu erstellen 🚫 BLOCKED

**Angriff**: `{ "ttl_seconds": 0 }` oder `{ "ttl_seconds": -100 }` senden, um eine Sperre zu erstellen, die sofort abläuft.
**Ergebnis**: BLOCKED — `if ($ttlSeconds === null || $ttlSeconds < 1)` → 422-Validierungsfehler.

---

### ATK-06 — SQL-Injection via Ressourcen-Pfadparameter 🚫 BLOCKED

**Angriff**: `locks/resource'; DROP TABLE distributed_locks; --` als Ressourcenname verwenden.
**Ergebnis**: BLOCKED — alle Abfragen verwenden parametrisierte Anweisungen (`WHERE resource = ?`). Der eingeschleuste String wird als literaler Ressourcenbezeichner behandelt.

---

### ATK-07 — Leerer Eigentümer zur Umgehung der Eigentümerprüfung 🚫 BLOCKED

**Angriff**: `{ "owner": "" }` oder `{ "owner": "   " }` senden, um ohne gültige Eigentumsrechte freizugeben oder zu erneuern.
**Ergebnis**: BLOCKED — `$owner = trim(...); if ($owner === '')` → 422-Validierungsfehler.

---

### ATK-08 — Nicht-Integer-TTL zur Umgehung der Typvalidierung 🚫 BLOCKED

**Angriff**: `{ "ttl_seconds": "3600" }` (String) oder `{ "ttl_seconds": 60.5 }` (Float) senden.
**Ergebnis**: BLOCKED — `is_int($body['ttl_seconds'])` lehnt Strings und Floats ab. Nur der JSON-Integer-Typ wird akzeptiert.

---

### ATK-09 — Gleicher Eigentümer erwirbt mehrfach 🚫 BLOCKED (by design)

**Angriff**: Gleicher Eigentümer erwirbt eine von ihm gehaltene Sperre erneut, ohne `/renew` zu verwenden.
**Ergebnis**: ERLAUBT (by design) — `$existing->owner === $owner` → UPDATE (wieder aneignen/verlängern). Wiederaneignung durch den gleichen Eigentümer ist idempotent und sicher; sie aktualisiert `expires_at` und `acquired_at`.

---

### ATK-10 — Race Condition: Zwei Eigentümer erwerben gleichzeitig 🚫 BLOCKED

**Angriff**: Zwei Prozesse sehen beide keine Sperre und versuchen gleichzeitig INSERT.
**Ergebnis**: BLOCKED — `UNIQUE(resource)`-Constraint stellt sicher, dass nur ein INSERT erfolgreich ist. Der Verlierer fängt `\RuntimeException` ab und gibt `null` zurück → `{ acquired: false }`. Nur ein Eigentümer gewinnt.

---

### ATK-11 — GET nicht existierende oder abgelaufene Sperre 🚫 BLOCKED

**Angriff**: `GET /locks/nonexistent` aufrufen oder warten, bis die Sperre abläuft, dann GET aufrufen.
**Ergebnis**: BLOCKED — `if ($lock === null || $lock->isExpired($now)) return 404`. Abgelaufene Sperren geben 404 zurück (nicht die veralteten Sperrdaten).

---

### ATK-12 — Extrem langer Ressourcenname zur DoS-Erzeugung 🚫 BLOCKED (Designhinweis)

**Angriff**: `{ "resource": "<10MB-String>" }` als Ressourcen-Pfadparameter senden.
**Ergebnis**: TEILWEISE BLOCKED — die Ressource kommt aus dem URL-Pfad, begrenzt durch die Webserver-Pfadlänge (typisch 8 KB). Keine explizite Längenvalidierung auf Anwendungsebene ist in diesem FT vorhanden. In der Produktion `if (strlen($resource) > 255)` → 422 hinzufügen. Die DB speichert, was die Anwendung übergibt.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Von einem anderen gehaltene Sperre aneignen | 🚫 BLOCKED |
| ATK-02 | Einem anderen gehörende Sperre freigeben | 🚫 BLOCKED |
| ATK-03 | Sperre nach TTL-Ablauf stehlen | 🚫 BLOCKED (by design) |
| ATK-04 | Einem anderen gehörende Sperre erneuern | 🚫 BLOCKED |
| ATK-05 | Null/negative TTL | 🚫 BLOCKED |
| ATK-06 | SQL-Injection via Ressourcenpfad | 🚫 BLOCKED |
| ATK-07 | Leerer Eigentümer-Bypass | 🚫 BLOCKED |
| ATK-08 | Nicht-Integer-TTL-Typ-Bypass | 🚫 BLOCKED |
| ATK-09 | Wiederaneignung durch gleichen Eigentümer | 🚫 BLOCKED (beabsichtigt) |
| ATK-10 | Race Condition bei gleichzeitiger Aneignung | 🚫 BLOCKED |
| ATK-11 | GET abgelaufene/nicht existierende Sperre | 🚫 BLOCKED |
| ATK-12 | Extrem langer Ressourcenname | ⚠️ DESIGNHINWEIS |

**11 BLOCKED, 1 DESIGNHINWEIS, 0 EXPOSED**
Eigentümerverifizierung, `UNIQUE(resource)`-Race-Schutz, TTL-Validierung und parametrisierte Abfragen verhindern alle kritischen Angriffsvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kein `UNIQUE(resource)`-Constraint | Race Condition: Zwei Eigentümer erwerben beide; TOCTOU-Schwachstelle |
| Freigabe ohne Eigentümerprüfung | Jeder Prozess kann jede Sperre freigeben; keine Exklusivitätsgarantie |
| Keine TTL auf Sperren | Die Sperre eines abgestürzten Halters bleibt für immer bestehen; System-Deadlock |
| TTL von 0 oder negativ akzeptieren | Sperre ist bei Erstellung bereits abgelaufen; sofort wieder aneignbar |
| 404 bei Eigentümer-Nichtübereinstimmung zurückgeben (Freigabe) | Angreifer kann "Sperre existiert nicht" nicht von "falscher Eigentümer" unterscheiden; 403 verwenden |
| String/Float als TTL akzeptieren | `"3600"` sieht gültig aus, aber `is_int` schlägt fehl; strikte Typprüfung verhindert subtile Bugs |
| Eigentümer ohne Validierung speichern | Leerer Eigentümer umgeht Eigentumsrechte; immer nicht-leer validieren |
| Kein Ressourcen-Längenlimit | Webserver-Pfadlimit ist der einzige Guard; explizite Validierung hinzufügen |
| Abgelaufene Sperren erneuern | Abgelaufene Sperre gehört niemandem; stattdessen wieder aneignen |
