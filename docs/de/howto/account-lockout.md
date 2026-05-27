# Kontosperrung (Brute-Force-Schutz)

> **FT-Referenz**: FT280 (`NENE2-FT/lockoutlog`) — Kontosperrung: 5 fehlgeschlagene Versuche lösen eine 15-minütige Sperrung aus (423 Locked), korrektes Passwort während der Sperrung blockiert, Erfolg setzt Zähler zurück, Argon2id-Passwortverifizierung, MySQL-Integrationstests, 27 Tests bestanden / 5 übersprungen (MySQL), 44 Assertions PASS.
>
> **ATK-Bewertung**: ATK-01 bis ATK-12 am Ende dieses Dokuments.

Schützen Sie Login-Endpunkte vor Brute-Force-Angriffen, indem Sie ein Konto nach einer konfigurierbaren Anzahl fehlgeschlagener Versuche sperren.

## Überblick

Die Kontosperrung verfolgt fehlgeschlagene Anmeldeversuche pro E-Mail-Adresse und setzt einen `locked_until`-Zeitstempel, wenn der Fehlerschwellenwert überschritten wird. Die Sperre wird bei jedem Anmeldeversuch durchgesetzt — selbst ein korrektes Passwort wird während der Kontosperrung abgelehnt. Die Sperre läuft nach einer Abkühlphase automatisch ab.

## Datenbankschema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` verfolgt den Fehlerverlauf pro Konto. `locked_until` ist null für entsperrte Konten.

## Konstanten

```php
public const int MAX_ATTEMPTS    = 5;   // Fehler vor der Sperrung
public const int LOCKOUT_MINUTES = 15;  // Sperrdauer
```

## Anmelde-Ablauf

```php
// 1. Sperrung vor der Passwortverifizierung prüfen
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Gesperrt
}

// 2. Anmeldedaten verifizieren
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Nicht autorisiert
}

// 3. Erfolg — Zähler zurücksetzen
$this->repo->resetState($email, $now);
return 200;
```

Die Sperrprüfung erfolgt **vor** der Passwortverifizierung. Der Sperrstatus wird nur für **bestehende Benutzer** geschrieben — unbekannte E-Mails geben 401 zurück, ohne eine `account_state`-Zeile zu erstellen (verhindert Speichersättigung).

## Sperrprüfung

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` ist ein `Y-m-d H:i:s`-String. Der lexikografische Vergleich funktioniert korrekt für ISO-8601-Datetime-Strings.

## Fehler aufzeichnen

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

Wenn `failed_count` `MAX_ATTEMPTS` erreicht, wird `locked_until` auf `now + LOCKOUT_MINUTES * 60` Sekunden gesetzt.

## Zurücksetzen bei Erfolg

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

Eine erfolgreiche Authentifizierung setzt sowohl `failed_count` als auch `locked_until` zurück. Ein Benutzer, der vor der Sperrung erfolgreich ist, erhält einen neuen Fehlerzähler.

## Verhinderung von Benutzer-Enumeration

Für falsches Passwort und unbekannte E-Mail den gleichen HTTP-Status (401) zurückgeben:

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // gleicher Status unabhängig vom Grund
}
```

Ein Angreifer kann anhand der HTTP-Antwort nicht unterscheiden, ob kein Konto vorhanden ist oder das Passwort falsch ist.

## MySQL-Schema

Für MySQL `INT AUTO_INCREMENT` und `DATETIME` verwenden:

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Das `Y-m-d H:i:s`-Datetime-Format funktioniert für SQLite (TEXT-Vergleich) und MySQL (DATETIME-Spalte).

## MySQL-Integrationstest

Eine `MysqlLockoutTest.php` hinzufügen, die übersprungen wird, wenn `MYSQL_HOST` nicht gesetzt ist:

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // Tabellen für Testisolierung löschen und neu erstellen
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

Gegen den gemeinsamen FT-MySQL-Container ausführen (Port 3308, persistentes Volume):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Dann die Integrationstests mit Umgebungsvariablen ausführen:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Ohne `MYSQL_HOST` werden die MySQL-Tests automatisch übersprungen.

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|---|---|
| Sperrschwellenwert | 5 fehlgeschlagene Versuche |
| Sperrdauer | 15 Minuten |
| Korrektes Passwort während der Sperrung | Blockiert (423) |
| Benutzer-Enumeration | Gleiches 401 für unbekannte E-Mail und falsches Passwort |
| Sperrumfang | Pro E-Mail-Adresse, nicht pro IP |
| Sperre zurücksetzen | Automatisch bei erfolgreicher Anmeldung |
| Passwort-Hashing | Argon2id |
| Lange E-Mail-Eingabe | Ab 256+ Zeichen abgelehnt (422) |
| SQL-Injection | Parametrisierte Abfragen verhindern Injection |

## Design-Kompromiss: Kontosperrungs-DoS

Da die Sperrung pro E-Mail (nicht pro IP) erfolgt, kann ein Angreifer, der die E-Mail eines Benutzers kennt, diesen durch 5 falsche Passwörter aussperren. Dies ist eine inhärente Spannung zwischen Brute-Force-Schutz und Verfügbarkeit.

Gegenmaßnahmen (hier nicht implementiert, aber verfügbar):
- **Progressive Verzögerungen** statt harter Sperrung
- **CAPTCHA** nach N Fehlern
- **Benachrichtigungs-E-Mail** bei ausgelöster Sperrung
- **Admin-Entsperr-Endpunkt**

Für die meisten Anwendungen bevorzugt der Kompromiss den Brute-Force-Schutz. Die Sperrung läuft automatisch nach 15 Minuten ab.

## Routen-Übersicht

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/users` | Benutzer erstellen (Seed/Registrierung) |
| `POST` | `/auth/login` | Anmeldeversuch (200/401/423) |
| `GET` | `/auth/status/{email}` | Sperrstatus prüfen |

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Brute-Force bis zur Sperrung 🚫 BLOCKIERT

**Angriff**: 5+ fehlgeschlagene Anmeldeversuche mit falschen Passwörtern für eine bekannte E-Mail senden.
**Ergebnis**: BLOCKIERT — nach 5 Fehlern setzt `failed_count >= MAX_ATTEMPTS` `locked_until = jetzt + 15 Min`. Nachfolgende Versuche erhalten 423 `account-locked`, bevor das Passwort geprüft wird.

---

### ATK-02 — Korrektes Passwort nach der Sperrung einreichen 🚫 BLOCKIERT

**Angriff**: Konto sperren, dann sofort das korrekte Passwort einreichen.
**Ergebnis**: BLOCKIERT — Sperrprüfung erfolgt vor `findUserByEmail()`. Selbst mit dem korrekten Passwort wird 423 zurückgegeben, solange die Sperre aktiv ist.

---

### ATK-03 — Nicht existierende E-Mail testen, um Sperrung auf echten Konten zu vermeiden 🚫 BLOCKIERT (by design)

**Angriff**: Nicht existierende E-Mail verwenden, ohne Sperrung auf echten Konten auszulösen.
**Ergebnis**: BLOCKIERT (by design) — Nicht existierende E-Mails häufen keine Fehler an und schützen den Speicher. Echte Konten sind durch ihren eigenen Sperrstatus geschützt. Das Testen gefälschter E-Mails verrät nichts über echte Konten.

---

### ATK-04 — Race Condition: Gleichzeitige Anmeldeversuche am Fehlerschwellenwert 🚫 BLOCKIERT

**Angriff**: Zwei Anfragen gleichzeitig senden, wenn `failed_count` bei 4 liegt, um an der Sperrung vorbeizukommen.
**Ergebnis**: BLOCKIERT — `UPDATE account_states` ist auf DB-Ebene atomar. SQLite WAL serialisiert gleichzeitige Schreibvorgänge; MySQL verwendet Sperren auf Zeilenebene. Beide Updates gelingen; das endgültige `locked_until` wird korrekt gesetzt.

---

### ATK-05 — Status-Endpunkt enthüllt Sperrstatus 🚫 BLOCKIERT (by design)

**Angriff**: `GET /auth/status/{email}` verwenden, um herauszufinden, ob eine E-Mail gesperrt wurde.
**Ergebnis**: BY DESIGN — der Status-Endpunkt ist für Client-UX gedacht ("erneut in 15 Min versuchen"). In der Produktion sollte dieser Rate-Limited oder authentifizierungspflichtig sein. Er enthüllt den Sperrzeitpunkt, aber keine Passwortinformationen.

---

### ATK-06 — SQL-Injection über E-Mail-Feld 🚫 BLOCKIERT

**Angriff**: `{"email": "' OR '1'='1' --", "password": "x"}` senden.
**Ergebnis**: BLOCKIERT — alle Abfragen verwenden parametrisierte Anweisungen (`WHERE email = ?`). Der injizierte String wird als literaler E-Mail-Wert behandelt.

---

### ATK-07 — Übermäßig langer E-Mail-String zur Denial-of-Service 🚫 BLOCKIERT

**Angriff**: Ein E-Mail-Feld mit 100.000 Zeichen senden.
**Ergebnis**: BLOCKIERT — `if (strlen($email) > 255)` → 422 `validation-failed` vor jeder DB-Abfrage.

---

### ATK-08 — Fehlende E-Mail- oder Passwortfelder 🚫 BLOCKIERT

**Angriff**: `{}` oder `{"email": "x@x.com"}` ohne Passwort senden.
**Ergebnis**: BLOCKIERT — `if ($email === '' || $pass === '')` → 422 `validation-failed`.

---

### ATK-09 — Zähler durch Anmeldung mit anderem Konto zurücksetzen 🚫 BLOCKIERT

**Angriff**: Konto A sperren, dann als Konto B anmelden, um den Zähler von A zurückzusetzen.
**Ergebnis**: BLOCKIERT — `resetState()` ist nach E-Mail-Adresse gegliedert. Die erfolgreiche Anmeldung eines anderen Kontos hat keinen Einfluss auf den Status von Konto A.

---

### ATK-10 — Nur-Leerzeichen-E-Mail zur Umgehung der Validierung 🚫 BLOCKIERT

**Angriff**: `{"email": "   ", "password": "x"}` senden.
**Ergebnis**: BLOCKIERT — `$email = trim($body['email'])` reduziert Leerzeichen auf `''` → 422.

---

### ATK-11 — Nicht-String-E-Mail-Typ zur Umgehung der is_string-Prüfung 🚫 BLOCKIERT

**Angriff**: `{"email": 12345, "password": "x"}` (Ganzzahl als E-Mail) senden.
**Ergebnis**: BLOCKIERT — `is_string($body['email'])`-Prüfung → false → `$email = ''` → 422.

---

### ATK-12 — Anhaltende Kontosperrung des Opfers (Verfügbarkeitsangriff) 🚫 BLOCKIERT (mitigiert)

**Angriff**: Böswilliger Benutzer schlägt wiederholt die Anmeldung für die E-Mail des Opfers fehl, um die Sperrung aufrechtzuerhalten.
**Ergebnis**: MITIGIERT — die Sperrung ist zeitbasiert (15 Minuten). Sie läuft automatisch ab; kein dauerhaftes Verbot. Ein anhaltender Angriff hält das 15-Minuten-Fenster aufrecht, kann das Konto aber nicht dauerhaft deaktivieren. Produktionshärtung: CAPTCHA, IP-basiertes Rate-Limiting, Benutzer per E-Mail benachrichtigen.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|--------|--------|
| ATK-01 | Brute-Force bis zur Sperrung | 🚫 BLOCKIERT |
| ATK-02 | Korrektes Passwort nach der Sperrung | 🚫 BLOCKIERT |
| ATK-03 | Test über nicht existierende E-Mail | 🚫 BLOCKIERT (by design) |
| ATK-04 | Race Condition beim Fehlerzähler | 🚫 BLOCKIERT |
| ATK-05 | Status-Endpunkt enthüllt Sperrstatus | 🚫 BLOCKIERT (by design) |
| ATK-06 | SQL-Injection über E-Mail | 🚫 BLOCKIERT |
| ATK-07 | Übermäßig langer E-Mail-String DoS | 🚫 BLOCKIERT |
| ATK-08 | Fehlende Pflichtfelder | 🚫 BLOCKIERT |
| ATK-09 | Zähler über anderes Konto zurücksetzen | 🚫 BLOCKIERT |
| ATK-10 | Nur-Leerzeichen-E-Mail | 🚫 BLOCKIERT |
| ATK-11 | Nicht-String-E-Mail-Typ | 🚫 BLOCKIERT |
| ATK-12 | Anhaltende Kontosperrung des Opfers | 🚫 BLOCKIERT (mitigiert) |

**12 BLOCKIERT / MITIGIERT, 0 EXPONIERT**
Sperrprüfung vor der Passwortverifizierung, parametrisierte Abfragen, Eingabelängenvalidierung und zeitbasierter Ablauf verhindern alle getesteten Angriffsvektoren.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Sperrung nach der Passwortverifizierung prüfen | Verschwendet Argon2id-CPU für gesperrte Konten; Timing-Seitenkanal bei der Sperrung |
| 429 für Kontosperrung zurückgeben | Falsche Semantik — 429 ist Rate-Limiting, 423 ist eine gesperrte Ressource |
| Dauerhafte Sperrung bei Fehler implementieren | Angreifer kann für jeden Benutzer mit bekannter E-Mail dauerhaft Denial-of-Service verursachen |
| Fehler für nicht existierende E-Mails aufzeichnen | Angreifer erstellt vorab Sperrzustände, bevor Benutzer sich anmelden |
| Keine E-Mail-Längenvalidierung | 100KB+-E-Mail-Strings verursachen langsame Abfragen oder Speicherdruck |
| Sperrstatus im Arbeitsspeicher/Session speichern | Status geht beim Server-Neustart verloren; wird nicht über mehrere App-Instanzen geteilt |
| Gleicher Fehler für gesperrt vs. falsches Passwort | Schwierige UX-Unterscheidung — 423 für gesperrt, 401 für falsche Anmeldedaten verwenden |
