# Anleitung: Zeitzonen-bewusste Ereignisplanung

> **FT-Referenz**: FT286 (`NENE2-FT/schedulelog`) — Zeitzonen-bewusste Planung: UTC-Speicherung + Lokalzeit-Konvertierung, IANA-Zeitzonen-Validierung via DateTimeZone::listIdentifiers(), InvalidTimezoneException, dynamischer ?timezone-Abfrageparameter, 19 Tests / 39 Assertions BESTANDEN.

Diese Anleitung zeigt, wie eine Ereignisplanungs-API gebaut wird, die Zeiten in UTC speichert und sie in jeder Zeitzone darstellt, die der Client anfordert.

## Warum in UTC speichern?

UTC ist der universelle Referenzpunkt. Lokalzeiten sind mehrdeutig (Sommerzeitwechsel, Zeitzonen-Regeländerungen) und variieren nach Client-Standort. Durch Speicherung in UTC:
- Sortierung und Vergleich sind immer korrekt
- Clients können in ihrer lokalen Zeitzone anzeigen
- Sommerzeitübergänge schaffen keine Mehrdeutigkeit in historischen Daten

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- IANA-Zeitzone des Event-Erstellers
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601: 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- Lokal ISO 8601: 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

Sowohl `start_utc` als auch `start_local` werden gespeichert. `start_utc` ist maßgeblich; `start_local` ist ein Convenience-Cache für die Zeitzone des Erstellers.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/events` | Ereignis erstellen (Zeitzone + lokale Startzeit → UTC) |
| `GET` | `/events` | Ereignisse auflisten (optional `?timezone=America/New_York`) |
| `GET` | `/events/{id}` | Ereignis abrufen (optional `?timezone=`) |

## IANA-Zeitzonen-Validierung

PHPs `DateTimeZone`-Konstruktor akzeptiert einige ungültige Bezeichner stillschweigend. Explizit validieren:

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        // PHP akzeptiert in manchen Versionen ungültige Abkürzungen wie "EST" —
        // explizit gegen die kanonische IANA-Liste validieren.
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` gibt die PHP-kompilierte Liste von IANA-Bezeichnern zurück. Nicht-IANA-Strings (wie `EST`, `GMT+5`) werden abgelehnt.

## Ereignis erstellen: Lokal → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Unknown timezone: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Cannot parse datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## Ereignisse auflisten: Dynamische Zeitzonen-Konvertierung

Der `?timezone=`-Abfrageparameter konvertiert alle Ereignisse sofort in die Zeitzone des Clients:

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // Ungültige Ansichts-Zeitzone: stillschweigend UTC zurückgeben
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

Ungültige `?timezone=`-Werte fallen stillschweigend auf das gespeicherte `start_local` zurück, statt einen Fehler zurückzugeben — eine Designentscheidung, die für schreibgeschützte Ansichten geeignet ist.

## UTC-Format: ISO 8601 mit Z-Suffix

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ Literal Z
}
```

Das `Z`-Suffix zeigt explizit UTC an (gemäß ISO 8601 / RFC 3339). `+00:00` zu verwenden oder den Offset wegzulassen sind akzeptable Alternativen, aber `Z` ist kompakter und universell erkannt.

## DST-sichere Konvertierung

```
Beispiel: Asia/Tokyo ist UTC+9 (keine Sommerzeit)
Lokal: 2026-05-20T10:00:00  Asia/Tokyo
UTC:   2026-05-20T01:00:00Z

Beispiel: America/New_York (Sommerzeit)
Lokal: 2026-05-20T10:00:00  America/New_York (EDT = UTC-4 im Sommer)
UTC:   2026-05-20T14:00:00Z

Lokal: 2026-01-20T10:00:00  America/New_York (EST = UTC-5 im Winter)
UTC:   2026-01-20T15:00:00Z
```

`DateTimeImmutable` mit einer benannten IANA-Zeitzone behandelt Sommerzeit automatisch. Es verwendet den Offset, der an diesem bestimmten Datum aktiv ist, nicht einen festen Offset.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Lokalzeit ohne Zeitzonen-Spalte speichern | Kann später nicht in UTC konvertiert werden; historische Daten werden nach Sommerzeitwechseln mehrdeutig |
| `EST`, `PST`, `GMT+5` als Zeitzone akzeptieren | Mehrdeutige Abkürzungen; einige sind mehreren IANA-Zonen zugeordnet; `DateTimeZone::listIdentifiers()` lehnt diese ab |
| `new DateTimeZone($tz)` ohne Prüfung von `listIdentifiers()` verwenden | PHP akzeptiert stillschweigend einige ungültige oder veraltete Bezeichner; kanonische Validierung erfasst sie |
| UTC-Offset (`+09:00`) statt IANA-Name speichern | Offset allein kann Sommerzeit nicht behandeln; `Asia/Tokyo` ist immer +9, aber `America/New_York` variiert |
| Ereignisse nach `start_local` sortieren | Lexikografische Sortierung nach Lokalzeiten ignoriert Zeitzonenunterschiede; immer nach `start_utc` sortieren |
| Zeitzone bei jeder Abfrage konvertieren | Teuer für große Datensätze; Caching oder Vorberechnung häufiger Ansichts-Zeitzonen in Betracht ziehen |
| 422 für ungültiges `?timezone=` in GET zurückgeben | Schreibgeschützte Abfragen sollten gracefully degradieren; auf UTC zurückfallen statt Fehler |
| `date()` statt `DateTimeImmutable` verwenden | `date()` verwendet die Standard-Zeitzone des Servers; `DateTimeImmutable` mit expliziten Zonen ist vorhersehbar |
