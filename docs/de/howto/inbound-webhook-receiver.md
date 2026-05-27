# So fügen Sie einen Inbound-Webhook-Empfänger hinzu

Webhooks von mehreren externen Diensten empfangen, HMAC-Signaturen pro Quelle validieren und Events mit Idempotenz speichern.

## Schema

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/sources` | Webhook-Quelle registrieren |
| `POST` | `/sources/{id}/receive` | Webhook empfangen |
| `GET` | `/sources/{id}/events` | Empfangene Events auflisten |
| `GET` | `/events/{id}` | Bestimmtes Event abrufen |

## HMAC-SHA256-Signaturvalidierung

Jede Quelle hat ihr eigenes HMAC-Secret. Es darf niemals in Antworten exponiert werden.

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // zeitkonstant
}
```

Reihenfolge der Aufrufe: **Signatur zuerst validieren**, dann Idempotenz-Prüfung, dann speichern:

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... Idempotenz-Prüfung ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## Idempotenz (event_id pro Quelle)

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

Der `UNIQUE(source_id, event_id)`-Constraint ist der DB-Level-Backstop. Die PHP-Prüfung oben vermeidet den Ausnahme-Pfad beim ersten Duplikat.

## Secret niemals exponieren

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // vor der Rückgabe entfernen
return $this->json->create($source, 201);
```

## Inaktive-Quellen-Prüfung

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## MySQL-Hinweise

Der `UNIQUE KEY uq_source_event (source_id, event_id)`-Constraint funktioniert in MySQL gleich. `VARCHAR(191)` für indizierte Textspalten verwenden, um innerhalb von InnoDB's Schlüssellängenlimit zu bleiben.

### MySQL-Integrationstests ausführen

Geteilten FT-MySQL-Container starten (Port 3308, persistentes Volume):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Dann Integrationstests mit Umgebungsvariablen ausführen:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Ohne `MYSQL_HOST` werden die MySQL-Tests automatisch übersprungen (`markTestSkipped`).

## Sicherheitshinweise

- `hash_equals()` verhindert Timing-Angriffe bei der Signaturprüfung.
- Roher JSON-Body wird unverändert gespeichert; nicht vor der Signaturverifizierung parsen.
- Dieselbe `event_id` von zwei verschiedenen Quellen erstellt separate Datensätze — der UNIQUE-Constraint gilt für `(source_id, event_id)`, nicht nur `event_id`.
