# Passwortlose Authentifizierung (Magic Link)

Implementierungsleitfaden für passwortlose Authentifizierung (Magic Link). Erklärt das sichere Designmuster für ein Einmal-Link-System, bei dem die Authentifizierung nur mit einer E-Mail-Adresse möglich ist.

## Überblick

Magic-Link-Authentifizierung funktioniert mit folgendem Ablauf:

1. Benutzer sendet E-Mail-Adresse
2. Server generiert ein Einmal-Token (Magic Link) und sendet es per E-Mail
3. Benutzer sendet das Token, um ein Session-Token zu erhalten
4. Session-Token für API-Zugriff verwenden

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/auth/request` | E-Mail-Adresse vorlegen → Magic Link generieren (immer 202) |
| `POST` | `/auth/verify` | Magic-Link-Token verifizieren → Session-Token ausgeben |
| `POST` | `/auth/logout` | Session invalidieren (immer 204) |
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
    token_hash TEXT NOT NULL UNIQUE,  -- SHA-256-Hash gespeichert (Rohwert wird nicht gespeichert)
    expires_at TEXT NOT NULL,          -- 15-Minuten-Ablaufzeit
    used_at TEXT,                      -- Einmalige Verwendung (NULL = unbenutzt)
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- SHA-256-Hash gespeichert
    expires_at TEXT NOT NULL,                  -- 24-Stunden-Ablaufzeit
    revoked_at TEXT,                           -- Bei logout gesetzt
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Sicherheitsdesign

### Token als SHA-256-Hash speichern

```php
// Generierung: 256-Bit-Zufall → Hex-String (64 Zeichen)
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// Nur tokenHash in DB speichern
// Nur rawToken wird per E-Mail gesendet (Sicherheit bei DB-Leck)
```

Selbst bei DB-Leck kann das Token nicht aus dem Hash wiederhergestellt werden. Gleiches Prinzip wie Passwort-Hashing.

### Benutzer-Enumerationsprävention

```php
// POST /auth/request gibt immer 202 zurück
// Antwort nicht zwischen registrierten / nicht-registrierten E-Mails unterscheiden
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

Angreifer kann die Gültigkeit von E-Mail-Adressen nicht bestätigen.

### Ablauf-Check vor used_at-Check

```php
// Zuerst Ablauf prüfen
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// Dann used_at prüfen
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

Verhindert das Preisgeben, ob ein abgelaufenes Token "verwendet" ist (Timing-Informations-Leck-Prävention).

### Einmalige Verwendung (Replay-Angriffs-Prävention)

```php
// Bei erfolgreichem verify sofort used_at setzen
$this->repository->markMagicLinkUsed($linkId, $now);
```

Denselben Magic Link kann man nicht zweimal verwenden. Verhindert die Wiederverwendung abgefangener Links.

### Session-Invalidierung (Logout)

```php
// Logout gibt immer 204 zurück — verrät keine Session-Existenz
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

Bei `/me` wird 401 zurückgegeben, wenn `revoked_at !== null`.

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

`X-User-Id`-Header wird nicht für die Authentifizierung verwendet. Nur `Authorization: Bearer <token>`.

## Neuen Benutzer automatisch erstellen

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

Benutzer wird beim ersten Login automatisch erstellt. Charakteristikum passwortloser Authentifizierung.

## Magic-Link-Ablaufzeiten

- **Magic Link**: 15 Minuten (900 Sekunden) — Zeit zum Öffnen und Klicken der E-Mail
- **Session Token**: 24 Stunden (86400 Sekunden) — normale API-Session

```php
$expiresAt = date('c', time() + 900);    // magic link: 15 Min
$sessionExpiresAt = date('c', time() + 86400);  // session: 24h
```

## Produktionsüberlegungen

- **E-Mail-Versand**: In diesem FT ist das `token` aus Testgründen in der Antwort enthalten. In der Produktion per SMTP an die E-Mail-Adresse des Benutzers senden und aus der Antwort entfernen.
- **Rate-Limiting**: Anfragen an `/auth/request` nach IP / E-Mail rate-limitieren.
- **Alte unbenutzte Links invalidieren**: Wenn `/auth/request` mehrmals mit derselben E-Mail aufgerufen wird, explizit alte unbenutzte Links invalidieren.
- **HTTPS erforderlich**: Da Magic-Link-Tokens in URL-Parametern enthalten sind, ist HTTPS erforderlich (Man-in-the-Middle-Angriffs-Prävention).
