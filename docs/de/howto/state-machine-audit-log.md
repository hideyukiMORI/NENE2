# Anleitung: Zustandsmaschine mit Prüfprotokoll

> **FT-Referenz**: FT237 (`NENE2-FT/statemachinelog`) — Zustandsmaschine mit Prüfprotokoll
> **VULN**: FT237 — Sicherheits-/Schwachstellen-Assessment (V-01 bis V-10)

Demonstriert eine Zustandsmaschinen-API, bei der jeder Übergang in einer unveränderlichen
Prüfprotokolltabelle aufgezeichnet wird. Der aktuelle Status liegt beim Auftrag; die vollständige
Historie liegt in einer separaten `order_transitions`-Tabelle. `InvalidTransitionException` bietet
strukturierte 409-Antworten mit `from`- und `to`-Kontext.

---

## Routen

| Methode | Pfad                            | Beschreibung                                   |
|---------|--------------------------------------|-----------------------------------------------|
| `POST` | `/orders`                       | Auftrag erstellen (beginnt als `draft`)        |
| `GET`  | `/orders/{id}`                  | Aktuellen Auftragsstatus abrufen               |
| `POST` | `/orders/{id}/transitions`      | Zustandsübergang anwenden                      |
| `GET`  | `/orders/{id}/transitions`      | Vollständige Übergangshistorie auflisten        |

---

## Zustandsmaschine: erlaubte Übergänge

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

Wenn ein Aufrufer einen unerlaubten Übergang anfordert, trägt die Exception die Ausgangs- und Ziel-
zustände als strukturierte Daten für die Fehlerantwort:

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

`from` und `to` ermöglichen es dem Aufrufer genau zu verstehen, welcher Übergang abgelehnt wurde, ohne
den `detail`-String zu parsen.

---

## Übergangs-Prüfprotokoll: Zwei-Schreib-Muster

Jeder erfolgreiche Übergang aktualisiert den Auftragsstatus UND fügt atomar einen Protokolleintrag ein:

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

    // Zum Prüfprotokoll hinzufügen
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **Atomizitätshinweis**: Ohne eine umschließende Transaktion hinterlässt ein Fehler zwischen UPDATE und
> INSERT den Auftrag im neuen Zustand ohne Protokolleintrag. Beide Anweisungen in einer
> Transaktion für echte Atomizität einwickeln. SQLites WAL-Modus macht dies unter parallelem Zugriff sicher.

---

## Schema: Auftragszustand + Übergangshistorie

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

`order_transitions` ist nach Design nur zum Anhängen — kein UPDATE- oder DELETE-Endpunkt existiert dafür.
Die vollständige Übergangshistorie wird für Prüfzwecke aufbewahrt.

---

## Übergangshistorie-Antwort

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

## VULN — Sicherheitsassessment (FT237)

### V-01 — Keine Authentifizierung bei keinem Endpunkt

**Angriff**: Aufträge erstellen und Übergänge ohne Anmeldedaten anwenden.

```bash
curl -s -X POST http://localhost:8080/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**Beobachtet**: `200 OK` — kein Token erforderlich. Jeder kann jeden Auftrag genehmigen oder stornieren.

**Urteil**: **EXPOSED** (nach Design für FT237-Demo). Authentifizierung und Autorisierung hinzufügen:
Übergänge hinter einer Rolle (Einreicher vs. Prüfer) sperren und jeden Auftrag auf seinen Eigentümer beschränken.

---

### V-02 — Ungültiger Status-Wert

**Angriff**: Einen unbekannten Status-String senden.

```json
{"status": "hacked"}
{"status": ""}
```

**Beobachtet**: `OrderStatus::tryFrom('hacked')` = `null` → `422` mit einem Fehler, der alle
gültigen Statuse auflistet.

**Urteil**: **BLOCKED** — backed enum `tryFrom()` lehnt unbekannte Werte ab.

---

### V-03 — Unerlaubter Übergang (terminaler Zustand → aktiv)

**Angriff**: Versuchen, von `approved` oder `cancelled` zu einem anderen Status zu wechseln.

```json
{"status": "submitted"}   // von approved
{"status": "draft"}       // von cancelled
```

**Beobachtet**: `canTransitionTo()` gibt `false` zurück → `InvalidTransitionException` →
`409 Conflict` mit `from`/`to`-Kontext im Antwortbody.

**Urteil**: **BLOCKED** — Zustandsmaschine erzwingt alle Übergangsregeln auf Domain-Ebene.

---

### V-04 — Nicht-numerische Auftrags-ID

**Angriff**: Einen String oder Float als `{id}` übergeben.

```
GET /orders/abc
GET /orders/1.5
```

**Beobachtet**: `(int) 'abc'` = 0, `(int) '1.5'` = 1. Für `abc` gibt `findById(0)`
`null` → `404 Not Found` zurück. Für `1.5` wird Auftrag 1 zurückgegeben, wenn er existiert — stille Kürzung.

**Urteil**: **PARTIALLY BLOCKED** — nicht-numerische Strings werden in 404 aufgelöst. Floats werden
stillschweigend gekürzt. `ctype_digit()`-Schutz für strikte Validierung hinzufügen.

---

### V-05 — Übergangshistorie ist nicht auf den Aufrufer beschränkt

**Angriff**: Die Übergangshistorie eines anderen Benutzers lesen.

```
GET /orders/1/transitions
```

**Beobachtet**: `200 OK` — vollständige Historie ohne Eigentümerschafts- oder Authentifizierungsprüfung zurückgegeben.
Die Historie enthüllt, wer eingereicht, genehmigt oder storniert hat (über Zeitstempel, obwohl kein Akteur aufgezeichnet wird).

**Urteil**: **EXPOSED** — kein Eigentumsmodell. Ein `created_by`-Feld zu Aufträgen hinzufügen und
Historieslesungen auf den Eigentümer oder autorisierte Prüfer beschränken.

---

### V-06 — SQL-Injection via `status`-Body-Feld

**Angriff**: SQL-Metazeichen in den `status`-Wert einbetten.

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**Beobachtet**:
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → `422` vor jeglichem SQL.
2. Selbst wenn die Prüfung umgangen würde, wird der Status als parametrisierter `?`-Wert übergeben.

**Urteil**: **BLOCKED** — Doppelschicht: Enum-Allowlist + parametrisierte Abfragen.

---

### V-07 — Übergang zum gleichen Status (Idempotenz)

**Angriff**: Einen Übergang zum aktuellen Status senden.

```json
// Auftrag ist bereits 'submitted'
{"status": "submitted"}
```

**Beobachtet**: `allowedTransitions()` für `submitted` ist `[approved, rejected, cancelled]`
— `submitted` ist nicht in der Liste. `canTransitionTo(submitted)` gibt `false` →
`409 Conflict` zurück.

**Urteil**: **BLOCKED** — Selbstübergänge werden implizit von der Zustandsmaschine abgelehnt.

---

### V-08 — Gleichzeitige Übergänge für denselben Auftrag

**Angriff**: Zwei gleichzeitige Übergangsanfragen für denselben Auftrag senden.

```
POST /orders/1/transitions {"status":"approved"}  // gleichzeitige Anfrage A
POST /orders/1/transitions {"status":"rejected"}  // gleichzeitige Anfrage B
```

**Beobachtet**: Beide Anfragen holen den Auftrag (Status = `submitted`) bevor eine der beiden
UPDATE-Anweisungen läuft. Beide sehen `canTransitionTo()` = true. Beide UPDATE — das zweite UPDATE
überschreibt das erste. Ein Übergangsprotokoll-Eintrag pro Anfrage wird eingefügt, aber der Auftrag
endet im Status, der zuletzt ausgeführt wurde. Die Historie zeigt beide Übergänge, was inkonsistent ist
(z. B. `submitted → approved`, dann `submitted → rejected`).

**Urteil**: **EXPOSED** — die `findById` + `canTransitionTo` + `UPDATE` + `INSERT`-Sequenz in eine
einzelne Transaktion einwickeln, um Race Conditions zu verhindern.

---

### V-09 — Nur-Leerzeichen-Titel

**Angriff**: Einen Auftrag mit einem leeren Titel erstellen.

```json
{"title": "   "}
```

**Beobachtet**: `trim($body['title'])` reduziert auf `""` → `title === ''`-Prüfung greift →
`422 Unprocessable Entity`.

**Urteil**: **BLOCKED** — `trim()` vor Leerstring-Prüfung behandelt nur-Leerzeichen-Eingaben.

---

### V-10 — Unbegrenzte Titellänge

**Angriff**: Einen Auftrag mit einem sehr langen Titel erstellen.

```json
{"title": "A".repeat(100_000)}
```

**Beobachtet**: Kein Längenlimit wird durchgesetzt — sehr lange Titel werden ohne Einschränkung in
der `TEXT`-Spalte gespeichert.

**Urteil**: **EXPOSED** — einen Längen-Schutz hinzufügen:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## VULN-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|--------|
| V-01 | Keine Authentifizierung | EXPOSED |
| V-02 | Ungültiger Status-Wert | BLOCKED |
| V-03 | Unerlaubter Übergang von terminalem Zustand | BLOCKED |
| V-04 | Nicht-numerische Auftrags-ID | PARTIALLY BLOCKED |
| V-05 | Übergangshistorie nicht auf Aufrufer beschränkt | EXPOSED |
| V-06 | SQL-Injection via Status-Body | BLOCKED |
| V-07 | Selbst-Übergang (gleicher Status) | BLOCKED |
| V-08 | Gleichzeitige Übergänge Race Condition | EXPOSED |
| V-09 | Nur-Leerzeichen-Titel | BLOCKED |
| V-10 | Unbegrenzte Titellänge | EXPOSED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **V-01 / V-05** — Authentifizierung und Autorisierung hinzufügen (Eigentümerschafts-Scoping)
2. **V-08** — Übergang in einer Transaktion einwickeln
3. **V-10** — Titellängenlimit hinzufügen
4. **V-04** — `ctype_digit()`-Schutz für ID-Parameter hinzufügen

---

## Verwandte Anleitungen

- [`approval-workflow.md`](approval-workflow.md) — Enum-basierte Zustandsmaschine mit separaten Aktions-Endpunkten
- [`audit-trail.md`](audit-trail.md) — Nur-Anhänge-Prüfprotokoll-Muster
- [`transactions.md`](transactions.md) — Multi-Schreib-Sequenzen in einer Transaktion einwickeln
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Prävention
