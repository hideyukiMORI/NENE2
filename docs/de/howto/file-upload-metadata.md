# How-to: File Upload Metadata API (VULN-A–L)

Diese Anleitung demonstriert sicheres File-Upload-Metadaten-Management, das VULN-A bis VULN-L abdeckt.

## Muster-Übersicht

Dateien werden von dieser API nicht gespeichert — nur ihre Metadaten (Dateiname, MIME-Typ, Größe) werden erfasst. Der eigentliche Dateitransfer erfolgt separat (z. B. direkt zu S3). Dies ist ein gängiges Muster zur Nachverfolgung von Upload-Historien und zur Durchsetzung von Constraints.

## Schema

```sql
CREATE TABLE IF NOT EXISTS uploads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    filename    TEXT    NOT NULL,
    mime_type   TEXT    NOT NULL,
    size_bytes  INTEGER NOT NULL,
    is_public   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

## VULN-A: SQL-Injection

Alle Abfragen verwenden PDO Prepared Statements. Von Benutzern eingereichte Dateinamen und MIME-Typen werden niemals in SQL-Strings interpoliert.

## VULN-B: Mass Assignment + MIME-Allowlist

Nur eine explizite Allowlist von MIME-Typen wird akzeptiert:

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

Unbekannte MIME-Typen (z. B. `application/x-msdownload`, `application/x-sh`) werden mit 422 abgelehnt.

## VULN-C: IDOR

Nicht-Admin-Benutzer können nur auf ihre eigenen Uploads zugreifen. Uploads anderer Benutzer geben 404 zurück (nicht 403):

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F: Path Traversal

Verzeichnis-Trennzeichen und `..` werden in Dateinamen abgelehnt:

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

Dies verhindert Dateinamen wie `../etc/passwd`, `C:\Windows\cmd.exe` oder `subdir/evil.php`.

## VULN-G: ReDoS

IDs in Pfad-Parametern werden mit `ctype_digit()` validiert, niemals mit Regex.

## VULN-I: Negative / Null-Werte

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

Null- und negative Größen werden abgelehnt.

## VULN-J: Typverwechslung

- `mime_type` muss `is_string()` sein — Integer `123` wird abgelehnt.
- `size_bytes` muss `is_int()` sein — String `"1024"` und Float `100.5` werden abgelehnt.
- `is_public` muss `is_bool()` sein — String `"true"` und Integer `1` werden abgelehnt.

## Validierungs-Zusammenfassung

| Feld | Regel |
|------|-------|
| `X-User-Id` | Erforderlich für POST/DELETE; `ctype_digit`, >0 |
| `filename` | Nicht leer, max 255 Zeichen, kein `/`, `\`, `..` |
| `mime_type` | String; muss in Allowlist sein |
| `size_bytes` | Integer 1–104.857.600 (100 MiB) |
| `is_public` | Nur Boolean |

## Routen

```
POST   /uploads              Upload-Metadaten registrieren (X-User-Id erforderlich)
GET    /uploads/{id}         Metadaten abrufen (Eigentümer oder Admin)
DELETE /uploads/{id}         Eintrag löschen (Eigentümer oder Admin)
GET    /users/{userId}/uploads  Uploads eines Benutzers auflisten (Eigentümer oder Admin)
```

## Siehe auch

- FT210-Quelle: `../NENE2-FT/uploadlog/`
- Verwandt: `docs/howto/wish-list-api.md` (FT207, auch VULN)
