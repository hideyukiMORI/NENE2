# Verschlüsselte Feldspeicherung aufbauen

> **FT-Referenz**: FT267 (`NENE2-FT/encryptlog`) — AES-256-GCM-Feldverschlüsselung: Verschlüsselung beim Schreiben / Entschlüsselung beim Lesen, Blind-Index für durchsuchbaren Chiffretext, Schlüsseltrennung zwischen Verschlüsselung und Index-Schlüsseln
>
> **VULN-Bewertung**: V-01 bis V-10 am Ende dieses Dokuments.
>
> **Muster auch belegt durch FT187 encryptlog** — AES-256-GCM-Pro-Feld-Verschlüsselung mit HMAC-SHA256-Blind-Index für durchsuchbare PII-Speicherung.

---

## Was abgedeckt wird

Sensible Felder (Name, E-Mail, SSN, Kreditkarte) verschlüsselt im Ruhezustand speichern, während sie durchsuchbar bleiben:

1. **AES-256-GCM** — authentifizierte Verschlüsselung; jeder Eintrag erhält seinen eigenen Nonce
2. **Blind-Index** — HMAC-SHA256 des Feldwerts ermöglicht `WHERE email_idx = ?` ohne Entschlüsselung
3. **AEAD-Manipulationserkennung** — Tag-Mismatch verursacht `\RuntimeException`, nicht 400
4. **Chiffretext niemals in API-Antworten** — die VO / toArray()-Schicht gibt immer Klartext zurück
5. **IDOR-Prävention** — alle Lese-/Schreibvorgänge begrenzen `WHERE id AND user_id`

---

## Chiffretext-Format

```
base64( nonce ‖ ciphertext ‖ tag )
```

| Komponente | Größe | Zweck |
|---|---|---|
| `nonce` | 12 Bytes | Zufälliger Pro-Verschlüsselung-IV (GCM-Standard) |
| `ciphertext` | variabel | AES-256-GCM-verschlüsselter Klartext |
| `tag` | 16 Bytes | Authentifizierungs-Tag — erkennt Manipulation |

Als einzelne `TEXT`-Spalte gespeichert. Gleicher Klartext → jedes Mal anderer Chiffretext (anderer Nonce).

---

## Schema

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- HMAC-SHA256 Blind-Index für die Suche
    notes_enc  TEXT,               -- nullbares verschlüsseltes Feld
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` hat einen Index — `WHERE email_idx = ?` ist schnell. Der `email_enc`-Chiffretext wird niemals durchsucht.

---

## FieldCrypto-Helfer

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // muss 32 Bytes sein
        private string $indexKey, // muss 32 Bytes sein
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // frischer Pro-Wert-IV
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * Deterministisch — gleiche Eingabe immer → gleiche Ausgabe.
     * Ermöglicht WHERE email_idx = ? ohne gespeicherten Chiffretext zu entschlüsseln.
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## Kernmuster: Schreiben verschlüsselt, Lesen entschlüsselt

```php
// CREATE — alle sensiblen Felder vor INSERT verschlüsseln
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // deterministisch für die Suche
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// READ — beim Hydratisieren transparent entschlüsseln
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## Kernmuster: Blind-Index-Suche

```php
// SUCHE — Blind-Index aus Query-Parameter berechnen, keine Zeilen beim Suchen entschlüsseln
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // gleicher Schlüssel → gleicher Index
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // Zeilen werden dann in hydrateRow() entschlüsselt
}
```

**Wenn E-Mail beim Update ändert, neu indizieren:**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← muss zusammen aktualisiert werden
]);
```

---

## Kernmuster: Chiffretext niemals in Antworten

```php
// VaultRecord::toArray() — gibt nur entschlüsselten Klartext zurück
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // Klartext
        'email'      => $this->email, // Klartext
        'notes'      => $this->notes, // Klartext oder null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc, email_enc, email_idx, notes_enc — niemals preisgegeben
    ];
}
```

Ein Angreifer, der die API-Antwort liest, kann keinen Chiffretext für Offline-Angriffe extrahieren.

---

## Kernmuster: Manipulationserkennung ist ein 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // Tag-Mismatch = manipulierte DB-Zeile ODER falscher Schlüssel
    // Werfen — globalen Error-Handler 500 zurückgeben lassen
    // KEIN 400 zurückgeben — ein 400 ist ein Client-Fehler; dies ist ein internes Integritätsproblem
    throw new \RuntimeException('Decryption failed.');
}
```

400 zurückzugeben würde implizieren, dass der Client schlechte Daten gesendet hat. Ein 500 signalisiert korrekt "server-seitiges Integritätsproblem" und verrät nicht, welches Feld fehlgeschlagen ist oder warum.

---

## Schlüsselverwaltungsrichtlinien

```php
// Produktion: Schlüssel von einem KMS oder Secret-Manager ableiten
$encKey   = random_bytes(32); // 32 Bytes = AES-256
$indexKey = random_bytes(32); // separater Schlüssel — andere HMAC-Domain

// Schlüssel NIEMALS in Quellcode hartcodieren; Env-Vars oder Key-Ableitung verwenden:
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 Hex-Zeichen → 32 Bytes
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 Hex-Zeichen → 32 Bytes
```

**Zwei separate Schlüssel:**
- `encKey` — AES-256-GCM. Rotierbar: Zeilen mit neuem Schlüssel neu verschlüsseln, Versions-Präfix aktualisieren.
- `indexKey` — HMAC-SHA256. Kann nicht rotiert werden, ohne alle Indizes neu zu hashen.

---

## Testergebnisse (FT187)

```
51 Tests / 110 Assertions — alle bestanden
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

| Testbereich | Abdeckung |
|---|---|
| FieldCrypto-Unit | Verschlüssel/Entschlüssel-Roundtrip, Nonce-Einzigartigkeit, Blind-Index-Determinismus, Manipulationserkennung, Kurz-Schlüssel-Ablehnung |
| Happy Path | create/get/list/update/delete/search |
| Chiffretext-Isolation | `name_enc`, `email_enc`, `email_idx`, `notes_enc` nicht in der Antwort |
| IDOR-Prävention | Cross-User get/update/delete geben alle 404 zurück |
| Mass-Assignment | `name_enc`, `email_idx`, `user_id` aus Body ignoriert |
| Validierung | fehlender/langer/falscher-Typ name, email, notes, limit |
| Blind-Index-Neuindizierung | E-Mail-Update hält Index synchron |

---

## VULN-Bewertung (FT267)

Sicherheitsbewertung von `NENE2-FT/encryptlog` unter dem Feldverschlüsselungs-Bedrohungsmodell.

### V-01 — Schlüsselverwaltung: Env-Laden ✅ BLOCKED

**Bedrohung**: Verschlüsselungsschlüssel, die ins VCS eingecheckt oder im Quellcode hartcodiert sind.
**Gegenmaßnahme**: Schlüssel werden über `getenv()` in `ConfigLoader` geladen, Länge wird beim Booten validiert. Die `.env`-Datei ist in .gitignore. Kein Schlüsselmaterial erscheint im Quellcode.
**Verbleibendes Risiko**: Schlüsselrotation (beide Schlüssel ersetzen, alle Zeilen neu verschlüsseln) ist nicht implementiert. Für FT-Umfang akzeptiert; Produktionssystem braucht einen Rotationsplan.

---

### V-02 — Nonce-Wiederverwendung (GCM) ✅ BLOCKED

**Bedrohung**: Wenn derselbe Nonce jemals zweimal unter demselben Schlüssel verwendet wird, verliert GCM alle Vertraulichkeits- und Authentizitätsgarantien.
**Gegenmaßnahme**: `random_bytes(12)` wird bei jeder `encrypt()`-Ausführung aufgerufen. Der 96-Bit-Nonce-Raum und `random_bytes()` machen die Kollisionswahrscheinlichkeit für jedes realistische Nutzungsvolumen vernachlässigbar (< 2^32 Verschlüsselungen pro Schlüssellebensdauer ist die sichere Grenze).
**Befund**: Sicher.

---

### V-03 — Authentifizierungs-Tag-Verifizierung ✅ BLOCKED

**Bedrohung**: Chiffretext-Manipulation bleibt unerkannt; Angreifer dreht Bits, um entschlüsselten Klartext zu manipulieren.
**Gegenmaßnahme**: `openssl_decrypt()` verifiziert den 16-Byte-GCM-Authentifizierungs-Tag vor der Rückgabe von Klartext. Jede Ein-Bit-Änderung gibt `false` zurück, was `FieldCrypto::decrypt()` in eine geworfene `\RuntimeException` umwandelt. Die Anwendung fängt sie ab und gibt `500` zurück; kein partieller Klartext wird preisgegeben.
**Befund**: Sicher.

---

### V-04 — API-Antwort verrät Entschlüsselungsfehler-Detail ⚠️ EXPOSED

**Bedrohung**: Error-Handler serialisiert `\RuntimeException::getMessage()` ("Decryption failed — tag mismatch or corrupt ciphertext.") in die API-Antwort und verrät ein Integritätssignal an Angreifer.
**Befund**: Im `APP_DEBUG=true`-Modus kann die vollständige Nachricht und Stack Trace auftauchen. Im `APP_DEBUG=false`-Modus kann der Standard-Handler noch den Exception-Klassennamen preisgeben.
**Empfehlung**: Einen dedizierten `DecryptionFailedExceptionHandler` hinzufügen, der unabhängig vom Debug-Modus auf `500` mit einem generischen `"internal-error"` Problem Details-Body abbildet. Tag-Verifizierungsfehler sollten nur server-seitig protokolliert werden.

---

### V-05 — Blind-Index-Kollision / Offline-Wörterbuch ✅ BLOCKED

**Bedrohung**: Angreifer erstellt offline ein Wörterbuch von `blindIndex(candidate)`-Werten und vergleicht es mit der `email_idx`-Spalte.
**Gegenmaßnahme**: HMAC-SHA256 mit einem 256-Bit-Geheimschlüssel. Ohne `VAULT_INDEX_KEY` ist die Vorberechnung eines Index-Werts rechnerisch nicht machbar. Der Blind-Index unterstützt nur Exakt-Match (`WHERE email_idx = ?`); Wildcard- oder Substring-Suche ist nicht möglich.
**Verbleibendes Risiko**: Wenn `VAULT_INDEX_KEY` kompromittiert ist, werden alle E-Mail-Blind-Indizes für eine endliche bekannte E-Mail-Liste brute-forcierbar. Die Schlüsselvertraulichkeit ist unerlässlich.

---

### V-06 — Keine Authentifizierung / Autorisierung auf Endpunkten ⚠️ EXPOSED

**Bedrohung**: Jeder unauthentifizierte Aufrufer kann Vault-Einträge für beliebige `user_id`-Werte erstellen, lesen, aktualisieren und löschen.
**Befund**: Das FT gibt `/vault/{userId}/records` ohne API-Key, JWT oder Session-Prüfung frei. Der `user_id`-Pfadparameter wird vom Aufrufer angegeben.
**Empfehlung**: Authentifizierung (API-Key oder JWT) voraussetzen und `$userId` aus dem verifizierten Token ableiten — niemals einer aufrufer-gelieferten `user_id` vertrauen. `requireScope()` oder eine äquivalente Auth-Middleware hinzufügen.
**FT-Hinweis**: Absichtliche Umfangsbeschränkung für das FT. Produktionsnutzung erfordert Auth.

---

### V-07 — IDOR bei Update / Delete ✅ BLOCKED

**Bedrohung**: Authentifizierter-aber-falscher Benutzer modifiziert den verschlüsselten Eintrag eines anderen Benutzers.
**Gegenmaßnahme**: Alle Schreibabfragen enthalten `AND user_id = :user_id`. Wenn der Eintrag einem anderen Benutzer gehört, gibt `rowCount()` 0 zurück und der Controller gibt 404 zurück. Der Angreifer erfährt nur, dass der Eintrag (für ihn) nicht existiert.
**Befund**: Sicher, vorausgesetzt Authentifizierung ist vorhanden (siehe V-06).

---

### V-08 — Schlüsselrotation / Re-Verschlüsselungs-Lücke ⚠️ EXPOSED

**Bedrohung**: Wenn `VAULT_ENC_KEY` rotiert wird, kann alter Chiffretext, der unter dem vorherigen Schlüssel verschlüsselt wurde, nicht entschlüsselt werden. Es gibt keine Re-Verschlüsselungs-Migrationsstrategie.
**Befund**: Keine Schlüssel-Versionierung, kein Re-Verschlüsselungs-Tool und keine dokumentierte Migration.
**Empfehlung**: Jedem verschlüsselten Blob ein Schlüssel-Versions-Byte voranstellen (z. B. `v1:<base64>`). Beim Entschlüsseln Version lesen, Schlüssel auswählen. Ein Migrationsskript bereitstellen, das unter dem alten Schlüssel entschlüsselt und unter dem neuen Schlüssel in einer Transaktion neu verschlüsselt.

---

### V-09 — Blind-Index-Timing-Vergleich ✅ BLOCKED

**Bedrohung**: Der Vergleich von `email_idx` aus einer nicht vertrauenswürdigen Quelle mit `===` verrät zeichenweises Timing.
**Gegenmaßnahme**: `findByEmail()` übergibt den berechneten Blind-Index als SQL-Parameter. Der Vergleich erfolgt innerhalb von SQLites B-Tree-Index-Suche, was kein Timing-Orakel von der PHP-Seite ist. Kein PHP-seitiger String-Vergleich von Blind-Index-Werten findet statt.
**Befund**: Sicher.

---

### V-10 — Entschlüsselte Daten im Speicher / in Logs ⚠️ EXPOSED

**Bedrohung**: Entschlüsselter Klartext (Name, E-Mail, Notizen) erscheint in: PHP-Exception-Traces, Request-Logging-Middleware (wenn Body protokolliert wird), Fehlerausgabe, APM-Spans.
**Befund**: Request-Body-Logging-Middleware protokolliert den POST-Body vor der Verschlüsselung — Klartext-Felder sind im Log. Wenn `VaultRecord` in einem Exception-Kontext enthalten ist, erscheinen entschlüsselte Felder im Stack-Trace.
**Empfehlung**:
1. Klartext-Vault-Payloads aus dem Request-Body-Logging ausschließen (maskieren oder `/vault`-Routen überspringen).
2. `__debugInfo()` auf `VaultRecord` implementieren, um sensible Felder aus var_dump / Exception-Serialisierung zu schwärzen.
3. Sicherstellen, dass Error-Tracking-Integrationen (Sentry usw.) Klartext-Felder vor der Übertragung scrubben.

---

### VULN-Zusammenfassung

| ID | Bedrohung | Status |
|----|---------|--------|
| V-01 | Schlüssel ins VCS eingecheckt | ✅ BLOCKED |
| V-02 | Nonce-Wiederverwendung (GCM) | ✅ BLOCKED |
| V-03 | Manipulierter Chiffretext akzeptiert | ✅ BLOCKED |
| V-04 | Entschlüsselungsfehler-Detail in Antwort | ⚠️ EXPOSED |
| V-05 | Blind-Index-Offline-Wörterbuch | ✅ BLOCKED |
| V-06 | Keine Authentifizierung auf Endpunkten | ⚠️ EXPOSED |
| V-07 | IDOR bei Update/Delete | ✅ BLOCKED |
| V-08 | Schlüsselrotation / Re-Verschlüsselungs-Lücke | ⚠️ EXPOSED |
| V-09 | Blind-Index-Timing-Vergleich | ✅ BLOCKED |
| V-10 | Entschlüsselte Daten in Logs/Exceptions | ⚠️ EXPOSED |

**Bewertung**: 6 BLOCKED, 4 EXPOSED.

Die vier Offenlegungen betreffen Schlüsselrotationsstrategie (V-08), Authentifizierung (V-06, absichtlicher FT-Umfang), Fehlerdetail-Leak (V-04) und Log-Hygiene (V-10). Keine davon stellt einen Fehler im AES-256-GCM- oder Blind-Index-kryptografischen Design dar — es sind betriebliche und Integrationslücken, die vor der Produktionsnutzung behoben werden müssen.
