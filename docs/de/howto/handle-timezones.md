# So behandeln Sie Zeitzonen

PHPs Zeitzonenbehandlung hat mehrere stille Fehler-Modi. Diese Anleitung behandelt die Muster und Fallstricke aus realen NENE2-Field-Trials.

## Immer die Zeitzone angeben, wenn `DateTimeImmutable` erstellt wird

`new DateTimeImmutable('now')` verwendet die Server-`date.timezone`-ini-Einstellung, die zwischen Umgebungen unterschiedlich ist. Immer `UTC` explizit für server-seitige Timestamps übergeben:

```php
// Fragil — hängt vom server's date.timezone ab
$now = new \DateTimeImmutable('now');

// Korrekt — immer UTC
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
```

Für gespeicherte Timestamps als ISO8601 UTC formatieren:

```php
$now->format('Y-m-d\TH:i:s\Z') // → "2026-05-20T15:00:00Z"
```

## IANA-Zeitzonenbezeichner explizit validieren

PHPs `DateTimeZone`-Konstruktor akzeptiert Zeitzonenabkürzungen wie `"EST"` ohne Exception, aber sie sind keine kanonischen IANA-Bezeichner. `"America/New_York"` ist die korrekte IANA-Form.

```php
// Dies gelingt — aber "EST" ist kein IANA-Bezeichner
$tz = new \DateTimeZone('EST'); // keine Exception!

// Korrekte Validierung:
try {
    $tz = new \DateTimeZone($input);
} catch (\Exception) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}

// Zusätzliche Mitgliedschaftsprüfung für Nicht-IANA-Abkürzungen:
if (!in_array($input, \DateTimeZone::listIdentifiers(), true)) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}
```

Ohne die `listIdentifiers()`-Prüfung werden `"EST"`, `"PST"` und ähnliche Abkürzungen still akzeptiert.

## Lokale Datetime-Strings mit `createFromFormat` parsen

Beim Akzeptieren eines lokalen Datetime-Werts aus Benutzereingaben (ohne Zeitzonenoffset), `createFromFormat` mit dem expliziten Format und der Zeitzone verwenden:

```php
$tz    = new \DateTimeZone('Asia/Tokyo');
$local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-06-01T10:00:00', $tz);

if ($local === false) {
    // Ungültiges Format — '2026/06/01 10:00', '2026-06-01', etc. geben alle false zurück
    throw new \InvalidArgumentException('Invalid datetime format. Expected YYYY-MM-DDTHH:mm:ss.');
}
```

`createFromFormat` gegenüber `new DateTimeImmutable($str, $tz)` bevorzugen — der Konstruktor ist nachsichtig und akzeptiert viele Formate still.

## Lokale Zeit für die Speicherung in UTC konvertieren

```php
$utc = $local->setTimezone(new \DateTimeZone('UTC'));
// Speichern als: $utc->format('Y-m-d\TH:i:s\Z')
```

Immer UTC in der Datenbank speichern. Den ursprünglichen Zeitzonennamen daneben speichern, damit die lokale Zeit beim Abrufen rekonstruiert werden kann.

## DST-Übergang: Mehrdeutige Wanduhr-Zeiten

Während "Zurückfallen"-Übergängen (z. B. `America/New_York` am ersten Sonntag im November) kommen manche Wanduhrzeiten zweimal vor:

- `2026-11-01 01:30 AM` existiert sowohl in EDT (UTC-4) als auch EST (UTC-5)

PHP löst die Mehrdeutigkeit auf, indem es das **erste Vorkommen** wählt (Sommer-/DST-Zeit):

```php
$dt = \DateTimeImmutable::createFromFormat(
    'Y-m-d\TH:i:s',
    '2026-11-01T01:30:00',
    new \DateTimeZone('America/New_York'),
);
// → 05:30 UTC (EDT = UTC-4), nicht 06:30 UTC (EST = UTC-5)
```

Dies entspricht dem IANA-Standard. Wenn Ihre Anwendung zwischen den zwei Vorkommen unterscheiden muss (z. B. für Kalendersysteme), muss dies auf Anwendungsebene behandelt werden — PHP stellt keine API zur Auswahl des zweiten Vorkommens bereit.

## Vollständiges lokal→UTC-Konvertierungsmuster

```php
use Schedule\Event\InvalidTimezoneException;

function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
{
    try {
        $tz = new \DateTimeZone($ianaTimezone);
    } catch (\Exception) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    // Nicht-IANA-Abkürzungen ablehnen (z. B. "EST")
    if (!in_array($ianaTimezone, \DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

    if ($local === false) {
        throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
}
```

## Multi-Timezone-Listenabfragen

Beim Auflisten von in UTC gespeicherten Ereignissen, in die Zeitzone des Betrachters konvertieren:

```php
$viewTz = QueryStringParser::string($request, 'timezone');

if ($viewTz !== null) {
    try {
        $tz    = new \DateTimeZone($viewTz);
        $local = (new \DateTimeImmutable($event->startUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $data['start_local'] = $local->format('Y-m-d\TH:i:s');
    } catch (\Exception) {
        // Ungültige angeforderte Zeitzone — Konvertierung still auslassen
    }
}
```

---

## SQLite-spezifisch: `datetime('now')` gibt immer UTC zurück

SQLites eingebaute Datum-/Zeitfunktionen arbeiten immer in **UTC**, unabhängig von der OS-Zeitzone des Servers oder PHPs `date.timezone`-Einstellung.

```sql
SELECT datetime('now');          -- → "2026-05-27 11:30:00"  (UTC)
SELECT date('now');              -- → "2026-05-27"            (UTC-Datum)
SELECT date('now', '+1 day');    -- → "2026-05-28"            (UTC + 1 Tag)
SELECT datetime('now', '-9 hours'); -- → lokale JST-Annäherung (manualem Offset — vermeiden)
```

**Das ist normalerweise das, was man will**: Timestamps als UTC TEXT speichern und in UTC vergleichen.

### Filtern nach "heute" in UTC

```sql
-- Heute erstellte Einträge (UTC)
SELECT * FROM events WHERE DATE(created_at) = DATE('now');

-- Einträge in den nächsten 30 Tagen (UTC)
SELECT * FROM reminders WHERE reminder_at <= DATE('now', '+30 days');

-- Einträge für einen bestimmten Monat (UTC)
SELECT * FROM logs WHERE STRFTIME('%Y-%m', created_at) = '2026-05';
```

### Die Falle: "heute" unterscheidet sich je nach Zeitzone

Wenn die Benutzer in JST (UTC+9) sind, beginnt "heute" in JST 9 Stunden vor "heute" in UTC. `DATE('now')` in SQLite gibt das UTC-Datum zurück — das ist eine Abweichung.

```php
// Falsch: SQLite DATE('now') = UTC-Datum, nicht das lokale Datum des Benutzers
$rows = $this->db->fetchAll("SELECT * FROM tasks WHERE DATE(due_date) = DATE('now')");

// Korrekt: "heute" des Benutzers in PHP berechnen und als Parameter übergeben
$todayUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
$rows = $this->db->fetchAll(
    "SELECT * FROM tasks WHERE DATE(due_date) = ?",
    [$todayUtc],
);
```

Für einen Dienst, bei dem "heute" UTC bedeutet, ist `DATE('now')` in Ordnung. Für benutzerseitige "heute fällig"-Funktionen, die Grenze in PHP mit der Zeitzone des Benutzers berechnen und als Bind-Parameter übergeben.

### Dynamisches Intervall mit einem Spaltenwert

SQLite erlaubt die Kombination von `date()` mit einem aus einem Spaltenwert gebauten String:

```sql
-- Einträge, bei denen next_review_at = heute basierend auf der interval_days-Spalte
SELECT * FROM cards WHERE next_review_at <= DATE('now');

-- Nächstes Review-Datum dynamisch berechnen (Ergebnis speichern, nicht in SELECT darauf verlassen)
SELECT DATE('now', '+' || interval_days || ' days') AS next_date FROM cards;
```

Dies ist nützlich in einem `UPDATE`-Statement beim Vorrücken eines Zeitplans:

```php
$this->db->execute(
    "UPDATE cards SET next_review_at = DATE('now', '+' || interval_days || ' days') WHERE id = ?",
    [$cardId],
);
```

### `STRFTIME`-Format-Referenz

| Muster | Ausgabe | Verwendung |
|--------|---------|------------|
| `%Y-%m-%d` | `2026-05-27` | Vollständiges Datum |
| `%Y-%m` | `2026-05` | Jahr-Monat-Gruppierung |
| `%Y-%W` | `2026-22` | Jahr + **Sonntag-beginnende** Wochennummer (0–53) |
| `%H:%M:%S` | `11:30:00` | Nur Zeit |
| `%s` | Unix-Timestamp | Ganzzahl-Sekunden seit Epoch |

**`%W` beginnt am Sonntag**, nicht ISO 8601 (Montag-beginnend). Für Montag-beginnende Wochennummern, die Wochengrenze in PHP berechnen:

```php
// Den Montag der aktuellen ISO-Woche abrufen
$monday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('Monday this week')
    ->format('Y-m-d');

$sunday = (new \DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');

$rows = $this->db->fetchAll(
    "SELECT * FROM workouts WHERE workout_date BETWEEN ? AND ?",
    [$monday, $sunday],
);
```
