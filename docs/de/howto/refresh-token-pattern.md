# How-to: Refresh-Token-Muster

> **FT-Referenz**: FT281 (`NENE2-FT/refreshlog`) — Refresh-Token-Muster: kurzlebiger Access-Token (5 Min JWT) + langlebiger Refresh-Token (7 Tage), SHA-256-Hash-Speicherung, Token-Rotation bei Verwendung, Replay-Angriff-Erkennung (widerrufenes Token → alle widerrufen), Logout gibt immer 204 zurück, 15 Tests / 63 Assertions bestanden.

Dieses Handbuch zeigt, wie man das Refresh-Token-Muster implementiert — kurzlebige Access-Tokens für Sicherheit, Refresh-Tokens für Sitzungskontinuität.

## Warum es wichtig ist

JWTs sind zustandslos. Einmal ausgestellt können sie nicht widerrufen werden, bis sie ablaufen. Ein 5-Minuten-TTL begrenzt die Exposition, wenn ein Token gestohlen wird. Refresh-Tokens verlängern Sitzungen ohne wiederholte Passwort-Eingabeaufforderungen, und können bei jeder Verwendung rotiert (widerrufen und neu ausgestellt) werden, um Diebstahl zu erkennen.

## Schema

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` speichert SHA-256 des rohen Tokens — niemals den rohen Wert. `revoked` ist ein Soft-Delete-Flag (vs. hartes Löschen für Replay-Erkennung).

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/auth/login` | Keine | E-Mail + Passwort → Access-Token + Refresh-Token |
| `POST` | `/auth/refresh` | Refresh-Token im Body | Refresh-Token rotieren, neues Paar ausstellen |
| `POST` | `/auth/logout` | Refresh-Token im Body | Refresh-Token widerrufen |
| `GET` | `/auth/me` | Bearer Access-Token | Aktuelle Benutzerinfo abrufen |

## Token-Lebensdauer

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 Minuten — kurz für Sicherheit
// Refresh-Tokens: 7 Tage (RefreshTokenRepository::TTL_DAYS)
```

## Token-Paar ausstellen

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // eindeutige Token-ID
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## Nur den Hash speichern

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 Hex-Zeichen = 256 Bit Entropie
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← roh an Client zurückgeben; niemals gespeichert
}
```

Wenn die DB verletzt wird, bekommen Angreifer Hashes — nutzlos ohne die rohen Tokens, die Clients halten.

## Token-Rotation

```php
// Bei erfolgreicher /auth/refresh:
$this->refreshTokens->revoke($stored->id);      // alt widerrufen
return $this->json->create($this->issueTokenPair($user));  // neues Paar ausstellen
```

Jede Aktualisierung rotiert den Token. Das alte Token wird sofort ungültig, sodass ein gestohlenes Refresh-Token nur einmal verwendet werden kann, bevor die Rotation es ungültig macht.

## Replay-Angriff-Erkennung

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // Widerrufenes Token wird erneut verwendet → potenzieller Replay-Angriff
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

Wenn ein Angreifer ein Refresh-Token stiehlt, es verwendet, und der legitime Client dann versucht es zu verwenden (jetzt widerrufen) — erkennt das System dies und widerruft alle Sitzungen für den Benutzer, was eine erneute Authentifizierung erzwingt.

## Logout gibt immer 204 zurück

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Immer 204 — niemals enthüllen ob das Token gültig war
    return $this->json->createEmpty(204);
}
```

Das Zurückgeben von 401 für ein bereits widerrufenes Token beim Logout würde einem Angreifer erlauben zu sondieren, ob er ausgeloggt wurde.

## Sicherheitseigenschaften-Zusammenfassung

| Eigenschaft | Implementierung |
|---|---|
| Access-Token-TTL | 5 Minuten (Diebstahl-Exposition minimieren) |
| Refresh-Token-TTL | 7 Tage (Sitzungskontinuität) |
| Token-Speicherung | Nur SHA-256-Hash; roher Wert niemals gespeichert |
| Token-Rotation | Altes Token bei jeder erfolgreichen Aktualisierung widerrufen |
| Replay-Erkennung | Widerrufenes Token erneut verwendet → alle Benutzersitzungen widerrufen |
| Logout | Immer 204 (niemals Token-Gültigkeit lecken) |
| `jti`-Anspruch | Einzigartig pro Token (zukünftige Widerruf-Verfolgung) |

---

## Was man NICHT tun sollte

| Anti-Pattern | Risiko |
|---|---|
| Rohen Refresh-Token in DB speichern | DB-Einbruch exponiert alle aktiven Sitzungen |
| Hartes Löschen beim Widerrufen verwenden | Replay-Angriffe können nicht erkannt werden (benötigt `revoked = 1` um zu wissen, dass das Token existierte) |
| Langen Access-Token-TTL (Stunden/Tage) | Gestohlenes Token bietet langfristigen Zugang |
| 401 beim Logout mit ungültigem Token zurückgeben | Angreifer kann sondieren ob sie noch eingeloggt sind |
| Kein `jti` im Access-Token | Einzelne Tokens können nicht für zukünftige Widerruf-Listen verfolgt werden |
| Einzelnes Token (nur Access, kein Refresh) | Benutzer muss sich alle 5 Minuten erneut authentifizieren, oder gefährlich lange TTLs verwenden |
| MD5 oder SHA-1 für Token-Hash | Schwacher Hash; SHA-256 oder besser verwenden |
| Kein Ablauf bei Refresh-Tokens | Refresh-Tokens leben ewig; ein gestohlenes Token bietet unbegrenzten Zugang |
