# How-to: Einmalige-Geheimnisse-API und ATK-01~12 Cracker-Angriffstest

> **NENE2 Field Trial 184** — Cracker-Angriffstest-Zyklus (ATK-01~12).
> Das Token IST die Anmeldedaten. Atomarer Verbrauch verhindert Race Conditions.

---

## Was dieser Trial beweist

Ein einmaliges Geheimnis speichert eine verschlüsselte Nachricht, die nur einmal gelesen werden kann. Nach dem ersten erfolgreichen Lesen wird das Geheimnis dauerhaft verbraucht.

Sicherheitsanforderungen:
1. **256-Bit-Token-Entropie** — Brute Force ist rechnerisch nicht machbar
2. **Atomarer Verbrauch** — `UPDATE WHERE consumed=0` verhindert Double-Read-Race-Conditions
3. **IDOR-Prävention** — Löschen erfordert sowohl Token als auch Benutzer-Eigentümerschaft
4. **Mass Assignment blockiert** — consumed/token/created_at sind nur serverseitig
5. **Typsicherheit** — V::str() / V::userId() / V::queryInt() lehnen Nicht-String-Eingaben ab

---

## API

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `POST` | `/secrets` | X-User-Id | Ein einmaliges Geheimnis erstellen |
| `GET` | `/secrets` | X-User-Id | Eigene Geheimnisse auflisten (nur Metadaten, keine Nachricht) |
| `GET` | `/secrets/{token}` | — | Lesen + Verbrauchen (Token IST die Anmeldedaten) |
| `DELETE` | `/secrets/{token}` | X-User-Id | Vor dem Lesen abbrechen (muss Eigentümer sein) |

---

## ATK-01~12 Ergebnisse

| ID | Angriffsvektor | Abwehr | Ergebnis |
|----|----------------|--------|---------|
| ATK-01 | SQL-Injection in Token | PDO-parametrisierte Abfragen | ✅ PASS |
| ATK-02 | IDOR-mandantenübergreifendes Löschen | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | Mass Assignment (`consumed=1` im Body) | Nur serverseitige Felder | ✅ PASS |
| ATK-04 | XSS-Payload in Nachricht | JSON-API — kein HTML-Rendering | ✅ PASS |
| ATK-05 | Doppelt-kodiertes / fehlerhaftes Token | `/^[0-9a-f]{64}$/` Format-Prüfung | ✅ PASS |
| ATK-06 | Auth-Bypass beim Lesen | Token IST die Anmeldedaten — per Design | ✅ PASS |
| ATK-07 | Nachricht/Passwort als Nicht-String | `V::str()` erzwingt `is_string()` | ✅ PASS |
| ATK-08 | 20-stelliger Overflow in limit/offset | `V::queryInt()` strlen > 18 Guard | ✅ PASS |
| ATK-09 | ReDoS im limit-Parameter | `ctype_digit()` — O(n), kein Backtracking | ✅ PASS |
| ATK-10 | Brute-Force-Token | `random_bytes(32)` = 2^256 Entropie | ✅ PASS |
| ATK-11 | Race-Condition Double-Read | `UPDATE WHERE consumed=0` + rowCount-Prüfung | ✅ PASS |
| ATK-12 | Header-Injection in X-User-Id | `V::userId()` erzwingt `ctype_digit()` | ✅ PASS |

**12/12: PASS**

---

## Kernmuster: Atomarer Verbrauch

Die kritische Sicherheitsinvariante — ein Geheimnis kann nur einmal gelesen werden:

```php
// SecretRepository::consumeByToken()

// Schritt 1: Geheimnis abrufen (normales SELECT — kein Guard)
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// Schritt 2: consumed-Flag prüfen (frühes Beenden für den häufigen Fall)
if ($secret['consumed']) return null;

// Schritt 3: Atomares UPDATE — das ist der echte Guard
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// Schritt 4: rowCount() === 0 bedeutet, ein anderer Leser hat das Race gewonnen
if ($update->rowCount() === 0) {
    return null; // Jemand anderes hat es zwischen unserem SELECT und diesem UPDATE verbraucht
}

// Schritt 5: Wir haben gewonnen — Geheimnis zurückgeben
return Secret::fromRow($secret);
```

**Warum das funktioniert:** SQLite und die meisten RDBMS garantieren, dass `UPDATE WHERE consumed=0` atomar ist. Nur ein gleichzeitiger Schreiber kann `consumed` von 0→1 ändern. Der Verlierer's `rowCount()` gibt 0 zurück.

---

## Token-Generierung

```php
$token = bin2hex(random_bytes(32)); // 64 Hex-Zeichen = 32 Bytes = 256 Bits
```

- `random_bytes()` verwendet das OS-CSPRNG (äquivalent zu `/dev/urandom`)
- 2^256 Tokens bei 10^12 Versuchen/Sekunde ≈ 10^60 Jahre zum Brute-Force
- Tokens sind in der DB eindeutig (`UNIQUE`-Constraint)

---

## Token-Format-Validierung

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Lehnt ab: Großbuchstaben-Hex, Pfadtraversal ../../, URL-kodiert, Integer, leer
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## IDOR-Prävention (ATK-02)

```php
// DELETE erfordert SOWOHL Token-Eigentümerschaft ALS AUCH user_id-Übereinstimmung
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// Gibt unabhängig vom Grund 404 zurück — vermeidet Enumerations-Oracle
return $stmt->rowCount() > 0;
```

---

## Mass-Assignment-Prävention (ATK-03)

Serverseitige Felder werden **niemals aus dem Request-Body gelesen**:

```php
// POST /secrets Handler — nur message, password, expires_at werden aus dem Body akzeptiert
$token        = bin2hex(random_bytes(32));  // server-generiert
$consumed     = 0;                          // beginnt immer unverbraucht
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // Server-Zeit
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // serverseitig gehasht

// body['consumed'], body['token'], body['user_id'], body['created_at'] werden stillschweigend ignoriert
```

---

## V.php-Validierungskette

```php
// ATK-07: message muss ein String sein (lehnt int, bool, null, array ab)
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: X-User-Id muss ctype_digit + positiv + max 18 Zeichen sein
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: limit muss numerisch sein, max 18 Ziffern, im Bereich 1–100
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## Optionaler Passwortschutz

```php
// Speicherung: nur SHA-256-Hash (kein Klartext)
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// Verifizierung: Constant-Time-Vergleich (timing-sicher)
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // Falsches Passwort → stilles 404 (kein Oracle)
}
```

> **Hinweis:** Falsches Passwort gibt 404 (nicht 403) zurück, um Oracle-Angriffe zu verhindern.
> Das Geheimnis wird bei falschem Passwort NICHT verbraucht — nur das korrekte Passwort verbraucht es.

---

## Metadaten-Liste (keine Nachrichtenpreisgabe)

```php
// GET /secrets — gibt nur Metadaten zurück, niemals die Nachricht
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' wird absichtlich weggelassen
    ];
}
```

---

## Testergebnisse

```
85 Tests / 209 Assertions — alle PASS
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

---

## Wichtigste Erkenntnisse

| Muster | Regel |
|--------|-------|
| Atomarer Verbrauch | `UPDATE WHERE consumed=0` + `rowCount()`-Prüfung — nicht SELECT dann UPDATE |
| Token-Entropie | `random_bytes(32)` Minimum (256 Bits) — niemals sequentielle IDs |
| Token-Format | Allowlist-Regex an beiden Enden verankert (`/^[0-9a-f]{64}$/`) |
| IDOR | Alle Schreiboperationen nach `token AND user_id` eingrenzen |
| Mass Assignment | Token, consumed, created_at — nur serverseitig, niemals aus Body |
| Passwort-Timing | `hash_equals()` für Constant-Time-Vergleich |
| Falsches Passwort | 404 nicht 403 — vermeidet Bestätigung der Geheimnis-Existenz |
| Metadaten-Liste | Nachricht aus Listen-Endpunkt weglassen — nur beim Verbrauchen lesen |
