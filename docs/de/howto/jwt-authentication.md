# How-to: JWT-Authentifizierung

> **FT-Referenz**: FT261 (`NENE2-FT/jwtlog`) — JWT-Authentifizierung mit Argon2id-Passwort-Hashing und BearerTokenMiddleware
> **VULN**: FT261 — Schwachstellenanalyse (V-01 bis V-10)

JWT-Bearer-Token mit `LocalBearerTokenVerifier` und `BearerTokenMiddleware` ausstellen und verifizieren.

---

## Schnellstart

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// Alle Pfade außer /auth/login schützen
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## Token ausstellen

`LocalBearerTokenVerifier` implementiert sowohl `TokenIssuerInterface` als auch `TokenVerifierInterface` — eine Instanz behandelt beides.

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // subject: Benutzeridentifier (int oder string)
    'email' => $user->email,    // benutzerdefinierter Claim
    'iat'   => $now,            // issued-at (Unix-Zeitstempel — int)
    'exp'   => $now + 3600,     // Ablauf   (Unix-Zeitstempel — int, erforderlich für Ablaufumsetzung)
]);
```

**`exp` muss ein Unix-Zeitstempel (int) sein.** Die Übergabe eines Datum-Strings (`'2026-06-01'`) überspringt stillschweigend die Ablaufumsetzung, da `LocalBearerTokenVerifier` `is_int($claims['exp'])` vor dem Vergleich prüft.

---

## Claims in einem Handler lesen

`BearerTokenMiddleware` speichert dekodierte Claims im Request-Attribut `nene2.auth.claims` nach erfolgreicher Verifizierung:

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // Diese null-Prüfung sollte nicht auslösen — die Middleware hat fehlende Tokens bereits abgelehnt.
    // Trotzdem einschließen für PHPStan Level 8 und defensive Klarheit.
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

Ebenfalls verfügbar: `$request->getAttribute('nene2.auth.credential_type')` gibt `'bearer'` zurück.

---

## Pfadschutzmodi

`BearerTokenMiddleware` unterstützt drei Modi — die erste nicht-leere Konfiguration gewinnt:

| Konfiguration | Verhalten | Wann verwenden |
|---|---|---|
| `protectedPaths: ['/me', '/admin']` | Nur aufgelistete exakte Pfade sind geschützt | Öffentliche Pfade sind die Mehrheit |
| `protectedPathPrefixes: ['/api/']` | Pfade, die mit Präfix beginnen, sind geschützt | Einen ganzen Teilbaum schützen |
| `excludedPaths: ['/login', '/register']` | Alle Pfade außer aufgelisteten sind geschützt | Öffentliche Pfade sind die Minderheit |
| (Standard — alle Arrays leer) | Jeder Pfad ist geschützt | Vollständig private API |

```php
// ✅ /auth/login ist öffentlich, alles andere erfordert ein Token
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ Nur /auth/me ist geschützt
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ Alle /api/-Pfade sind geschützt
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] ist NICHT "nichts schützen" — es deaktiviert den Allowlist-Modus
//     und fällt auf den nächsten Modus zurück (Präfixe, dann Blockliste, dann alles schützen).
```

---

## `alg: none`-Angriff — bereits abgelehnt

`LocalBearerTokenVerifier` prüft, dass `alg == 'HS256'` im Token-Header steht, bevor die Signatur verifiziert wird. Jeder andere Algorithmus — einschließlich `none` — wirft `TokenVerificationException`:

```
Token algorithm must be HS256.
```

Das verhindert den klassischen `alg: none`-Bypass, bei dem ein Angreifer ein Header-loses Token ohne Signatur erstellt. Bei der Implementierung eines benutzerdefinierten Verifiers immer den erwarteten Algorithmus explizit erzwingen.

---

## Fehlerantworten

`BearerTokenMiddleware` gibt 401 Problem Details zurück und fügt automatisch den `WWW-Authenticate`-Header hinzu (RFC 6750):

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

Mögliche `error`-Werte: `missing_token` (kein Header), `invalid_token` (ungültiges Schema, ungültige Signatur, abgelaufen, `nbf` in der Zukunft, fehlerhaft).

---

## Secret-Verwaltung

Das JWT-Secret niemals hartcodieren. Es aus einer Umgebungsvariable lesen:

```php
// ❌ Hartcodiertes Secret — in Versionsverwaltung committet
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ Umgebungsvariable
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

Ein starkes zufälliges Secret in allen Umgebungen verwenden. Für die Produktion eine bibliotheksbasierte Implementierung (`firebase/php-jwt`, `lcobucci/jwt`) statt `LocalBearerTokenVerifier` verwenden — das Präfix "Local" signalisiert seinen Anwendungsbereich.

---

## Token-Widerruf

JWT ist zustandslos — es gibt keinen eingebauten Widerruf. Token bleiben bis `exp` gültig. Wenn sofortiger Widerruf benötigt wird (z.B. Logout, Passwortänderung):

- Eine Token-Blockliste in Redis mit TTL entsprechend `exp` speichern
- Oder kurzlebige Token (15 Minuten) mit Refresh-Token verwenden

---

## `authMiddleware`-Parametername

Der benannte Parameter von `RuntimeApplicationFactory` ist `authMiddleware:`, nicht `middlewares:` oder `middleware:`:

```php
// ❌ Unbekannter benannter Parameter $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ Korrekt
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## Code-Review-Checkliste

- [ ] `exp`-Claim ist ein Unix-Zeitstempel (int), kein Datum-String
- [ ] JWT-Secret wird aus einer Umgebungsvariable gelesen (nicht hartcodiert)
- [ ] `LocalBearerTokenVerifier` wird nicht in der Produktion verwendet (Bibliotheksimplementierung verwenden)
- [ ] `nene2.auth.claims`-Attribut wird vor Verwendung auf null geprüft
- [ ] Wahl des Modus `excludedPaths` / `protectedPaths` entspricht der Absicht
- [ ] Token-Antwort enthält kein `password_hash` oder andere Secrets
- [ ] `Authorization`-Header wird nicht protokolliert
- [ ] 401 wird bei Auth-Fehlern zurückgegeben (nicht 404)

---

## Timing-Angriffs-Schutz: Dummy-Hash für Benutzer-Enumeration

Wenn eine E-Mail nicht gefunden wird, ist `$user === null`. Ohne einen Dummy-Hash würde der Code `password_verify()` vollständig überspringen — was die Antwort für unbekannte E-Mails merklich schneller macht.

```php
$user = $this->repo->findByEmail(trim($body['email']));

// Immer password_verify ausführen — verhindert zeitbasierte Benutzer-Enumeration.
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  Reihenfolge wichtig: password_verify() VOR || $user === null
// Kurzschlussauswertung würde password_verify() überspringen, wenn $user zuerst geprüft würde.
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // gleicher Fehler, unabhängig davon, ob E-Mail unbekannt oder Passwort falsch ist
}
```

---

## VULN — Schwachstellenanalyse (FT261)

### V-01 — Kein Brute-Force-Schutz bei Login

**Risiko**: `POST /auth/login` hat keine Rate-Begrenzung.

**Auswirkung**: Ein Angreifer kann unbegrenzte Anmeldeversuche einreichen. Argon2id ist absichtlich langsam (~100ms), aber ohne Rate-Begrenzung können verteilte Anfragen trotzdem Tausende von Passwörtern ausprobieren.

**Urteil**: **EXPOSED** — `ThrottleMiddleware` auf `POST /auth/login` hinzufügen (z.B. 5 Req/Min/IP). 429 mit `Retry-After` zurückgeben.

---

### V-02 — JWT-Secret-Stärke ist umgebungsabhängig

**Risiko**: Wenn `NENE2_LOCAL_JWT_SECRET` leer oder schwach ist (`secret`, `test`), können HMAC-HS256-Token per Brute-Force geknackt oder erraten werden. Ein gefälschtes Token mit Admin-Claims würde akzeptiert.

**Urteil**: **EXPOSED** — fail-closed Startup-Prüfung:
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — Kein Token-Widerruf

**Risiko**: Ausgestellte JWTs bleiben bis `exp` gültig. Gestohlene Token oder Token von gelöschten Benutzern werden bis zu 1 Stunde lang akzeptiert.

**Urteil**: **EXPOSED** — eine Token-Blockliste implementieren (z.B. `revoked_tokens(jti TEXT PK, revoked_at TEXT)`) oder kurzlebige Token (15 Min) mit Refresh-Token verwenden.

---

### V-04 — Kein Benutzerregistrierungs-Endpunkt

**Risiko**: Keine `POST /auth/register`-Route vorhanden. Testbenutzer erfordern direkte DB-Einfügung, was die Passwort-Hashing-Richtlinie der Anwendung umgeht.

**Urteil**: **DESIGN-LÜCKE** — `POST /auth/register` mit E-Mail-Validierung und Argon2id-Hashing hinzufügen.

---

### V-05 — E-Mail-Groß-/Kleinschreibung: keine Normalisierung

**Risiko**: `WHERE email = ?` ist case-sensitiv. `USER@EXAMPLE.COM` und `user@example.com` sind verschiedene Suchvorgänge. Zwei Konten mit unterschiedlicher Groß-/Kleinschreibung können koexistieren.

**Urteil**: **EXPOSED** — E-Mail bei Registrierung und Login in Kleinbuchstaben normalisieren (`strtolower()`).

---

### V-06 — Token-TTL: 1 Stunde ist möglicherweise zu lang für sensible APIs

**Risiko**: `TOKEN_TTL_SECONDS = 3600`. Gestohlene Token bleiben bis zu einer Stunde gültig.

**Urteil**: **DESIGN-ERWÄGUNG** — 1 Stunde ist für die meisten APIs akzeptabel. Für sensible Operationen kürzere TTLs (5–15 Min) mit Refresh-Token verwenden. TTL konfigurierbar machen.

---

### V-07 — `password_hash` ist nicht in JWT-Claims

**Risiko**: Der `issue()`-Aufruf enthält nur `sub`, `email`, `iat`, `exp`.

**Urteil**: **SAFE** — Claims sind minimal. Selbst wenn ein Token dekodiert wird (base64, nicht verschlüsselt), werden keine sensiblen internen Daten exponiert.

---

### V-08 — SQL-Injection über E-Mail

**Angriff**: `{"email": "' OR '1'='1", "password": "x"}`

**Beobachtet**: `WHERE email = ?` ist eine parametrisierte Abfrage. Die Injection wird als Literalstring behandelt. Kein Benutzer wird gefunden; 401 wird zurückgegeben.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern SQL-Injection.

---

### V-09 — Keine E-Mail-Format-Validierung

**Risiko**: Beliebige nicht-leere Strings werden als E-Mail akzeptiert (z.B. `"not-an-email"`).

**Auswirkung**: Verschwendete Argon2id-Berechnung; ungültige Benutzer in der DB; unterbrochene Passwort-Reset-Flows.

**Urteil**: **EXPOSED** — `filter_var($email, FILTER_VALIDATE_EMAIL)` bei Registrierung und Login hinzufügen.

---

### V-10 — Keine HTTPS-Erzwingung

**Risiko**: JWT-Token und Passwörter werden im Klartext über HTTP übertragen.

**Urteil**: **EXPOSED** — HTTPS in der Produktion erzwingen. `Strict-Transport-Security`-Header über `SecurityHeadersMiddleware` hinzufügen.

---

## VULN-Zusammenfassung

| # | Schwachstelle | Urteil |
|---|---------------|--------|
| V-01 | Kein Brute-Force-Schutz | EXPOSED |
| V-02 | JWT-Secret-Stärke (umgebungsabhängig) | EXPOSED |
| V-03 | Kein Token-Widerruf | EXPOSED |
| V-04 | Kein Registrierungs-Endpunkt | DESIGN-LÜCKE |
| V-05 | E-Mail-Groß-/Kleinschreibung / keine Normalisierung | EXPOSED |
| V-06 | Token-TTL 1 Stunde | DESIGN-ERWÄGUNG |
| V-07 | password_hash nicht in JWT-Claims | SAFE |
| V-08 | SQL-Injection über E-Mail | BLOCKED |
| V-09 | Keine E-Mail-Format-Validierung | EXPOSED |
| V-10 | Keine HTTPS-Erzwingung | EXPOSED |

**Kritische Korrekturen vor Produktionseinsatz**:
1. **V-01** — `ThrottleMiddleware` auf `POST /auth/login` (5 Req/Min/IP)
2. **V-02** — Fail-closed JWT-Secret-Validierung beim Start (`strlen >= 32`)
3. **V-03** — Token-Widerrufsliste oder kurze TTL + Refresh-Token
4. **V-05** — E-Mail bei Registrierung und Login in Kleinbuchstaben normalisieren
5. **V-09** — `filter_var($email, FILTER_VALIDATE_EMAIL)` bei Registrierung

---

## Verwandte Anleitungen

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — Brute-Force-Sperrung für PIN-Verifizierung
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — Rate-Limiting-Middleware
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + zeitkonstanter Vergleich
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explizites DTO-Whitelisting
