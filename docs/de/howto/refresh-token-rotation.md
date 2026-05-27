# How-to: JWT Refresh-Token-Rotation

Dieses Handbuch behandelt die Implementierung von kurzlebigen Access-Tokens kombiniert mit langlebigen Refresh-Tokens. Die Schlüsseleigenschaft ist **Rotation**: jede Verwendung eines Refresh-Tokens widerruft es sofort und stellt ein neues aus. Ein wiederverwendetes (bereits widerrufenes) Refresh-Token löst den Widerruf aller Tokens für diesen Benutzer aus.

---

## Warum zwei Tokens?

| Token | TTL | Speicherung | Zweck |
|---|---|---|---|
| Access-Token | 5 Min | Client-Speicher | Authentifiziert API-Anfragen (zustandslos, kein DB-Nachschlag) |
| Refresh-Token | 7 Tage | DB (gehasht) | Stellt neue Access-Tokens aus; verwaltet via Rotation |

Ein kurzlebiger Access-Token begrenzt den Schaden, wenn er leckt — er läuft in Minuten ab. Der Refresh-Token verlängert die Sitzung ohne erneuten Login, ist aber widerrufbar, weil er in der Datenbank lebt.

---

## Schema

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- SHA-256-Hash; niemals der rohe Wert
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` — immer den Hash speichern, niemals den rohen Token. Wenn die DB leckt, können gehashte Tokens nicht direkt verwendet werden.

---

## Tokens ausstellen

### Access-Token: `jti` für Eindeutigkeit hinzufügen

Ohne `jti` sind zwei Tokens, die in derselben Sekunde für denselben Benutzer ausgestellt wurden, identisch — ihre Payloads sind byte-für-byte gleich. `jti` (JWT ID) garantiert, dass jedes Token einzigartig ist:

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // einzigartig pro Ausstellung
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 Minuten
]);
```

### Refresh-Token: Hash speichern, rohen Wert zurückgeben

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 256-Bit-Zufallstoken
    $hash      = hash('sha256', $raw);       // nur dies speichern
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // der Client erhält dies; die DB speichert es nie
}
```

---

## Token-Rotation

Jede Aktualisierungsanfrage muss das alte Token widerrufen, bevor ein neues ausgestellt wird:

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... Body parsen, gespeichertes Token finden ...

    if ($stored === null || !$stored->isValid()) {
        // Wiederverwendung eines widerrufenen Tokens ist ein potenzieller Replay-Angriff —
        // alle Tokens für den Benutzer widerrufen, um erneute Authentifizierung zu erzwingen.
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // Rotation: altes Token zuerst widerrufen, dann neues Paar ausstellen
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**Wiederverwendungserkennung**: Wenn ein widerrufenes Refresh-Token am `/auth/refresh`-Endpunkt ankommt, bedeutet das entweder, dass der Benutzer ein altes Token wiederverwendet (ungewöhnlich) oder ein Angreifer es gestohlen hat. `revokeAllForUser()` zwingt jede Sitzung zur erneuten Authentifizierung.

---

## Logout: immer 204 zurückgeben

Niemals unterschiedliche Statuscodes zurückgeben, je nachdem ob das Refresh-Token gültig war. Das ermöglicht einem Angreifer zu sondieren, ob ein Token noch aktiv ist:

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... Body parsen ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Immer 204 — niemals lecken ob das Token gültig war oder nicht
    return $this->json->createEmpty(204);
}
```

Das bedeutet auch, dass doppelter Logout (Logout zweimal mit demselben Token aufrufen) beide Male 204 zurückgibt — der Client kann immer sicher Logout aufrufen, ohne sich um den Token-Status zu sorgen.

---

## Gültigkeitsprüfung auf der RefreshToken-Entität

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

Zeichenkettenvergleich funktioniert für lexikographisch sortierte ISO-8601-Daten.

---

## BearerTokenMiddleware: Refresh/Logout-Pfade ausschließen

Die Refresh- und Logout-Endpunkte empfangen einen Refresh-Token im Body, kein Bearer Access-Token im Authorization-Header. Aus `BearerTokenMiddleware` ausschließen:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

---

## Antwort-Form

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in` (Sekunden) ermöglicht dem Client, eine proaktive Aktualisierung zu planen, bevor der Access-Token abläuft.

---

## Code-Review-Checkliste

1. `token_hash`-Spalte speichert `hash('sha256', $raw)` — niemals den rohen Wert
2. `revoke()` wird vor `issueTokenPair()` im Refresh-Handler aufgerufen
3. Widerrufene-Token-Wiederverwendung löst `revokeAllForUser()` aus (nicht nur ein 401)
4. Logout gibt immer 204 zurück — kein bedingtes 401/404
5. Access-Token-TTL ist kurz (≤ 15 Minuten)
6. `jti`-Anspruch ist in Access-Tokens vorhanden
7. Tests decken Cross-Token-Rotation (altes Token ungültig nach Refresh) und Wiederverwendungserkennung ab

---

## Rotation und Wiederverwendungserkennung testen

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // Altes Token muss abgelehnt werden
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // Einmal rotieren — altes Token ist jetzt widerrufen
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // Angreifer replayed altes (widerrufenes) Refresh-Token — löst revokeAllForUser() aus
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // Das neu ausgestellte Refresh-Token ist jetzt auch widerrufen
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## Siehe auch

- `docs/howto/jwt-authentication.md` — JWT-Ausstellung, BearerTokenMiddleware, `nene2.auth.claims`
- `docs/howto/password-hashing.md` — Argon2id, Dummy-Hash-Muster für Benutzer-Enumerationsschutz
