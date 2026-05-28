# How-to: Benutzerprofil-API

> **FT-Referenz**: FT275 (`NENE2-FT/profilelog`) — Benutzerprofil: ein-Profil-pro-Benutzer (UNIQUE user_id), E-Mail validiert mit FILTER_VALIDATE_EMAIL, Feldlängenbeschränkungen (display_name 100 / bio 500 / avatar_url 2048), Nur-HTTPS-Avatar-URL, DatabaseConstraintException → 409, Eigentümerguard via X-User-Id, 32 Tests BESTANDEN.

Demonstriert ein 1:1 Benutzer-zu-Profil-System: Benutzer erstellen (E-Mail eindeutig), ihr Profil erstellen/abrufen/aktualisieren. Profilfelder haben durchgesetzte Längenbeschränkungen und eine URL-Sicherheitsbeschränkung.

---

## Schema

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

`user_id UNIQUE` setzt die Ein-Profil-pro-Benutzer-Invariante auf DB-Ebene durch.

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer erstellen (E-Mail erforderlich, eindeutig) |
| `POST` | `/users/{userId}/profile` | Profil für Benutzer erstellen |
| `GET` | `/users/{userId}/profile` | Profil abrufen |
| `PUT` | `/users/{userId}/profile` | Profil aktualisieren (nur Eigentümer) |

---

## E-Mail-Validierung

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

Bei doppelter E-Mail wird `DatabaseConstraintException` abgefangen und auf 409 gemappt:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## Feldgrenzen (UserProfile-Value-Object)

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

Länge wird mit `mb_strlen()` geprüft (Mehrbyte-sicher):

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## Nur-HTTPS-Avatar-URL

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // Nur HTTPS erlauben, um javascript:- und data:-URI-Schemata zu verhindern
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` blockiert `javascript:`, `data:` und `http://` bevor `filter_var` läuft.

---

## Eigentümerguard

Profilaktualisierungen erfordern, dass `X-User-Id` mit dem Profileigentümer übereinstimmt:

```php
$actorId = $this->resolveActorId($request); // aus X-User-Id-Header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|-------------|--------|
| Keine E-Mail-Format-Validierung | Ungültige E-Mails gespeichert; Downstream-Sendevorgänge schlagen still fehl |
| Kein UNIQUE auf `user_id` in Profilen | Doppelte Profile möglich; GET gibt unvorhersehbare Zeile zurück |
| `strlen()` für display_name-Limit verwenden | Mehrbyte-Zeichen (Emoji, CJK) falsch gezählt |
| `http://`-Avatar-URLs erlauben | Passives Mixed-Content und potenzielle Clickjacking-Fläche |
| `javascript:`- oder `data:`-URIs erlauben | XSS wenn Avatar-URL als `<a href>` oder `<img src>` gerendert wird |
| `DatabaseConstraintException`-Catch überspringen | UNIQUE-Verletzung wird zu 500 statt 409 |
| Jedem Benutzer erlauben, jedes Profil zu aktualisieren | IDOR — vor dem Schreiben immer Akteur = Eigentümer prüfen |
