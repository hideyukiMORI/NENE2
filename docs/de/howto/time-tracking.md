# Anleitung: Zeiterfassungs-API

> **FT-Referenz**: FT246 (`NENE2-FT/timelog`) — Zeiterfassungs-API

Demonstriert eine Stoppuhr-basierte Zeiterfassungs-API, bei der ein Zeiterfassungs-Eintrag eine `start_time`
und eine nullable `end_time` hat (`NULL` = läuft, nicht-`NULL` = gestoppt), nur ein Timer gleichzeitig
laufen kann, die Dauer über SQLites `strftime('%s', ...)` berechnet wird und tägliche Zusammenfassungen
die gesamten erfassten Sekunden pro Kalendertag aggregieren.

---

## Routen

| Methode   | Pfad              | Beschreibung                                                      |
|-----------|-------------------|------------------------------------------------------------------|
| `POST`   | `/timers/start`   | Neuen Timer starten (schlägt fehl, wenn einer bereits läuft)     |
| `POST`   | `/timers/stop`    | Den aktuell laufenden Timer stoppen                               |
| `GET`    | `/timers/running` | Den aktuell laufenden Timer abrufen (oder `running: false`)       |
| `GET`    | `/timers/summary` | Tägliche Zusammenfassung: Gesamtsekunden und Eintragsanzahl pro Tag |
| `GET`    | `/timers`         | Einträge auflisten (paginiert, filterbar nach Label und Datum)   |
| `GET`    | `/timers/{id}`    | Einen einzelnen Zeiterfassungs-Eintrag abrufen                   |
| `DELETE` | `/timers/{id}`    | Einen Zeiterfassungs-Eintrag löschen (`204 No Content`)          |

> **Statische Routen zuerst**: `/timers/start`, `/timers/stop`, `/timers/running`,
> `/timers/summary` werden alle vor `/timers/{id}` registriert, damit Literal-Pfade
> nicht als parametrisierte Segmente erfasst werden.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = läuft
    created_at TEXT NOT NULL
);
```

`end_time` ist nullable — `NULL` bedeutet, der Timer läuft noch. `NOT NULL` bedeutet, er wurde gestoppt.
Es gibt keine separate `status`-Spalte; das Vorhandensein oder Fehlen von `end_time` kodiert den Laufzustand.

---

## Laufzustand: `end_time IS NULL`

Der Laufzustand des Timers wird ausschließlich aus der `end_time`-Spalte erkannt:

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // läuft noch — noch keine Dauer
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` gibt `true` zurück, wenn `endTime` `null` ist. `durationSeconds()` gibt
`null` für laufende Timer zurück — die Dauer kann erst berechnet werden, wenn der Timer gestoppt wird.
Die Antwort enthält `"running": true` und `"duration_seconds": null` für aktive Einträge.

---

## Singleton-Timer: nur einer kann gleichzeitig laufen

`start()` prüft auf einen laufenden Timer, bevor ein neuer erstellt wird:

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

Wenn ein Timer bereits läuft, wird `TimerAlreadyRunningException` geworfen → `409 Conflict`.
`end_time` wird als Literal-`NULL`-SQL-Wert eingefügt.

Die Suche nach dem laufenden Timer:

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — Standard-SQL-`NULL`-Vergleich (nicht `= NULL`). `LIMIT 1`
schützt davor, mehrere Zeilen zurückzugeben, wenn die Invariante jemals verletzt wird.

---

## Timer stoppen: `stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` findet den laufenden Timer, setzt `end_time` und gibt den aktualisierten Eintrag mit
der berechneten Dauer zurück. `NoRunningTimerException` wird geworfen, wenn kein Timer läuft →
`409 Conflict`.

---

## Dauerberechnung: `strftime('%s', ...)` in SQL

Für aggregierte Zusammenfassungen wird die Dauer in SQL über SQLites `strftime('%s', ...)`-Funktion berechnet, die die Unix-Epochensekunden eines Datumszeit-Strings als Ganzzahl zurückgibt:

```sql
SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds
```

`strftime('%s', ...)` parst den ISO-Datumszeit-String (einschließlich eines `±HH:MM`-Offsets, der nach UTC
normalisiert wird) und gibt ganze Epochensekunden zurück. Die Subtraktion der beiden ergibt die exakte
Dauer in Sekunden — passend zur PHP-seitigen `getTimestamp()`-Differenz.

> **Fallstrick — `julianday()` nicht für Sekundengenauigkeit verwenden.** Die Formel
> `CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)` ist verlockend, aber die
> `julianday`-Differenz ist ein Gleitkommawert knapp unter der ganzen Sekunde, sodass das `CAST(... AS INTEGER)`
> einen 60-Sekunden-Eintrag auf **59** kürzt. Verwenden Sie stattdessen `strftime('%s', ...)` (ganze
> Epochensekunden) — das ist exakt. (Gefunden durch die PHPUnit-Suite des FT246-`timelog`-Beispiels.)

`SUM(...)` summiert alle abgeschlossenen Einträge für den Tag. `WHERE end_time IS NOT NULL`
filtert alle noch laufenden Timer aus der Zusammenfassung.

Die PHP-seitige Berechnung für einzelne Einträge:

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

Beide Ansätze produzieren dasselbe Ergebnis für UTC-Zeitstempel. Der SQL-Ansatz wird für Aggregation
verwendet (er vermeidet das Abrufen aller Zeilen zum Summieren); der PHP-Ansatz wird für die
Serialisierung einzelner Einträge verwendet.

---

## Tägliche Zusammenfassungsaggregation

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` extrahiert das Kalenderdatum aus dem `start_time`-ISO-String.
`GROUP BY day` gruppiert alle abgeschlossenen Einträge für denselben Tag.
`ORDER BY day DESC` gibt die neuesten Tage zuerst zurück.

Die `$where`-Klausel beginnt immer mit `['end_time IS NOT NULL']`, um laufende Timer auszuschließen,
und fügt dann optional `date(start_time) >= ?` und `date(start_time) <= ?` für den Datumsbereichsfilter hinzu.

---

## `date()`-Funktion für datumsbasierte Filterung

Das Filtern von Einträgen nach Kalenderdatum verwendet SQLites `date()`-Funktion:

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` extrahiert nur `YYYY-MM-DD` aus dem ISO-Datumszeit-String.
`= ?` vergleicht das extrahierte Datum mit dem Filterwert. Dies trifft korrekt alle
Einträge, die am angegebenen Tag begonnen haben, unabhängig von der Zeitkomponente.

---

## Label-Filterung mit `LIKE`

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` führt einen Groß-/Kleinschreibungsunempfindlichen Teilstring-Vergleich in SQLites
Standard-Kollation durch. Sonderzeichen `%` und `_` in `$label` werden als LIKE-Wildcards interpretiert
— escapen wenn strikt-literales Matching erforderlich ist.

---

## `GET /timers/running`-Antwortvertrag

Der Laufend-Endpunkt gibt eine konsistente Form zurück, ob ein Timer aktiv ist oder nicht:

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — kein Timer aktiv.
`running: true, entry: {...}` — aktiver Timer mit `end_time: null` und `duration_seconds: null`.

Dies vermeidet ein `404` für „kein laufender Timer" — `404` impliziert, die Ressource existiert nicht,
aber das Konzept „laufender Timer" existiert immer (es ist nur leer). `running: false` zu verwenden
ist semantisch sauberer.

---

## Verwandte Anleitungen

- [`shift-management.md`](shift-management.md) — Schicht-Ein-/Ausstempeln mit nullable Endzeit
- [`scheduled-reminders.md`](scheduled-reminders.md) — Zeitzonen-bewusste Datumszeit-Validierung
- [`aggregate-reporting.md`](aggregate-reporting.md) — `GROUP BY date`-Aggregationsmuster
- [`handle-timezones.md`](handle-timezones.md) — UTC-Speicherung und Zeitzonen-Konvertierung
