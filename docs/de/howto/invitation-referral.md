# How-to: Einladungs-/Empfehlungs-API

Diese Anleitung zeigt, wie ein tokenbasiertes Einladungssystem mit Ablauf und einmaliger Verwendung mit NENE2 aufgebaut wird.
Muster demonstriert durch den **invitelog**-Feldversuch (FT221).

## Funktionen

- Einladungstoken generieren (`bin2hex(random_bytes(16))` = 32 Kleinbuchstaben-Hex-Zeichen)
- Ablaufdatum pro Einladung festlegen (ISO 8601)
- Einladung annehmen/verwenden (einmalig, verfolgt Eingeladenen)
- Benutzerspezifische Einladungsliste (IDOR: nur Selbst kann ansehen)
- Status-Lebenszyklus: `pending → used` (Ablauf wird bei Verwendung erkannt)

## Schema

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/invitations` | Benutzer | Einladung erstellen (gibt Token zurück) |
| `GET` | `/invitations/{token}` | Öffentlich (Token = Secret) | Einladungsstatus abrufen |
| `POST` | `/invitations/{token}/use` | Benutzer | Einladung annehmen |
| `GET` | `/users/{userId}/invitations` | Benutzer (nur Selbst) | Eigene Einladungen auflisten |

## Token-Generierung

```php
$token = bin2hex(random_bytes(16)); // 32 Kleinbuchstaben-Hex-Zeichen, kryptografisch sicher
```

Token-Muster in Pfad-Parametern validiert:

```php
/** Token: 32 Kleinbuchstaben-Hex-Zeichen (16 zufällige Bytes) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## Einmalige Verwendungslogik

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // Als verwendet markieren + Eingeladenen erfassen
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## IDOR-Schutz

Der Einladungslisten-Endpunkt erzwingt Selbst-Zugriff:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

Der `GET /invitations/{token}`-Endpunkt verwendet den Token selbst als Secret — das Wissen des Tokens gewährt Zugriff. Dies ist das „Token = Capability"-Muster.

## Sicherheitsmuster

- **`bin2hex(random_bytes(16))`**: Kryptografisch sicherer 128-Bit-Entropie-Token
- **Token-Muster-Validierung**: `/\A[0-9a-f]{32}\z/` — blockiert SQL-Injection, überdimensionierte Token
- **`ctype_digit()`**: ReDoS-sichere Integer-Validierung für Benutzer-ID-Pfadparameter
- **ISO-8601-Ablaufvalidierung**: Regex-Muster + lexikografischer Vergleich (UTC)
- **Ablauf bei Verwendung geprüft**: Nicht vorausgefiltert — Token-Lookup gibt Ergebnis zurück, dann wird Ablauf geprüft
