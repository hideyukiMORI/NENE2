# Passwortlose Authentifizierung (Magic Link)

Implementierungsanleitung für passwortlose Authentifizierung (Magic Link). Beschreibt sichere Designmuster für ein Einmalig-Link-System, das allein mit einer E-Mail-Adresse authentifiziert.

## Übersicht

Magic-Link-Authentifizierung funktioniert mit folgendem Ablauf:

1. Benutzer sendet E-Mail-Adresse
2. Server generiert einen Einmal-Token (Magic Link) und sendet ihn per E-Mail
3. Benutzer sendet das Token und erhält ein Session-Token
4. Mit dem Session-Token wird auf die API zugegriffen

## Endpunkte

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/auth/request` | E-Mail-Adresse angeben → Magic Link generieren (immer 202) |
| `POST` | `/auth/verify` | Magic-Link-Token verifizieren → Session-Token ausstellen |
| `POST` | `/auth/logout` | Session ungültig machen (immer 204) |
| `GET` | `/me` | Authentifizierte Benutzerinformationen abrufen |

## Datenbankdesign

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,  -- SHA-256-Hash speichern (Rohwert nicht gespeichert)
    expires_at TEXT NOT NULL,          -- 15-Minuten-Ablaufzeit
    used_at TEXT,                      -- Einmalige Verwendung (NULL = unbenutzt)
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- SHA-256-Hash speichern
    expires_at TEXT NOT NULL,                  -- 24-Stunden-Ablaufzeit
    revoked_at TEXT,                           -- beim Logout gesetzt
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Sicherheitsdesign

### Token als SHA-256-Hash speichern

```php
// Generierung: 256-Bit-Zufallswert → Hex-String (64 Zeichen)
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// Nur tokenHash in DB speichern
// rawToken wird nur per E-Mail gesendet (Sicherheit bei DB-Kompromiss)
```

Selbst wenn die DB kompromittiert wird, können Token aus den Hashes nicht wiederhergestellt werden. Gleiches Prinzip wie Passwort-Hashing.

### Benutzer-Enumerations-Prävention

```php
// POST /auth/request gibt immer 202 zurück
// Antwort unterscheidet sich nicht für registrierte/nicht-registrierte E-Mails
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

Angreifer können die Gültigkeit von E-Mail-Adressen nicht überprüfen.

### Ablauf-Check vor used_at-Check

```php
// Ablauf zuerst prüfen
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// Danach used_at prüfen
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

Verhindert, dass bekannt wird, ob ein abgelaufenes Token „verwendet" wurde (verhindert Timing-Informationsleck).

### Einmalige Verwendung (Replay-Angriffs-Prävention)

```php
// Bei erfolgreicher Verifizierung immediately used_at setzen
$this->repository->markMagicLinkUsed($linkId, $now);
```

Derselbe Magic Link kann nicht zweimal verwendet werden. Verhindert die Wiederverwendung abgefangener Links.

### Session-Ungültigmachung (Logout)

```php
// Logout gibt immer 204 zurück — verrät nicht Session-Existenz
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

`/me` gibt 401 zurück, wenn `revoked_at !== null`.

## Session-Validierungsablauf

```php
private function handleMe(ServerRequestInterface $request): ResponseInterface
{
    $rawToken = $this->extractBearerToken($request);
    if ($rawToken === '') {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }

    $tokenHash = hash('sha256', $rawToken);
    $session = $this->repository->findSessionByTokenHash($tokenHash);

    if ($session === null) { return 401; }
    if ($session['revoked_at'] !== null) { return 401 revoked; }
    if ($now > $session['expires_at']) { return 401 expired; }

    // ...
}
```

## Bearer-Token-Extraktion

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

`X-User-Id`-Header nicht für Authentifizierung verwenden. Nur `Authorization: Bearer <token>`.

## Automatische Neubenutzererstellung

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    return (int) $this->executor->lastInsertId();
}
```

Benutzer werden beim ersten Login automatisch erstellt. Eigenschaft der passwortlosen Authentifizierung.

## Magic-Link-Ablaufzeiten

- **Magic Link**: 15 Minuten (900 Sekunden) — genug Zeit zum E-Mail-Öffnen und Klicken
- **Session-Token**: 24 Stunden (86400 Sekunden) — normale API-Session

```php
$expiresAt = date('c', time() + 900);    // magic link: 15 min
$sessionExpiresAt = date('c', time() + 86400);  // session: 24h
```

## Produktionsüberlegungen

- **E-Mail-Versand**: In diesem FT wird `token` in der Antwort zurückgegeben (für Tests).
  In der Produktion per SMTP an die E-Mail-Adresse des Benutzers senden und aus der Antwort entfernen.
- **Rate Limiting**: Anfragen an `/auth/request` nach IP / E-Mail begrenzen.
- **Ungültigmachung alter unbenutzter Links**: Bei mehrmaligem Aufrufen von `/auth/request` mit derselben E-Mail erwägen, alte unbenutzte Links explizit ungültig zu machen.
- **HTTPS erforderlich**: Magic-Link-Token befinden sich in URL-Parametern, daher ist HTTPS Pflicht (Man-in-the-Middle-Angriffs-Prävention).
