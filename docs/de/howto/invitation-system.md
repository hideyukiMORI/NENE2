# How-to: Einladungssystem

> **FT-Referenz**: FT283 (`NENE2-FT/invitelog`) — Einladungscode-System: 32-Zeichen-Hex-Token (128-Bit-Entropie), ISO 8601-Datums-/Zeit-Validierung, pending→used-Status-Lebenszyklus, match-Expression-Statuszuordnung, IDOR-geschützte Einladungsliste, 23 Tests / 47 Assertions PASS.

Diese Anleitung zeigt, wie ein sicheres Einladungssystem aufgebaut wird — Einmaltokens generieren, die bei Einlösung Zugriff gewähren.

## Anwendungsfall

Ein Benutzer erstellt einen Einladungslink (Token) und teilt ihn. Der Empfänger löst das Token ein, um beizutreten. Jedes Token ist für die einmalige Nutzung gedacht und zeitlich begrenzt.

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

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

Wichtige Punkte:
- `token TEXT UNIQUE` — erzwingt ein Token pro Zeile auf DB-Ebene
- `invitee_id` ist `NULL` bis zur Einlösung
- `status` — `'pending'` | `'used'`
- `used_at` — wird bei Einlösung gesetzt, liefert Audit-Zeitstempel

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/invitations` | `X-User-Id` | Einladung erstellen |
| `GET` | `/invitations/{token}` | Keine | Einladung nach Token nachschlagen |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | Einladung einlösen |
| `GET` | `/users/{userId}/invitations` | `X-User-Id` (nur Selbst) | Eigene Einladungen auflisten |

## Token-Generierung

```php
/** Token: 32 Kleinbuchstaben-Hex-Zeichen (16 zufällige Bytes = 128-Bit-Entropie) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` generiert kryptografisch sichere 128-Bit-Zufallsdaten. Die Hex-Darstellung besteht aus 32 Zeichen. Dies ist dasselbe Entropieniveau wie UUID v4 (122 nutzbare Bits).

## expires_at-Validierung

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

Der Regex validiert nur das Format. Die Zeitzonenbehandlung (UTC vs. lokal) liegt in der Verantwortung der Anwendung — die Verwendung einer einheitlichen Zeitzone (z.B. UTC) vermeidet Grenzfälle.

## Status-Lebenszyklus

```
pending → used (einseitig, irreversibel)
```

Nur `pending`-Einladungen können eingelöst werden. Einmal genutzt, ist die Einladung dauerhaft verbraucht.

## Einlösung mit match-Expression

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` ist erschöpfend (im Gegensatz zu `switch`): kein Fall-through, alle Fälle müssen behandelt werden. Das Repository gibt einen String-Ergebnistyp zurück; der Handler ordnet ihn sauber HTTP-Antworten zu.

## Repository — Atomare Einlösung

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // Ablauf prüfen
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // Als genutzt markieren
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

Die Prüf-dann-Aktualisieren-Sequenz ist eine potenzielle TOCTOU-Race-Condition bei gleichzeitigen Einlösungen desselben Tokens. Für die Produktion eine DB-Level-Transaktion oder `UPDATE WHERE status = 'pending'` verwenden und betroffene Zeilen prüfen.

## IDOR — Einladungsliste

Nur der Einladende kann seine eigenen Einladungen einsehen:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

Gibt 404 zurück (nicht 403), um zu verbergen, ob der Zielbenutzer existiert.

## X-User-Id-Header-Validierung

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` ist ReDoS-sicher; `strlen > 18` verhindert PHP-Int-Überlauf auf 64-Bit; `> 0` lehnt Benutzer-ID 0 ab.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kurze/sequenzielle Token verwenden (4-stelliger PIN) | In Millisekunden per Brute-Force knackbar; mindestens 128-Bit-Zufall verwenden |
| Token ohne `UNIQUE`-Constraint speichern | Doppeltes Token-Collision verursacht Verwirrung bei der Einlösung |
| `status === 'pending'` mit losem Vergleich prüfen | PHP `'0' == false`; immer striktes `===` verwenden |
| Keine Ablaufvalidierung bei Einlösung | Abgelaufene Einladungen bleiben dauerhaft einlösbar |
| 403 bei Einladungslisten-IDOR-Prüfung zurückgeben | Enthüllt, dass Zielbenutzer existiert; 404 verwenden, um Enumeration zu verbergen |
| Atomare Einlösung ohne Transaktion | Gleichzeitige Anfragen können beide `pending` sehen und beide erfolgreich sein — Doppeleinlösung |
| Soft Delete (`deleted_at`) statt Status-Spalte | Status-Spalte ist selbstdokumentierend; `pending`/`used` ist klarer als null/nicht-null |
| Beliebige Strings als `expires_at` akzeptieren | SQL-injectable wenn nicht parametrisiert; parametrisierte Abfrage + Formatvalidierung verwenden |
| Status bei abgelaufenem Token auf `pending` zurücksetzen | Ermöglicht die Wiederverwendung von legitim abgelaufenen Tokens |
