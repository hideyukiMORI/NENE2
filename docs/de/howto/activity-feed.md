# How-to: Activity-Feed / Timeline-API

> **FT-Referenz**: FT277 (`NENE2-FT/feedlog`) — Activity-Feed: typenlistenbasierte Events (9 Typen), JSON-Payload pro Event, benutzerbezogener Feed mit IDOR → 404, Paginierungs-Clamping (max. 100), Admin fail-closed, 24 Tests / 37 Assertions PASS.
>
> Auch validiert in FT219 (`NENE2-FT/feedlog` Vorläufer) — VULN-Bewertung zum gleichen Muster.

Diese Anleitung zeigt, wie Sie mit NENE2 ein Activity-Feed-System mit typisierten Events, Benutzer-Scoping und Paginierung aufbauen.

## Funktionen

- Typisierte Activity-Events posten (streng allowlisted)
- JSON-Payload-Speicherung (beliebige Metadaten pro Event-Typ)
- Benutzerbezogener Feed mit IDOR-Schutz (gibt 404 bei unberechtigtem Zugriff zurück)
- Event-Typ-Filterung über Query-Parameter
- Zeitstempel-absteigende Paginierung (neueste zuerst)
- Admin kann Events im Namen von Benutzern posten

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/events` | Benutzer | Activity-Event posten |
| `GET` | `/users/{userId}/feed` | Benutzer (selbst oder Admin) | Feed mit optionalem Typfilter abrufen |

## Event-Typ-Allowlist (VULN-B)

Eine strikte Allowlist für Event-Typen verhindert Mass-Assignment und beliebige Event-Injection:

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## Payload-Speicherung

Payloads werden als JSON-Strings gespeichert und beim Abruf dekodiert:

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## IDOR-Schutz (VULN-C)

Der Feed-Zugriff gibt 404 (nicht 403) zurück, wenn ein unberechtigter Benutzer versucht, den Feed eines anderen Benutzers anzusehen:

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Paginierung mit Typfilterung

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

Unbekannte Typen im `?type=`-Parameter werden stillschweigend ignoriert (null = kein Filter angewendet).

## VULN-Bewertungsergebnisse (FT219)

- **VULN-B**: `in_array(..., strict: true)` verhindert jeden nicht gelisteten Event-Typ
- **VULN-C**: IDOR gibt 404 zurück, um die Feed-Existenz vor unberechtigten Aufrufern zu verbergen
- **VULN-D**: Admin fail-closed — leerer Admin-Schlüssel gibt immer false zurück
- **VULN-F**: `is_array($payload)` stellt sicher, dass der Payload immer ein JSON-Objekt ist, kein Skalar
- **VULN-G**: `ctype_digit()` schützt den `userId`-Pfadparameter
- **VULN-I**: `clampInt()` begrenzt `limit` (1–100) und `offset` (0–MAX_INT)

## Sicherheitsmuster

- **`ctype_digit()`**: ReDoS-sichere Ganzzahlvalidierung für Pfadparameter
- **`is_array()`**: Payload muss ein JSON-Objekt (Array in PHP) sein — kein String, keine Zahl, kein Null
- **Parametrisierte Abfragen**: Alle SQL-Abfragen verwenden `:named`-Parameter — keine String-Konkatenation
- **`in_array(..., true)`**: Strenger Vergleich verhindert Typ-Coercion-Bypass

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Beliebigen Event-Typ-String akzeptieren | Unkontrollierte Typen verschmutzen den Feed; schwer typenspezifische Abfragen zu erstellen |
| Payload als TEXT ohne JSON-Validierung speichern | `is_array($payload)` stellt ein JSON-Objekt sicher; Skalare/Arrays beschädigen nachgelagerte Konsumenten |
| Rohes `limit` aus dem Query-String übernehmen | Keine Obergrenze → Full-Table-Scan bei großen Datensätzen |
| `in_array($type, TYPES)` ohne `true` verwenden | Loser Vergleich; `0 == 'post_created'` in einigen PHP-Versionen |
| 403 bei Feed-Zugriff für falschen Benutzer zurückgeben | Verrät, dass der Benutzer existiert; 404 zur Verhinderung von Benutzer-Enumeration verwenden |
| Nur nach `user_id` indizieren | Fehlender `id DESC` im kombinierten Index verursacht langsames ORDER BY bei großen Feeds |
