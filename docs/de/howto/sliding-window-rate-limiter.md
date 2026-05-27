# How-to: Gleitendes-Fenster-Ratenbegrenzer

## Überblick

Diese Anleitung beschreibt den Aufbau eines pro-Benutzer und pro-Endpunkt gleitenden
Fenster-Ratenbegrenzers mit NENE2. Anfragen werden innerhalb eines gleitenden Zeitfensters
gezählt; sobald das Limit erreicht ist, werden weitere Anfragen mit `429 Too Many Requests`
abgelehnt.

**Referenzimplementierung**: `../NENE2-FT/ratelog/`

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

Der Index auf `(user_id, endpoint, created_at)` macht die COUNT-Abfrage bei größerem Datenvolumen schnell.

---

## Routentabelle

| Methode   | Pfad | Auth  | Beschreibung |
|-----------|------|-------|-------------|
| `POST`    | `/rate/check` | Benutzer | Anfrage protokollieren; gibt 429 zurück wenn Limit überschritten |
| `GET`     | `/rate/status` | Benutzer | Aktuelle Nutzung für einen Benutzer/Endpunkt |
| `DELETE`  | `/rate/reset/{userId}` | Admin | Zähler für einen Benutzer zurücksetzen |

---

## Kernalgorithmus

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60s
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**Gleitendes Fenster**: jeder `check()`-Aufruf schaut genau `WINDOW_SECONDS` vom aktuellen Zeitpunkt
zurück, sodass alte Ereignisse natürlich aus dem Gültigkeitsbereich fallen.

---

## Admin-Reset mit Fail-Closed-Muster

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // fail-closed: unkonfigurierter Key blockiert alle Admin-Zugriffe
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Alle Zähler für einen Benutzer zurücksetzen (alle Endpunkte):
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

Für einen bestimmten Endpunkt zurücksetzen:
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## Pfadparameter-Extraktion (ohne Router::param())

Wenn `Router::param()` in der installierten Version nicht verfügbar ist, das Attribut direkt verwenden:

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## Validierung

- `endpoint`: nicht-leerer String, max. 128 Zeichen
- `X-User-Id`: `ctype_digit()` + positive Integer
- Pfad `userId`: `ctype_digit()` + positive Integer (Fehler → 404)
- Admin-Key: `hash_equals()`-Vergleich (Fehler → 403)

---

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Anfrage erlaubt | 200 |
| Status abgerufen | 200 |
| Zähler zurückgesetzt | 200 |
| Kein X-User-Id | 400 |
| Kein Body | 400 |
| Leerer / fehlender Endpunkt | 422 |
| Endpunkt zu lang | 422 |
| Kein Admin-Key | 403 |
| Falscher Admin-Key | 403 |
| Ungültige userId im Pfad | 404 |
| Ratenlimit überschritten | 429 |

---

## Abgedeckte ATK-Angriffsmuster

| ATK | Muster | Abwehr |
|-----|--------|--------|
| ATK-01 | Fehlender X-User-Id | 400 mit Meldung |
| ATK-02 | Leere Endpunkt-Zeichenkette | 422 Validierung |
| ATK-03 | 129-Zeichen-Endpunkt (DoS) | Längenbegrenzung 422 |
| ATK-04 | SQL-Injection im Endpunkt | Parametrisierte Abfragen |
| ATK-05 | Nicht-Admin-Reset-Versuch | 403 fail-closed |
| ATK-06 | Falscher Admin-Key | 403 hash_equals() |
| ATK-07 | Negative userId im Pfad | 404 |
| ATK-08 | userId null | 404 |
| ATK-09 | Nicht-Ziffer-userId (`abc`) | 404 ctype_digit |
| ATK-10 | Status ohne Endpunkt-Parameter | 422 |
| ATK-11 | Check ohne Body | 400 |
| ATK-12 | Body ohne Endpunkt-Schlüssel | 422 |

---

## Hinweise

- **Gleichzeitigkeit**: Das gleitende Fenster hat ein kleines TOCTOU-Fenster. Für produktive
  Hochlastanwendungen atomare Zähler in Betracht ziehen (Redis INCR + EXPIRE) oder Datenbanksperren.
- **Uhrenabweichung**: Alle Zeitstempel sollten UTC verwenden, um DST- oder Zeitzonenprobleme zu vermeiden.
- **Speicherwachstum**: Alte Ereignisse akkumulieren sich. Einen periodischen Bereinigungsjob hinzufügen:
  `DELETE FROM rate_events WHERE created_at < :cutoff`.
