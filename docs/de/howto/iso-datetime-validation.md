# How-to: ISO 8601-Datumszeit mit Zeitzone validieren

Das Akzeptieren von benutzerkontrollierten Datums-/Zeit-Strings erfordert sorgfältige Validierung. Diese Anleitung behandelt die zwei wichtigsten Fallstricke: **PHP, das ungültige Zeitzonen-Offsets stillschweigend akzeptiert**, und **String-Vergleich, der über verschiedene Zeitzonen-Offsets hinweg fehlschlägt**.

---

## V::isoDatetime — Format-Validierung

```php
V::isoDatetime(mixed $raw): ?string
```

Validiert einen Datums-/Zeit-String im `±HH:MM`-Offset-Format:

```
✅ 2024-01-15T12:30:00+09:00   (JST)
✅ 2024-06-01T00:00:00+00:00   (UTC)
✅ 2024-12-31T23:59:59-05:00   (EST)
✅ 2026-06-15T09:00:00-14:00   (UTC−14, Howland Island)
✅ 2026-06-15T09:00:00+14:00   (UTC+14, Kiribati)

❌ 2024-01-15                   (nur Datum, keine Zeit)
❌ 2024-01-15T12:00:00Z         ('Z'-Suffix, kein ±HH:MM)
❌ 2024-01-15T12:00:00          (kein Offset)
❌ 2024-02-30T00:00:00+00:00   (30. Feb. existiert nicht)
❌ 2024-13-01T00:00:00+00:00   (Monat 13 existiert nicht)
❌ 2026-06-15T09:00:00+25:00   (ungültiger Offset — überschreitet +14:00)
```

### Implementierung

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // Strikter Regex: ±HH:MM erforderlich, kein Z, keine bloße Zeit
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Offset-Bereich validieren: gültige UTC-Offsets sind −14:00 … +14:00.
    // PHPs DateTimeImmutable akzeptiert +25:00 und ähnliche ungültige Offsets stillschweigend.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable bewahrt die Eingabe-Zeitzone — vermeidet strtotime + date()
    // die stillschweigend in der lokalen Zeitzone des Servers neu formatieren.
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // Round-Trip-Vergleich fängt Überlaufdaten ab (30. Feb. rollt auf 1. Mär., usw.)
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### Warum nicht `strtotime` + `date()`?

```php
// ❌ FALSCH — date() verwendet die lokale Zeitzone des Servers
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// Wenn Server UTC ist: '2024-01-15T03:30:00+00:00' — Zeitzone verloren!
```

```php
// ✅ KORREKT — DateTimeImmutable bewahrt den ursprünglichen Offset
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — Zukunfts-Check über Zeitzonen hinweg

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

Gibt den validierten String nur zurück, wenn die Datumszeit **strikt nach** `$now` liegt.

### Der kritische Fehler: String-Vergleich schlägt über Zeitzonen fehl

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 1 Stunde in der VERGANGENHEIT
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ FALSCH: String-Vergleich sagt Zukunft ("T18" > "T10")
$pastJst > $now  // → TRUE   ← FALSCH! Es liegt in der Vergangenheit!

// ✅ KORREKT: DateTimeImmutable-Vergleich normalisiert zuerst auf UTC
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓ (korrekt in der Vergangenheit)
```

Der umgekehrte Fehler tritt auch bei negativen Offsets auf:

```php
// EST 08:00 = UTC 13:00 → 3 Stunden in der ZUKUNFT
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ FALSCH: String-Vergleich sagt Vergangenheit ("T08" < "T10")
$futureEst > $now  // → FALSE  ← FALSCH! Es liegt in der Zukunft!

// ✅ KORREKT: Objekt-Vergleich
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓ (korrekt in der Zukunft)
```

### Implementierung

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // Objekt-Vergleich normalisiert beide auf UTC vor dem Vergleich.
    return $dtObj > $nowObj ? $dt : null;
}
```

### Verwendung in einem Routen-Handler

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // DateTimeImmutable für ein zeitzonenerhaltenes "now" verwenden
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt ist jetzt sicher zu speichern — der exakt eingereichte String, Zeitzone erhalten.
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## Zeitzonenerhaltung

`remind_at` (oder jede vom Benutzer eingereichte Datumszeit) genau wie validiert speichern — nicht nach UTC konvertieren.

```php
// ✅ Den validierten String unverändert speichern
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// mit :remind_at = '2026-06-15T09:00:00+09:00'

// Unverändert in der API-Antwort zurückgeben
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

Dies respektiert die Absicht des Benutzers und vermeidet implizite Zeitzonenkonvertierung. Wenn die Anwendung UTC-Normalisierung für die SQL-Sortierung/-Vergleich benötigt, eine separate `remind_at_utc`-Spalte hinzufügen, die bei Schreibzeit berechnet wird.

---

## Validierte Eingaben → sicheres SQL

Nach `V::isoDatetime()` / `V::futureDatetime()` ist der String sicher für die Einfügung über eine parametrisierte Abfrage. Niemals rohe Datums-/Zeit-Strings in SQL interpolieren.

```php
// ✅ Sicher — vorvalidiert, parametrisiert
$stmt->execute(['remind_at' => $remindAt]);

// ❌ Gefährlich — rohe Benutzereingabe interpoliert
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## Verwandte Themen

- FT181 — reminderlog: ISO 8601-Datums-/Zeit-Validierung & Zeitzone-bewusste API  
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — Datum und Zeit im Internet  
- [IANA Time Zone Database](https://www.iana.org/time-zones) — UTC-Offset-Referenz  
- `docs/howto/json-merge-patch.md` — verwendet ebenfalls isoDatetime für created_at
