# How-to: Zustandsautomat mit Audit-Protokoll

> **FT-Referenz**: FT237 (`NENE2-FT/statemachinelog`) — Zustandsautomat mit Audit-Protokoll
> **VULN**: FT237 — Sicherheits- und Schwachstellenbewertung (V-01 bis V-10)

Demonstriert eine Zustandsautomaten-API, bei der jeder Übergang in einer unveränderlichen
Audit-Protokoll-Tabelle aufgezeichnet wird. Der aktuelle Status befindet sich auf dem Auftrag;
die vollständige Historie befindet sich in einer separaten `order_transitions`-Tabelle.
`InvalidTransitionException` liefert strukturierte 409-Antworten mit `from`- und `to`-Kontext.

---

## Routen

| Methode | Pfad                            | Beschreibung                                     |
|---------|----------------------------------|-------------------------------------------------|
| `POST`  | `/orders`                        | Auftrag erstellen (beginnt als `draft`)         |
| `GET`   | `/orders/{id}`                   | Aktuellen Auftragsstatus abrufen                |
| `POST`  | `/orders/{id}/transitions`       | Zustandsübergang anwenden                       |
| `GET`   | `/orders/{id}/transitions`       | Vollständige Überganghistorie auflisten         |

---

## Zustandsautomat: erlaubte Übergänge

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

Terminale Zustände (`approved`, `rejected`, `cancelled`) geben eine leere Liste zurück — sie
können nicht weiter übergehen.

---

## InvalidTransitionException → 409 mit Kontext

Wenn ein Aufrufer einen unerlaubten Übergang anfordert, enthält die Exception die Von- und Bis-Zustände
als strukturierte Daten für die Fehlerantwort:

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from->value, $to->value)
        );
    }
}
```

Der Controller schließt `from` und `to` in die Problem-Details-Erweiterung ein:

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

Antwort:
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transition from \"approved\" to \"submitted\" is not allowed.",
  "from": "approved",
  "to": "submitted"
}
```

`from` und `to` ermöglichen dem Aufrufer genau zu verstehen, welcher Übergang abgelehnt wurde,
ohne die `detail`-Zeichenkette parsen zu müssen.

---

## Übergangs-Audit-Protokoll: Zwei-Schreib-Muster

Jeder erfolgreiche Übergang aktualisiert den Auftragsstatus UND fügt einen Protokolldatensatz atomar ein:

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // Aktuellen Status aktualisieren
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // An Audit-Protokoll anhängen
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **Atomizitätshinweis**: Ohne eine umschließende Transaktion hinterlässt ein Fehler zwischen dem
> UPDATE und dem INSERT den Auftrag im neuen Zustand ohne Protokolldatensatz. Beide Anweisungen in
> einer Transaktion einwickeln für echte Atomizität. Der WAL-Modus von SQLite macht dies unter
> gleichzeitigem Zugriff sicher.

---

## Schema: Auftragsstatus + Überganghistorie

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` ist by design nur-anfügend — es existiert kein UPDATE- oder DELETE-Endpunkt
dafür. Die vollständige Überganghistorie wird für die Buchprüfung aufbewahrt.

---

## Überganghistorie-Antwort

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

Die Liste ist nach `id ASC` geordnet, sodass die Historie chronologisch ist.

---

## VULN — Sicherheitsbewertung (FT237)

### V-01 — Keine Authentifizierung auf einem beliebigen Endpunkt

**Angriff**: Aufträge erstellen und Übergänge ohne Anmeldedaten anwenden.

```bash
curl -s -X POST http://localhost:8200/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**Beobachtet**: `200 OK` — kein Token erforderlich. Jeder kann jeden Auftrag genehmigen oder abbrechen.

**Urteil**: **EXPONIERT** (by design für FT237 Demo). Authentifizierung und Autorisierung hinzufügen:
Übergänge hinter einer Rolle absichern (Einreicher vs. Prüfer) und jeden Auftrag auf seinen Eigentümer beschränken.

---

### V-02 — Ungültiger Status-Wert

**Angriff**: Eine unbekannte Status-Zeichenkette senden.

```json
{"status": "hacked"}
{"status": ""}
```

**Beobachtet**: `OrderStatus::tryFrom('hacked')` = `null` → `422` mit einem Fehler, der alle
gültigen Status auflistet.

**Urteil**: **BLOCKIERT** — backed Enum `tryFrom()` lehnt unbekannte Werte ab.

---

### V-03 — Unerlaubter Übergang (terminaler Zustand → aktiv)

**Angriff**: Übergang von `approved` oder `cancelled` zu einem anderen Status versuchen.

```json
{"status": "submitted"}   // von approved
{"status": "draft"}       // von cancelled
```

**Beobachtet**: `canTransitionTo()` gibt `false` zurück → `InvalidTransitionException` →
`409 Conflict` mit `from`/`to`-Kontext im Antwort-Body.

**Urteil**: **BLOCKIERT** — Zustandsautomat erzwingt alle Übergangsregeln auf Domänenebene.

---

### V-04 — Nicht-numerische Auftrags-ID

**Angriff**: Zeichenkette oder Float als `{id}` übergeben.

```
GET /orders/abc
GET /orders/1.5
```

**Beobachtet**: `(int) 'abc'` = 0, `(int) '1.5'` = 1. Bei `abc` gibt `findById(0)` `null` zurück
→ `404 Not Found`. Bei `1.5` wird wenn Auftrag 1 existiert dieser zurückgegeben — stille Kürzung.

**Urteil**: **TEILWEISE BLOCKIERT** — nicht-numerische Zeichenketten resultieren in 404. Floats werden
still gekürzt. `ctype_digit()`-Schutz für strikte Validierung hinzufügen.

---

### V-05 — Überganghistorie ist nicht auf den Aufrufer beschränkt

**Angriff**: Überganghistorie eines anderen Benutzers lesen.

```
GET /orders/1/transitions
```

**Beobachtet**: `200 OK` — vollständige Historie wird ohne Eigentümlichkeits- oder Authentifizierungsprüfung
zurückgegeben. Die Historie enthüllt wer eingereicht, genehmigt oder abgebrochen hat (via Zeitstempel,
obwohl kein Akteur aufgezeichnet wird).

**Urteil**: **EXPONIERT** — kein Eigentümermodell. Ein `created_by`-Feld zu Aufträgen hinzufügen und
Historielesevorgänge auf den Eigentümer oder autorisierte Prüfer beschränken.

---

### V-06 — SQL-Injection via `status`-Body-Feld

**Angriff**: SQL-Metazeichen in den `status`-Wert einbetten.

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**Beobachtet**:
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → `422` vor jedem SQL.
2. Selbst wenn die Prüfung umgangen würde, wird der Status als parametrisierter `?`-Wert übergeben.

**Urteil**: **BLOCKIERT** — Doppelschicht: Enum-Allowlist + parametrisierte Abfragen.

---

### V-07 — Übergang in denselben Status (Idempotenz)

**Angriff**: Einen Übergang in den aktuellen Status senden.

```json
// Auftrag ist bereits 'submitted'
{"status": "submitted"}
```

**Beobachtet**: `allowedTransitions()` für `submitted` ist `[approved, rejected, cancelled]`
— `submitted` ist nicht in der Liste. `canTransitionTo(submitted)` gibt `false` zurück → `409 Conflict`.

**Urteil**: **BLOCKIERT** — Selbst-Übergänge werden implizit durch den Zustandsautomaten abgelehnt.

---

### V-08 — Gleichzeitige Übergänge auf demselben Auftrag

**Angriff**: Zwei simultane Übergangsanfragen für denselben Auftrag senden.

```
POST /orders/1/transitions {"status":"approved"}  // gleichzeitige Anfrage A
POST /orders/1/transitions {"status":"rejected"}  // gleichzeitige Anfrage B
```

**Beobachtet**: Beide Anfragen rufen den Auftrag ab (Status = `submitted`) bevor eines der UPDATEs
läuft. Beide sehen `canTransitionTo()` = true. Beide UPDATE — das zweite UPDATE überschreibt das
erste. Ein Übergangsprotokolldatensatz pro Anfrage wird eingefügt, aber der Auftrag endet in dem
Status, der zuletzt ausgeführt wurde. Die Historie zeigt beide Übergänge, was inkonsistent ist
(z.B. `submitted → approved`, dann `submitted → rejected`).

**Urteil**: **EXPONIERT** — die Sequenz `findById` + `canTransitionTo` + `UPDATE` + `INSERT` in
eine einzelne Transaktion einwickeln, um Race Conditions zu verhindern.

---

### V-09 — Nur-Leerzeichen-Titel

**Angriff**: Einen Auftrag mit einem leeren Titel erstellen.

```json
{"title": "   "}
```

**Beobachtet**: `trim($body['title'])` reduziert auf `""` → `title === ''`-Prüfung greift →
`422 Unprocessable Entity`.

**Urteil**: **BLOCKIERT** — `trim()` vor der Leerzeichenkettenprüfung behandelt nur-Leerzeichen-Eingaben.

---

### V-10 — Unbegrenzte Titellänge

**Angriff**: Einen Auftrag mit einem sehr langen Titel erstellen.

```json
{"title": "A".repeat(100_000)}
```

**Beobachtet**: Es wird keine Längenbegrenzung erzwungen — sehr lange Titel werden ohne
Einschränkung in der `TEXT`-Spalte gespeichert.

**Urteil**: **EXPONIERT** — einen Längen-Schutz hinzufügen:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## VULN-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| V-01 | Keine Authentifizierung | EXPONIERT |
| V-02 | Ungültiger Status-Wert | BLOCKIERT |
| V-03 | Unerlaubter Übergang von terminalem Zustand | BLOCKIERT |
| V-04 | Nicht-numerische Auftrags-ID | TEILWEISE BLOCKIERT |
| V-05 | Überganghistorie nicht auf Aufrufer beschränkt | EXPONIERT |
| V-06 | SQL-Injection via Status-Body | BLOCKIERT |
| V-07 | Selbst-Übergang (gleicher Status) | BLOCKIERT |
| V-08 | Gleichzeitige Übergänge Race Condition | EXPONIERT |
| V-09 | Nur-Leerzeichen-Titel | BLOCKIERT |
| V-10 | Unbegrenzte Titellänge | EXPONIERT |

**Echte Schwachstellen vor der Produktion beheben**:
1. **V-01 / V-05** — Authentifizierung und Autorisierung hinzufügen (Eigentümer-Scoping)
2. **V-08** — Übergang in eine Transaktion einwickeln
3. **V-10** — Titellängengrenze hinzufügen
4. **V-04** — `ctype_digit()`-Schutz für ID-Parameter hinzufügen

---

## Verwandte Anleitungen

- [`approval-workflow.md`](approval-workflow.md) — Enum-basierter Zustandsautomat mit separaten Aktions-Endpunkten
- [`audit-trail.md`](audit-trail.md) — Nur-anfügen-Audit-Protokoll-Muster
- [`transactions.md`](transactions.md) — Multi-Schreib-Sequenzen in eine Transaktion einwickeln
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Prävention
