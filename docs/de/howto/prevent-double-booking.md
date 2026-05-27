# Wie man Doppelbuchungen verhindert (Reservierungs- und Kapazitätsdurchsetzung)

Reservierungssysteme haben zwei unterschiedliche Fehlermodi, die separat behandelt werden müssen:

1. **Doppelte Reservierung** — derselbe Benutzer versucht, denselben Slot zweimal zu buchen
2. **Überkapazität** — die Anzahl der Reservierungen würde das Limit des Slots überschreiten

Beide resultieren in einem abgelehnten INSERT, erfordern aber unterschiedliche Fehlerantworten.
Diese Anleitung zeigt, wie man sie unterscheidet und sich gegen gleichzeitige Konflikte absichert.

---

## 1. Schema: UNIQUE-Constraint + Kapazitätsspalte

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- Letztes-Mittel-Schutz gegen Doppelreservierungen
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

Der `UNIQUE(slot_id, user_id)`-Constraint ist das Sicherheitsnetz — er verhindert Doppelreservierungen,
selbst wenn die Anwendungslogik einen Fehler hat. Aber er kann nicht sagen, *warum* das INSERT fehlschlug.

---

## 2. Duplikat von Überkapazität durch explizite Prüfung unterscheiden

`DatabaseConstraintException` enthält keine Informationen auf Spaltenebene darüber, welcher Constraint
ausgelöst hat. Um unterschiedliche 409-Antworten zurückzugeben, vor dem INSERT auf jede Bedingung prüfen:

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. Zuerst auf Doppelreservierung prüfen
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. Verbleibende Kapazität prüfen
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // Aufrufer mappt null → 409 slot-full
    }

    // 3. INSERT — UNIQUE-Constraint als letzter Schutz
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

Eine **Domain-Exception** (`AlreadyReservedException`) für die benutzerbezogene Geschäftsregel verwenden,
nicht `DatabaseConstraintException` — die ein Datenbankschicht-Ereignis signalisiert, keine Geschäftsbedingung.

---

## 3. Handler: auf unterschiedliche 409-Antworten mappen

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. Verfügbarkeit in SQL berechnen (N+1 vermeiden)

Reservierungen in derselben Abfrage wie den Slot-Abruf zählen:

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

Dann `available = capacity - reserved`. Niemals alle Reservierungen abrufen, um sie in PHP zu zählen.

---

## 5. Nebenläufigkeit: TOCTOU und wann es wichtig ist

Das explizite Prüfen-dann-Einfügen-Muster hat ein **TOCTOU (Time-of-Check / Time-of-Use)-Fenster**:
Zwei gleichzeitige Anfragen können beide die Kapazitätsprüfung bestehen und dann beide versuchen zu INSERT.

| Datenbank | Verhalten |
|---|---|
| **SQLite** | Pro-Datenbank-Schreibserialisierung: nur ein Schreibvorgang läuft gleichzeitig. Das zweite INSERT trifft den UNIQUE-Constraint und wirft `DatabaseConstraintException`. Sicher. |
| **PostgreSQL** unter hoher Nebenläufigkeit | Zwei Anfragen mit unterschiedlichen `user_id` können beide die `available > 0`-Prüfung bestehen und beide INSERT, was kurzzeitig die Kapazität um 1 überschreitet. Der UNIQUE-Constraint löst nicht aus (verschiedene Benutzer). |

**Fix für PostgreSQL**: Prüfung und INSERT in eine `SERIALIZABLE`-Transaktion einwickeln, oder
`SELECT ... FOR UPDATE` auf der Slot-Zeile verwenden, um sie vor dem Lesen zu sperren:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // Alle Abfragen in diesem Closure teilen dasselbe serialisierbare Snapshot
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

Für SQLite ist der UNIQUE-Constraint allein ausreichender Schutz.

---

## 6. Gleichzeitigkeitsähnliche Szenarien testen

Sequentielle Tests können echte Nebenläufigkeit nicht reproduzieren, aber sie können die Absicht verifizieren:

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // kommt „gleichzeitig" an

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // Slot voll

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // Nach Stornierung kann Bob buchen
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## Hinweise

- Der UNIQUE-Constraint ist ein **Letztes-Mittel-Schutz** — er fängt Fehler in der Anwendungslogik auf.
  Nie als primärem Kapazitätsdurchsetzer darauf verlassen, da er nicht zwischen Doppelbenutzer
  und Überkapazität unterscheiden kann.
- **Stornieren und neu reservieren**: Wenn ein Benutzer storniert, aus `reservations` löschen. Die Kapazitätsanzahl
  verringert sich automatisch über die `COUNT(r.id)`-Abfrage. Kein explizites „Slot-freigeben"-Update nötig.
- **Idempotente Stornierung**: `DELETE WHERE slot_id = ? AND user_id = ?` gibt 0 Zeilen zurück, wenn
  die Reservierung nicht existiert — auf 404 mappen, nicht auf 500.
