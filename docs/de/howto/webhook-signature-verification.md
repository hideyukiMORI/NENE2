# How-to: Webhook-Signaturverifikation mit HMAC-SHA256

> **FT-Referenz**: FT260 (`NENE2-FT/hmaclog`) — Webhook-Signaturverifikation: HMAC-SHA256, timing-sicherer Vergleich, Replay-Angriff-Prävention
> **ATK**: FT260 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert, wie eingehende Webhook-Anfragen mit einer Stripe-ähnlichen HMAC-SHA256-Signatur verifiziert werden.
Der Signatur-Header bindet einen Timestamp an den Request-Body und verhindert sowohl Fälschung als auch Replay-Angriffe.
`hash_equals()` wird für den Konstantzeit-Vergleich verwendet, um Timing-Angriffe zu verhindern.

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/webhook` | Signierten Webhook empfangen und verifizieren |
| `GET` | `/webhook/events` | Empfangene Webhook-Events auflisten |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    payload      TEXT NOT NULL,
    delivered_at TEXT NOT NULL
);
```

Events werden nur nach bestandener Signaturverifikation gespeichert. Ein abgelehnter Webhook wird nie persistiert.

---

## Signaturformat (Stripe-Style)

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**Signiertes Payload**: `"<timestamp>.<raw-body>"`

Der Timestamp ist in der HMAC-Berechnung enthalten. Das bedeutet:
- Eine gültige Signatur gilt nur für den Body, über den sie berechnet wurde (Body-Manipulation bricht die Signatur).
- Eine gültige Signatur gilt nur zum Zeitpunkt ihrer Generierung (das Wiederholen einer alten, gültigen Signatur schlägt die Timestamp-Prüfung fehl, auch wenn der HMAC korrekt ist).

---

## Verifier

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // KRITISCH: hash_equals ist Konstantzeit; === ist es NICHT
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }
        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }
}
```

---

## Controller: Raw-Body-Extraktion

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // muss rohe Bytes sein, nicht geparst

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // nur nach Verifikation parsen
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**Kritische Reihenfolge**:
1. Raw-Body als String lesen — der HMAC wurde über die exakten Bytes berechnet.
2. Signatur gegen den Raw-Body verifizieren.
3. JSON erst nach erfolgreicher Verifikation parsen.

Wenn JSON zuerst geparst und dann neu serialisiert wird, kann sich der Byte-Inhalt unterscheiden (Schlüsselsortierung, Whitespace), was die HMAC-Prüfung bricht.

---

## ATK — Cracker-Mindset-Angriffstest (FT260)

### ATK-01 — Fehlender Signatur-Header

**Attack**: Webhook ohne `X-Webhook-Signature`-Header senden.

```bash
POST /webhook
{"event_type": "user.created"}
```

**Observed**: `verify()` prüft `$header === ''` vor jeder Berechnung. Gibt 401 Problem Details zurück:
`"Missing X-Webhook-Signature header."` Kein Event wird gespeichert.

**Verdict**: **BLOCKED** — fehlender Header wird vor der Signaturberechnung abgefangen.

---

### ATK-02 — Manipulierte Signatur (Ein-Zeichen-Änderung)

**Attack**: Eine gültige Signatur nehmen und ein Hex-Zeichen ändern.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-but-one-char-wrong>
```

**Observed**: `hash_equals($expectedSig, $receivedSig)` gibt `false` zurück. 401 wird zurückgegeben.
Der Vergleich ist Konstantzeit — die Antwortzeit variiert nicht damit, wie viele Zeichen übereinstimmen.

**Verdict**: **BLOCKED** — `hash_equals()` verhindert Timing-Oracle während manipulierte Sigs abgelehnt werden.

---

### ATK-03 — Falsches Secret verwendet

**Attack**: Anfrage mit einem anderen HMAC-Secret signieren.

```
X-Webhook-Signature: t=<now>,v1=<hmac-with-wrong-secret>
```

**Observed**: `computeSignature()` verwendet das Server-Secret. Der HMAC des Angreifers (mit einem anderen Secret berechnet) produziert einen anderen Hex-String. `hash_equals()` schlägt fehl. 401 zurückgegeben.

**Verdict**: **BLOCKED** — ohne das Secret kann keine gültige Signatur gefälscht werden.

---

### ATK-04 — Replay-Angriff: gültige alte Signatur

**Attack**: Einen legitimen `X-Webhook-Signature`-Header erfassen und 10 Minuten später wiederholen.

```
X-Webhook-Signature: t=<timestamp-from-10-minutes-ago>,v1=<valid-hmac>
```

**Observed**: `checkTimestamp($timestamp)` berechnet `abs(time() - $timestamp)`.
10 Minuten = 600 Sekunden > 300-Sekunden-Toleranz. `SignatureException` wird geworfen. 401 zurückgegeben.

**Verdict**: **BLOCKED** — Replay-Angriffe werden durch die 300-Sekunden-Timestamp-Toleranz verhindert.

---

### ATK-05 — Zukünftiger Timestamp: Replay-Schutz-Umgehungsversuch

**Attack**: Anfrage mit einem weit zukünftigen Timestamp vorsignieren, um das Gültigkeitsfenster zu erweitern.

```
X-Webhook-Signature: t=<now + 3600>,v1=<hmac-with-future-ts>
```

**Observed**: `abs(time() - $timestamp)` = 3600 > 300. `SignatureException` geworfen. 401 zurückgegeben.
`abs()` bedeutet, dass zukünftige Timestamps ebenfalls abgelehnt werden — die Prüfung ist symmetrisch.

**Verdict**: **BLOCKED** — `abs()` stellt sicher, dass sowohl vergangene als auch zukünftige Timestamps außerhalb des Toleranzfensters abgelehnt werden.

---

### ATK-06 — Body-Manipulation mit gültiger Signatur

**Attack**: Gültigen Webhook abfangen. `X-Webhook-Signature`-Header behalten, aber JSON-Body ändern.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-over-original-body>
Body: {"event_type": "user.deleted"}   ← geändert von "user.created"
```

**Observed**: Der HMAC wurde über `"<timestamp>.<original-body>"` berechnet. Der modifizierte Body
produziert einen anderen HMAC. `hash_equals()` schlägt fehl. 401 zurückgegeben.

**Verdict**: **BLOCKED** — die Signatur bindet den Timestamp an den Body. Beides ändern macht die Signatur ungültig.

---

### ATK-07 — Malformierter Header: fehlender Timestamp

**Attack**: Signatur-Header ohne `t=`-Komponente einreichen.

```
X-Webhook-Signature: v1=<some-hmac>
```

**Observed**: `parseHeader()` prüft `isset($parts['t'], $parts['v1'])`. Fehlendes `t` wirft
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 zurückgegeben.

**Verdict**: **BLOCKED** — Header-Parser setzt Pflichtfelder durch.

---

### ATK-08 — Leeres Secret auf dem Server

**Attack scenario**: Der Server ist mit einem leeren HMAC-Secret (`''`) falsch konfiguriert.

**Observed**: Ein leeres Secret ist in PHPs `hash_hmac()` gültig — es produziert einen deterministischen Hex-String. Ein Angreifer, der das leere Secret entdeckt, kann gültige Signaturen fälschen:
`hash_hmac('sha256', "{$timestamp}.{$body}", '')`.

**Verdict**: **EXPOSED (Fehlkonfiguration)** — der Verifier lehnt kein leeres Secret ab.
Die Anwendungs-Konfigurationsschicht muss beim Start validieren, dass `WEBHOOK_SECRET` nicht leer ist.
Fail-Closed-Standard: wenn das Secret leer ist, alle Webhooks ablehnen.

```php
// Empfohlener Start-Guard
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — HMAC-Bypass: `v1=` mit leerem Wert einreichen

**Attack**: Signatur auf leeren String setzen: `X-Webhook-Signature: t=<now>,v1=`.

**Observed**: `parseHeader()` prüft `$parts['v1'] === ''`. Ein leeres `v1` wirft
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 zurückgegeben.

**Verdict**: **BLOCKED** — leere Signatur wird im Parser abgelehnt, bevor `hash_equals()` aufgerufen wird.

---

### ATK-10 — Timestamp-Injection: Nicht-Ziffern-Timestamp

**Attack**: Timestamp einreichen, der kein reiner Integer ist: `t=1234abc`.

```
X-Webhook-Signature: t=1234abc,v1=<some-hmac>
```

**Observed**: `parseHeader()` prüft `ctype_digit($parts['t'])`. Nicht-Ziffern-Zeichen verursachen
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 zurückgegeben.

**Verdict**: **BLOCKED** — `ctype_digit()` setzt durch, dass der Timestamp ein reiner Integer-String ist.

---

### ATK-11 — Header-Injection: Komma im HMAC-Hex

**Attack**: Komma in den `v1`-Wert injizieren, um den Parser zu verwirren.

```
X-Webhook-Signature: t=<now>,v1=abc,def
```

**Observed**: `parseHeader()` verwendet `explode('=', $chunk, 2)` mit Limit 2. Der Header wird
zuerst auf `,` geteilt (produziert `['t=<now>', 'v1=abc', 'def']`), dann wird jeder Chunk auf
`=` mit Limit 2 geteilt. Der `def`-Chunk wird `['def', '']` und überschreibt nichts Kritisches.
Der `v1`-Wert ist `abc`, kein gültiger HMAC-Hex. `hash_equals()` schlägt fehl. 401 zurückgegeben.

**Verdict**: **BLOCKED** — Parser-Robustheit + HMAC-Längen-Prüfung verhindert Injektionsmanipulation.

---

### ATK-12 — Großer Body: Payload-Größenangriff

**Attack**: Webhook mit mehrmegabyte-großem Body senden.

**Observed**: Der Verifier berechnet `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)`.
`hash_hmac()` behandelt beliebig große Eingaben; die Ausgabe ist immer 64 Hex-Zeichen.
Kein explizites Größenlimit wird auf Verifier-Ebene angewendet. Ein 100 MB Body würde akzeptiert wenn
die Signatur gültig und der Timestamp frisch ist.

**Verdict**: **EXPOSED** — kein Request-Größenlimit am Webhook-Endpunkt. Request-Größen-Middleware
(z. B. 1-MB-Limit) upstream hinzufügen, um Ressourcenerschöpfung zu verhindern. Der Verifier sollte
nicht für Größenlimits zuständig sein — das ist ein Anliegen für eine äußere Middleware-Schicht.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Ergebnis |
|---|----------------|----------|
| ATK-01 | Fehlender Signatur-Header | BLOCKED |
| ATK-02 | Manipulierte Signatur (1 Zeichen) | BLOCKED |
| ATK-03 | Falsches Secret verwendet | BLOCKED |
| ATK-04 | Replay-Angriff (alter Timestamp) | BLOCKED |
| ATK-05 | Zukünftiger Timestamp-Bypass | BLOCKED |
| ATK-06 | Body-Manipulation | BLOCKED |
| ATK-07 | Malformierter Header (kein Timestamp) | BLOCKED |
| ATK-08 | Leeres Server-Secret (Fehlkonfiguration) | EXPOSED |
| ATK-09 | Leerer `v1=`-Wert | BLOCKED |
| ATK-10 | Nicht-Ziffer-Timestamp | BLOCKED |
| ATK-11 | Header-Injection via Komma | BLOCKED |
| ATK-12 | Großer Body / Ressourcenerschöpfung | EXPOSED |

**Echte Schwachstellen vor der Produktion zu beheben**:
1. **ATK-08** — Fail-Closed Leer-Secret-Guard beim Start (`if ($secret === '') throw`)
2. **ATK-12** — Request-Größen-Middleware (z. B. 1-MB-Limit) upstream der Webhook-Route

---

## Designhinweise

### Warum HMAC-SHA256 statt einfachem Bearer Token?

Ein Bearer Token beweist nur, dass der Sender das Token kennt. HMAC-SHA256 beweist, dass der Sender
das Secret kennt UND dass der Body nicht verändert wurde — Body-Integrität ist eingebaut.

### Warum den Timestamp an das HMAC-Payload binden?

Wenn die Signatur nur `HMAC(body)` wäre, könnte ein Angreifer, der eine gültige Anfrage erfasst, sie
unbegrenzt wiederholen. Durch das Signieren von `"<timestamp>.<body>"` ist jede Signatur nur innerhalb
des 300-Sekunden-Fensters und für den exakten Body, über den sie berechnet wurde, gültig.

### Warum `hash_equals()` statt `===`?

PHPs `===` ist ein Short-Circuit-Vergleich: er stoppt sobald zwei Zeichen sich unterscheiden. Ein Angreifer
kann die Zeit messen, die für den Vergleich zweier Strings benötigt wird, und ableiten, wie viele führende
Zeichen seiner Schätzung übereinstimmen — eins nach dem anderen — und das Secret byteweise brute-forcen.
`hash_equals()` läuft in Konstantzeit unabhängig davon, wo die Strings divergieren.

---

## Verwandte Anleitungen

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — `hash_equals()` und HMAC-SHA256 für PIN-Speicherung + Sperrung
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — Cracker-Mindset-ATK-Assessment-Muster
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — Rate Limiting als Ergänzung zur Signaturverifikation
