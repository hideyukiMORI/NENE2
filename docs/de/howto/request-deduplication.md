# Anfrage-Deduplizierung hinzufügen

Verhindert doppelte Verarbeitung durch Netzwerk-Wiederholungen oder Doppelklicks mithilfe eines `Idempotency-Key`-Headers. Der Server cached Antworten pro Key und gibt sie bei nachfolgenden identischen Anfragen wieder ab.

## Schema

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/payments` | Zahlung verarbeiten (Idempotency-Key erforderlich) |
| `POST` | `/orders` | Bestellung erstellen (Idempotency-Key erforderlich) |

## Handler-Muster

Jeder mutierende Endpunkt, der idempotent sein soll, folgt demselben Dreischritt-Muster:

```php
// 1. Den Idempotency-Key-Header erfordern
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. Gecachte Antwort zurückgeben, wenn der Key bereits verwendet wurde
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. Verarbeiten und cachen
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

Das `replayed: true`-Feld signalisiert Clients, dass die Antwort aus dem Cache kam.

## Strikte Betragsvalidierung

Nicht-Integer-Eingaben an der Grenze ablehnen — PHPs `(int)`-Cast kürzt Strings wie `"100; DROP TABLE …"` stillschweigend auf `100`. Eine explizite Typprüfung verwenden:

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL und Ablauf

Keys laufen nach 24 Stunden (86400 Sekunden) ab. Abgelaufene Einträge werden als frisch behandelt — derselbe Key kann nach dem Ablauf erneut verwendet werden:

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## Sicherheitseigenschaften

- **SQL-Injection über Key-Header**: Parametrisierte Abfragen speichern schädliche Keys als Literale.
- **Replay-Flood**: 10 identische Anfragen erstellen genau 1 Datensatz in der Geschäftstabelle.
- **Nur-Leerzeichen-Key**: `trim()` vor der Leer-Prüfung verhindert `"   "` als gültigen Key.
- **Typ-Injection in numerischen Feldern**: `ctype_digit()`-Prüfung lehnt partielle Integer-Strings ab.
- **Keine internen Lecks**: 400/422-Antworten enthalten nur die `error`- oder `errors`-Felder — keine Pfade, Stack-Traces oder Engine-Details.
