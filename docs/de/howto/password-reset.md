# Passwort-Reset-Ablauf

Einen sicheren token-basierten Passwort-Reset implementieren: Anforderung → Verifizierung → Abschluss.

## Überblick

Ein Passwort-Reset-Ablauf hat drei Schritte:
1. Benutzer fordert einen Reset an — ein zeitbegrenztes Token wird generiert und gesendet (z.B. per E-Mail).
2. Benutzer verifiziert, dass das Token noch gültig ist, bevor das Reset-Formular angezeigt wird.
3. Benutzer reicht ein neues Passwort ein — Token wird verbraucht und Passwort aktualisiert.

## Datenbankschema

```sql
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash` speichert den SHA-256-Hash des Roh-Tokens. Das Roh-Token wird niemals in der Datenbank gespeichert.

## Token-Generierung und -Speicherung

Das Roh-Token mit `random_bytes` generieren, dann nur den SHA-256-Hash speichern:

```php
$rawToken  = bin2hex(random_bytes(32)); // 256-Bit-Entropie, 64 Hex-Zeichen
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// $rawToken an den Benutzer zurückgeben (per E-Mail oder API-Antwort)
```

Bei der Verifizierung das eingehende Token auf dieselbe Weise hashen:

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Einen Hash zu speichern bedeutet, dass ein DB-Einbruch keine verwendbaren Reset-Tokens exponiert — ein Angreifer müsste SHA-256 auf einem 256-Bit-Zufallswert umkehren, was rechnerisch nicht machbar ist.

## Benutzer-Enumerationsprävention

`POST /password-reset` muss immer 202 zurückgeben, auch für unbekannte E-Mail-Adressen:

```php
$user = $this->repo->findUserByEmail($email);

// Immer 202 — nicht verraten, ob die E-Mail registriert ist
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... Token für echten Benutzer generieren
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

404 für unbekannte E-Mails zurückzugeben würde einem Angreifer erlauben, registrierte Konten durch Sondieren von E-Mail-Adressen zu enumerieren.

## Einmalige Verwendung

`used_at` setzen, wenn der Reset abgeschlossen wird. Jedes Token ablehnen, das `used_at IS NOT NULL` hat:

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## Ablauf

Ablauf sowohl bei GET (Status-Check) als auch POST (Abschluss) erzwingen. Ablauf immer vor `isUsed()` prüfen:

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — unterscheidet sich von "nicht gefunden" (404) und "verwendet" (409)
}
if ($reset->isUsed()) {
    return 409;
}
```

410 (Gone) unterscheidet "abgelaufen" von "verwendet" (409) und gibt dem Benutzer handlungsrelevante Informationen.

## Altes Token invalidieren

Wenn ein Benutzer einen neuen Reset anfordert, alle vorherigen ungenutzten Tokens für diesen Benutzer invalidieren:

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

Ohne dies hätte ein Benutzer, der eine Reset-E-Mail verloren hat und eine neue anfordert, zwei gültige Tokens gleichzeitig im Umlauf — beide könnten verwendet werden, um das Passwort zurückzusetzen.

## Antwort-Bereinigung

`GET /password-reset/{token}` darf `user_id` oder `token_hash` nicht in der Antwort exponieren:

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

`user_id` zu exponieren würde das Reset-Token mit einer Benutzer-Konto-ID verknüpfen, was unnötig ist, da das Token selbst die Autorisierungsanmeldedaten ist.

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|-------------|----------------|
| Token-Entropie | `bin2hex(random_bytes(32))` — 256 Bits |
| Token-Speicherung | Nur SHA-256-Hash — Roh-Token niemals in DB |
| Benutzer-Enumeration | Immer 202 von `POST /password-reset` |
| Ablauf | 1 Stunde; bei GET und POST geprüft |
| Einmalige Verwendung | `used_at` bei Abschluss gesetzt; 409 bei Wiederverwendung |
| Altes Token invalidieren | Vorherige ungenutzte Tokens bei neuer Anfrage auf verwendet gesetzt |
| Antwort-Leck | `user_id` und `token_hash` aus allen Antworten ausgeschlossen |
| Passwort-Hashing | Argon2id |

## Routen-Zusammenfassung

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/password-reset` | Reset anfordern (immer 202) |
| `GET` | `/password-reset/{token}` | Token-Gültigkeit prüfen |
| `POST` | `/password-reset/{token}` | Reset mit neuem Passwort abschließen |
