# How-to: Hintergrund-Job-Queue mit Retry und Idempotenz

> **FT-Referenz**: FT255 (`NENE2-FT/queuelog`) — Hintergrund-Job-Queue mit Retry und Idempotenz
> **VULN**: FT255 — Schwachstellenanalyse (V-01 bis V-10)

Demonstriert eine persistente Job-Queue auf SQLite-Basis. Jobs haben Prioritätsstufen, durchlaufen eine `pending → running → completed|failed`-Zustandsmaschine und unterstützen automatische Wiederholung bei Fehlschlag mit einem konfigurierbaren Wiederholungslimit. Ein Idempotenz-Key verhindert doppelte Job-Erstellung. Enthält eine vollständige Schwachstellenanalyse.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/jobs` | Job einreihen (optionaler Idempotenz-Key) |
| `GET`  | `/jobs` | Jobs auflisten (nach Status filterbar) |
| `GET`  | `/jobs/{id}` | Einen einzelnen Job abrufen |
| `POST` | `/jobs/claim` | Worker beansprucht nächsten ausstehenden Job |
| `POST` | `/jobs/{id}/complete` | Worker markiert Job als abgeschlossen |
| `POST` | `/jobs/{id}/fail` | Worker markiert Job als fehlgeschlagen (mit Retry) |

> **Routen-Reihenfolge**: `/jobs/claim` muss vor `/jobs/{id}` registriert werden, damit das Literalsegment `claim` nicht als Pfadparameter erfasst wird.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key TEXT UNIQUE` erzwingt Einzigartigkeit auf DB-Ebene. `claimed_at`, `worker_id` und `error` sind nullable — nur gesetzt, wenn ein Job in den Zustand `running` oder `failed` wechselt.

---

## Priorität: numerisches Enum für SQL-Sortierung

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

Numerische Werte ermöglichen direkte `ORDER BY priority DESC`-Sortierung. Ein String-Enum würde einen `CASE`-Ausdruck oder eine Prioritätsnachschlagetabelle erfordern. Lücken zwischen den Werten (0, 10, 20, 30) erlauben das Einfügen zukünftiger Prioritätsstufen ohne Neunummerierung.

---

## Beanspruchen: Höchste-Priorität-FIFO

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` wählt den Job mit der höchsten Priorität und bei gleicher Priorität den ältesten (FIFO). `LIMIT 1` stellt sicher, dass nur ein Job ausgewählt wird.

Diese Beanspruchung ist **nicht-atomar** (siehe V-06). Für ein Einzelworker-Setup ist das akzeptabel. Für gleichzeitige Worker `BEGIN IMMEDIATE` von SQLite + `SELECT … LIMIT 1 FOR UPDATE` (MySQL) oder ein bedingtes UPDATE mit `status = 'pending' AND id = ?` mit `changes()`-Prüfung verwenden.

---

## Retry-Logik: Wiedereinreihen vs. Fehlschlag

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // Wiedereinreihen: auf pending zurücksetzen mit inkrementiertem retry_count
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // Erschöpft: dauerhafter Fehlschlag
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` prüft, ob der Job noch Wiederholungsversuche hat. Falls ja, kehrt der Job zu `pending` zurück (geleertes `claimed_at`/`worker_id`) und kann erneut beansprucht werden. Falls erschöpft, wechselt er in den terminalen Zustand `failed`.

Bei Wiedereinreihung werden `claimed_at = NULL` und `worker_id = NULL` geleert, sodass der Job dem nächsten Worker, der ihn beansprucht, als frischer ausstehender Job erscheint.

---

## Idempotenz-Key: Deduplizierung bei Erstellung

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

Wenn ein Job mit demselben `idempotency_key` bereits existiert, wird der vorhandene Job mit `200 OK` zurückgegeben, anstatt ein Duplikat zu erstellen. Ein neuer Job gibt `201 Created` zurück. Der `UNIQUE`-Constraint auf `idempotency_key` bietet einen zweiten Schutz gegen Race Conditions.

---

## Zustandsmaschine

```
pending ──(claim)──→ running ──(complete)──→ completed (terminal)
                        │
                        └──(fail, Retries verbleiben)──→ pending
                        │
                        └──(fail, Retries erschöpft)──→ failed (terminal)
```

`complete()` und `fail()` prüfen beide `status = Running` vor dem Übergang. Ein `null`-Rückgabe von beiden zeigt an, dass der Job nicht gefunden oder nicht im richtigen Zustand war, vom Controller auf `409 Conflict` abgebildet.

---

## VULN — Schwachstellenanalyse (FT255)

### V-01 — Keine Authentifizierung: jeder Aufrufer kann Jobs einreihen, beanspruchen oder abschließen

**Risiko**: Alle Endpunkte sind nicht authentifiziert.

**Auswirkung**: Ein Angreifer kann beliebige Jobs mit beliebigem Typ und Payload einreihen, legitime Jobs beanspruchen, um echte Worker an der Verarbeitung zu hindern, und Jobs als abgeschlossen oder fehlgeschlagen markieren, ohne die tatsächliche Arbeit auszuführen.

**Urteil**: **EXPOSED** — Authentifizierung hinzufügen. Worker-Endpunkte (`/jobs/claim`, `/jobs/{id}/complete`, `/jobs/{id}/fail`) sollten einen Worker-API-Key oder JWT erfordern. Das Einreihen sollte auf authentifizierte Produzenten beschränkt sein.

---

### V-02 — Job-Typ ist ein beliebiger String: keine Allowlist erzwungen

**Risiko**: `type` akzeptiert beliebige nicht-leere Strings. Ein Angreifer kann Jobs mit Typen einreihen, die das System nicht behandelt (z.B. `"DROP TABLE"`, `"shutdown"`, `"admin_task"`).

**Auswirkung**: Wenn der Worker basierend auf `type` dispatcht (z.B. `match($job->type) { ... }`), werden unbekannte Typen stillschweigend übersprungen oder lösen unerwartete Standard-Handler aus.

**Urteil**: **EXPOSED** — `type` gegen eine Allowlist bekannter Job-Typen validieren. `422` für unbekannte Typen zurückgeben. Beispiel:

```php
if (!in_array($type, ['email', 'pdf', 'sync'], true)) {
    return $this->problems->create($request, 'validation-failed', '...', 422, ...);
}
```

---

### V-03 — Prioritätsmanipulation: Angreifer setzt `critical`-Priorität

**Angriff**: Job mit `"priority": "critical"` einreihen, um alle vorhandenen Jobs zu verdrängen.

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**Beobachtet**: Die Anfrage gelingt mit `201`. Der Spam-Job befindet sich nun an vorderster Stelle der Queue und wird vor allen legitimen hochprioritären Jobs beansprucht.

**Urteil**: **EXPOSED** — beschränken, wer hohe Prioritätsstufen setzen kann. Produzenten ohne erhöhtes Vertrauen sollten auf `low` oder `medium` beschränkt sein. `critical` von nicht-authentifizierten Aufrufern ablehnen.

---

### V-04 — Worker-ID-Spoofing: jeder kann mit beliebiger worker_id beanspruchen

**Angriff**: Einen Claim mit `"worker_id": "legitimate-worker-1"` einreichen.

**Beobachtet**: Der Claim gelingt — der Job wird der gefälschten Worker-ID zugewiesen. Der legitime Worker kann das nicht von seinen eigenen Claims unterscheiden.

**Urteil**: **EXPOSED** — `worker_id` sollte von einer authentifizierten Identität abgeleitet werden (API-Key → Worker-Name), nicht vom Aufrufer geliefert. Worker-IDs vom Aufrufer niemals vertrauen.

---

### V-05 — Job-Zustandsübernahme: jeder Aufrufer kann jeden laufenden Job abschließen/fehlschlagen lassen

**Angriff**: Einen Job abschließen oder fehlschlagen lassen, den ein anderer Worker beansprucht hat.

```bash
# Worker A beansprucht Job 1; Angreifer schließt ihn ab, bevor Worker A fertig ist:
POST /jobs/1/complete
```

**Beobachtet**: `complete()` prüft nur `status = Running`. Keine Eigentumsprüfung verifiziert, dass der Aufrufer der Worker ist, der den Job beansprucht hat.

**Urteil**: **EXPOSED** — eine `WHERE worker_id = $requestWorkerId`-Bedingung zu `complete()` und `fail()` hinzufügen. `409` zurückgeben, wenn der Worker den Job nicht besitzt.

---

### V-06 — Race Condition beim Claim: nicht-atomares SELECT + UPDATE

**Risiko**: `claim()` führt `SELECT … LIMIT 1` dann `UPDATE … WHERE id = ?` durch. Zwei gleichzeitige Worker könnten denselben Job auswählen, bevor einer davon ihn aktualisiert.

**Angriff**: Zwei Worker sehen Job 1 beide als `pending`, aktualisieren ihn beide auf `running`, führen den Job beide aus. Das zweite Update gewinnt die `worker_id`-Spalte, aber der Job wird zweimal ausgeführt.

**Urteil**: **EXPOSED** — ein atomares Claim-Muster verwenden:
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
Dann `changes() = 1` prüfen. Bei SQLite verhindert `BEGIN IMMEDIATE`, dass gleichzeitige Reads dieselbe ausstehende Zeile sehen.

---

### V-07 — Payload-Größe: kein Limit für Job-Payload

**Risiko**: `payload` akzeptiert beliebige JSON-Objekte ohne Größenvalidierung.

**Auswirkung**: Ein mehrmegabyte-großer Payload verbraucht Speicher und Arbeitsspeicher, wenn der Job von Workern abgerufen oder in der Queue aufgelistet wird.

**Urteil**: **EXPOSED** — eine Payload-Größenprüfung hinzufügen (z.B. `strlen($json) > 65536 → 422`). Request-Size-Middleware als äußeres Limit verwenden.

---

### V-08 — SQL-Injection über type oder payload

**Angriff**: SQL-Metazeichen in `type`- oder `payload`-Felder einbetten.

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**Beobachtet**: Werte werden als parametrisierte `?`-Platzhalter gebunden. Die Injection wird als Literaltext in der Datenbank gespeichert; das SQL wird nie ausgeführt.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern SQL-Injection.

---

### V-09 — Idempotenz-Key-Kollision: Angreifer errät einen legitimen Key

**Angriff**: Den Idempotenz-Key eines legitimen Aufrufers erraten oder enumerieren und denselben Job mit einem anderen Payload einreichen.

**Beobachtet**: Der vorhandene Job wird unverändert zurückgegeben. Die Anfrage des Angreifers erstellt KEINEN neuen Job — der `UNIQUE`-Constraint und die Anwendungsebenenprüfung verhindern beide. Der Angreifer erfährt, dass der Job existiert (via dem zurückgegebenen `200`), kann ihn aber nicht modifizieren.

**Urteil**: **TEILWEISE BLOCKIERT** — doppelte Erstellung ist blockiert. Der Angreifer kann jedoch die Existenz von Jobs durch Sondierung von Idempotenz-Keys enumerieren. Lange zufällige Keys (z.B. UUID v4) verwenden, um Enumeration unpraktikabel zu machen. Die Antwort auf einen übereinstimmenden Key verrät, dass der Job existiert und seinen Status.

---

### V-10 — Fehlermeldungs-Offenlegung in fehlgeschlagenen Jobs

**Risiko**: Worker-Fehlermeldungen von `POST /jobs/{id}/fail` werden in der `error`-Spalte gespeichert und in allen Listen-/Abrufantworten zurückgegeben.

**Auswirkung**: Interne Fehlermeldungen (Stack-Traces, DB-Verbindungsstrings, interne Dateipfade), die von Workern eingereicht werden, sind für jeden Aufrufer von `GET /jobs` sichtbar.

**Urteil**: **EXPOSED** — Fehlermeldungen vor der Speicherung bereinigen (sensible Details entfernen). Sichtbarkeit des `error`-Feldes in Listen-/Abrufantworten auf Admin-Rollen beschränken.

---

## VULN-Zusammenfassung

| # | Schwachstelle | Urteil |
|---|---------------|--------|
| V-01 | Keine Authentifizierung auf irgendeinem Endpunkt | EXPOSED |
| V-02 | Job-Typ: keine Allowlist | EXPOSED |
| V-03 | Prioritätsmanipulation (kritische Jobs) | EXPOSED |
| V-04 | Worker-ID-Spoofing | EXPOSED |
| V-05 | Job-Zustandsübernahme (keine Eigentumsprüfung) | EXPOSED |
| V-06 | Race Condition beim Claim (nicht-atomar) | EXPOSED |
| V-07 | Payload-Größe: kein Limit | EXPOSED |
| V-08 | SQL-Injection über type/payload | BLOCKED |
| V-09 | Idempotenz-Key-Kollision / Enumeration | TEILWEISE BLOCKIERT |
| V-10 | Fehlermeldungs-Offenlegung in Liste | EXPOSED |

**Kritische Korrekturen vor Produktionseinsatz**:
1. **V-01** — Authentifizierung für Produzenten und Worker hinzufügen (separate Authentifizierungsebenen)
2. **V-02** — `type` gegen eine bekannte Allowlist validieren
3. **V-03 / V-04 / V-05** — Worker-Identität von authentifizierter Session ableiten; `worker_id`-Eigentumsprüfung hinzufügen
4. **V-06** — Atomaren Claim verwenden (`UPDATE … WHERE … AND status='pending'` + `changes() = 1`)
5. **V-10** — Worker-Fehlermeldungen vor Speicherung bereinigen; Sichtbarkeit einschränken

---

## Verwandte Anleitungen

- [`notification-queue.md`](notification-queue.md) — Benachrichtigungs-Queue-API (notiflog FT214)
- [`idempotency.md`](idempotency.md) — Idempotenz-Key-Muster für POST-Anfragen
- [`dead-letter-queue.md`](dead-letter-queue.md) — Dead-Letter-Queue mit Retry (deadletterlog FT72)
- [`transactions.md`](transactions.md) — Queue-Operationen in Transaktionen einwickeln
