# Benutzerprofil-Verwaltung

Benutzerorientierte Profildaten speichern und aktualisieren: Anzeigename, Bio und Avatar-URL. Die Profilerstellung erfolgt getrennt von der Benutzererstellung — Benutzer existieren zuerst, dann wird ein Profil einmalig erstellt und an Ort und Stelle aktualisiert.

## Überblick

Eine Profilverwaltungs-API umfasst:
- **Benutzer erstellen** — E-Mail-basierte Benutzerregistrierung (ein Profil pro Benutzer)
- **Profil erstellen** — Erstmalige Profileinrichtung (idempotenzresistent: 409 wenn bereits vorhanden)
- **Profil abrufen** — Aktuelle Profildaten abrufen
- **Profil aktualisieren** — Profilfelder ersetzen (Eigentümerschaft wird durchgesetzt)

## Datenbankschema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE` auf `user_id` setzt ein Profil pro Benutzer auf DB-Ebene durch.

## Umgang mit doppelter E-Mail

`DatabaseConstraintException` abfangen, um 409 statt eines 500-Leaks zurückzugeben:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

Ohne diesen Catch führt eine doppelte E-Mail zu einer unbehandelten Ausnahme, die interne Fehlerdetails an den Client weitergibt.

## Avatar-URL-Validierung

Nur `https://`-URLs erlauben, um `javascript:`, `data:`, `file://` und `http://`-Schemata zu verhindern:

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // Nur HTTPS — blockiert javascript:, data:, file://, ftp://, http://
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

Leerer String ist erlaubt (kein Avatar gesetzt). Das Limit `MAX_AVATAR_URL_LENGTH = 2048` verhindert Speichermissbrauch.

## Feldlängenbeschränkungen

Beschränkungen als Konstanten auf dem Value-Object definieren für eine einzige Wahrheitsquelle:

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

`mb_strlen()` statt `strlen()` für Mehrbyte (UTF-8) Korrektheit verwenden.

## Eigentümerschaftsprüfung

Der `PUT /users/{userId}/profile`-Endpunkt verwendet einen `X-User-Id`-Header zur Identifikation des anfragenden Akteurs. In der Produktion durch einen JWT-Claim ersetzen:

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// Im Handler:
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Nicht-numerischer oder fehlender Header wird zu `0` aufgelöst, was niemals einer echten Benutzer-ID entspricht → 403.

## Verhinderung doppelter Profile

Vor dem Einfügen auf vorhandenes Profil prüfen und 409 zurückgeben:

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

Dies verhindert, dass ein zweiter `POST /users/{userId}/profile` ein vorhandenes Profil still überschreibt.

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|-------------|-----------------|
| Doppelte E-Mail | `DatabaseConstraintException` abgefangen → 409 (kein Stack-Trace-Leak) |
| avatar_url-Schema | `str_starts_with('https://')` blockiert alle Nicht-HTTPS-Schemata |
| avatar_url-Länge | `MAX_AVATAR_URL_LENGTH = 2048` |
| bio-Länge | `MAX_BIO_LENGTH = 500` mit `mb_strlen()` |
| Eigentümerschaft | `X-User-Id`-Header (in Produktion durch JWT-Claim ersetzen) |
| Ein Profil pro Benutzer | `UNIQUE (user_id)` DB-Constraint + 409-Prüfung im Handler |

## Routenübersicht

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer registrieren (E-Mail, 409 bei Duplikat) |
| `POST` | `/users/{userId}/profile` | Profil erstellen (409 wenn bereits vorhanden) |
| `GET` | `/users/{userId}/profile` | Profil abrufen |
| `PUT` | `/users/{userId}/profile` | Profil aktualisieren (erfordert `X-User-Id`-Header) |
