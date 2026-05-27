# Anleitung: Refresh-Token-Muster

> **FT-Referenz**: FT281 (`NENE2-FT/refreshlog`) — Refresh-Token-Muster: kurzlebiges Access-Token (5 Min. JWT) + langlebiges Refresh-Token (7 Tage), SHA-256-Hash-Speicherung, Token-Rotation bei Verwendung, Replay-Angriffserkennung (widerrufenes Token → alle widerrufen), Logout gibt immer 204 zurück, 15 Tests / 63 Assertions BESTANDEN.

Diese Anleitung zeigt, wie das Refresh-Token-Muster implementiert wird — kurzlebige Access-Tokens für die Sicherheit, Refresh-Tokens für Sitzungskontinuität.

## Warum es wichtig ist

JWTs sind zustandslos. Einmal ausgestellt, können sie bis zu ihrem Ablauf nicht widerrufen werden. Eine 5-Minuten-TTL begrenzt das Risiko, wenn ein Token gestohlen wird. Refresh-Tokens verlängern Sitzungen ohne wiederholte Passwort-Eingabeaufforderungen und können bei jeder Verwendung rotiert (widerrufen und neu ausgestellt) werden, um Diebstahl zu erkennen.

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

`token_hash` speichert den SHA-256 des rohen Tokens — nie den Rohwert. `revoked` ist ein Soft-Delete-Flag (im Gegensatz zu Hard-Delete für die Replay-Erkennung).

## Endpunkte

| Methode | Pfad | Authentifizierung | Beschreibung |
|---------|------|-------------------|-------------|
| `POST` | `/auth/login` | Keine | E-Mail + Passwort → Access-Token + Refresh-Token |
| `POST` | `/auth/refresh` | Refresh-Token im Body | Refresh-Token rotieren, neues Paar ausstellen |
| `POST` | `/auth/logout` | Refresh-Token im Body | Refresh-Token widerrufen |
| `GET` | `/auth/me` | Bearer-Access-Token | Aktuelle Benutzerinformationen abrufen |

## Token-Laufzeiten

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 Minuten — kurz für Sicherheit
// Refresh-Tokens: 7 Tage (RefreshTokenRepository::TTL_DAYS)
```

Kurze Access-Tokens begrenzen das Risiko bei Diebstahl. Lange Refresh-Tokens ermöglichen es Benutzern, über Sitzungen hinweg angemeldet zu bleiben, ohne Passwörter erneut eingeben zu müssen.

## Token-Paar ausstellen

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // eindeutige Token-ID — ermöglicht zukünftiges Revocation-Tracking
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

    return $raw;  // ← Rohwert an Client zurückgeben; wird nie gespeichert
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

Bei einem DB-Einbruch erhalten Angreifer Hashes — nutzlos ohne die Rohwerte, die von den Clients gehalten werden.

## Token-Rotation

```php
// Bei erfolgreichem /auth/refresh:
$this->refreshTokens->revoke($stored->id);      // altes widerrufen
return $this->json->create($this->issueTokenPair($user));  // neues Paar ausstellen
```

Jede Aktualisierung rotiert das Token. Das alte Token wird sofort ungültig, sodass ein gestohlenes Refresh-Token nur einmal verwendet werden kann, bevor die Rotation es ungültig macht.

## Replay-Angriffserkennung

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // Ein widerrufenes Token wird erneut verwendet → möglicher Replay-Angriff
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

Wenn ein Angreifer ein Refresh-Token stiehlt, es verwendet und der legitime Client es dann (jetzt widerrufen) versucht — erkennt das System dies und widerruft alle Sitzungen des Benutzers, was eine erneute Authentifizierung erzwingt.

## Logout gibt immer 204 zurück

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Immer 204 — nie preisgeben, ob das Token gültig war
    return $this->json->createEmpty(204);
}
```

Die Rückgabe von 401 für ein bereits widerrufenes Token beim Logout würde einem Angreifer ermöglichen, zu prüfen, ob er ausgeloggt wurde.

## Token-Gültigkeitsprüfung

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

Sowohl Widerruf als auch Ablauf werden geprüft. Abgelaufene, aber nicht widerrufene Tokens werden ebenfalls abgelehnt.

## Sicherheitseigenschaften-Zusammenfassung

| Eigenschaft | Implementierung |
|-------------|----------------|
| Access-Token-TTL | 5 Minuten (Diebstahl-Exposition minimieren) |
| Refresh-Token-TTL | 7 Tage (Sitzungskontinuität) |
| Token-Speicherung | Nur SHA-256-Hash; Rohwert wird nie gespeichert |
| Token-Rotation | Altes Token wird bei jeder erfolgreichen Aktualisierung widerrufen |
| Replay-Erkennung | Widerrufenes Token erneut verwendet → alle Benutzersitzungen widerrufen |
| Logout | Immer 204 (Token-Gültigkeit nie preisgeben) |
| `jti`-Claim | Eindeutig pro Token (zukünftiges Revocation-Tracking) |

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Rohes Refresh-Token in DB speichern | DB-Einbruch gefährdet alle aktiven Sitzungen |
| Hard Delete bei Widerruf verwenden | Replay-Angriffe können nicht erkannt werden (benötigt `revoked = 1`, um zu wissen, dass das Token existierte) |
| Lange Access-Token-TTL (Stunden/Tage) | Gestohlenes Token bietet Langzeitzugang; macht Refresh-Tokens sinnlos |
| 401 beim Logout mit ungültigem Token zurückgeben | Angreifer kann prüfen, ob er noch angemeldet ist |
| Kein `jti` im Access-Token | Individuelle Tokens können nicht für zukünftige Revocation-Listen verfolgt werden |
| Einzelnes Token (nur Access, kein Refresh) | Benutzer muss sich alle 5 Minuten neu authentifizieren oder gefährlich lange TTLs verwenden |
| MD5 oder SHA-1 für Token-Hash | Schwacher Hash; SHA-256 oder besser verwenden |
| Kein Ablauf bei Refresh-Tokens | Refresh-Tokens leben ewig; ein gestohlenes Token bietet unbegrenzten Zugang |
