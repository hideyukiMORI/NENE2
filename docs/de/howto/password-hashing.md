# How-to: Passwort-Hashing

Passwörter sicher speichern und verifizieren mit PHPs nativen `password_hash()` / `password_verify()` mit NENE2.

---

## Schnellstart

```php
// Registrierung — vor dem Speichern hashen
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// Login — Constant-Time-Verifizierung
if (!password_verify($inputPassword, $user->passwordHash)) {
    // 401 zurückgeben
}
```

---

## Algorithmus: immer `PASSWORD_ARGON2ID` verwenden

`PASSWORD_DEFAULT` ist ab PHP 8.4 immer noch `bcrypt`. Argon2id ist speicher-hart und widersteht GPU/ASIC-Angriffen.

```php
// ❌ PASSWORD_DEFAULT = bcrypt — anfälliger für GPU-Brute-Force
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — speicher-hart, für neue Projekte empfohlen
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id erfordert PHP 7.3+. NENE2 erfordert PHP 8.4, also ist es immer verfügbar.

---

## UNIQUE-Verletzungen erkennen: `DatabaseConstraintException`

NEENEs `PdoDatabaseQueryExecutor` verpackt alle Constraint-Verletzungen (UNIQUE, FK, NOT NULL) in `DatabaseConstraintException` bevor das erneute Werfen erfolgt. `\PDOException` direkt abfangen funktioniert **nicht**.

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ Wird nie hier erreicht — PDOException ist bereits verpackt
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ Den NENE2-Wrapper abfangen
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` ist Teil der stabilen öffentlichen API (ADR 0009).

Vollständiges Repository-Muster:

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    /** @throws DuplicateEmailException */
    public function create(string $email, string $passwordHash): User
    {
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $id  = $this->executor->insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, $passwordHash, $now],
            );

            return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
        } catch (DatabaseConstraintException) {
            throw new DuplicateEmailException($email);
        }
    }
}
```

---

## Benutzer-Enumerationsprävention (Timing-Angriff)

Wenn sofort 401 zurückgegeben wird, wenn die E-Mail nicht gefunden wird, verrät ein Timing-Unterschied, ob die E-Mail existiert — "Nicht-gefunden"-Antworten kommen sofort zurück, während "Falsches-Passwort"-Antworten die volle Argon2id-Rechenzeit benötigen.

```php
// ❌ Timing-Leck — nicht-gefunden ist messbar schneller
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ Immer password_verify ausführen — Constant-Time unabhängig davon, ob Benutzer existiert
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

Der Dummy-Hash **muss** ein gültiger Argon2id-Format-String sein, der mit `$argon2id$` beginnt. Falls nicht, bricht `password_verify()` kurz ab und gibt sofort `false` zurück, was das Timing-Leck wiederherstellt.

---

## `password_verify()` ist algorithmus-agnostisch

`password_verify()` liest das Hash-Präfix, um den Algorithmus zu bestimmen. Der Verifizierungscode muss bei der Migration von bcrypt auf Argon2id nicht geändert werden.

```php
// Funktioniert sowohl auf bcrypt- als auch Argon2id-Hashes
$result = password_verify($plaintext, $storedHash); // immer korrekt
```

`password_needs_rehash()` bei erfolgreichem Login verwenden, um Legacy-Hashes transparent zu upgraden:

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // Mit authentifiziertem Benutzer fortfahren
}
```

---

## `password_hash` niemals in die Antwort einschließen

`toArray()` oder ähnliche Helfer können jede Spalte einschließen. Nur die zurückzugebenden Felder explizit aufführen.

```php
// ❌ Kann password_hash lecken wenn $user eine toArray()-Methode hat
return $this->json->create($user->toArray(), 201);

// ✅ Explizite Feldliste — password_hash ist nie vorhanden
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## `RouteRegistrar::register()`-Namenskonflikt

NEENEs `RouteRegistrar`-Vertrag erfordert eine öffentliche `register(Router $router)`-Methode. Einen Routen-Handler **nicht** `register()` nennen — PHP lehnt den doppelten Methodennamen ab.

```php
// ❌ Fataler Fehler: Cannot redeclare RouteRegistrar::register()
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ Einen eindeutigen Handler-Namen verwenden
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## Code-Review-Checkliste

- [ ] `password_hash()` mit `PASSWORD_ARGON2ID` wird verwendet (nicht MD5, SHA-1, bcrypt oder `PASSWORD_DEFAULT`)
- [ ] `password_verify()` wird für Vergleiche verwendet (nicht `===`, `hash_equals()` oder benutzerdefinierter Vergleich)
- [ ] `password_verify()` läuft auch wenn der Benutzer nicht gefunden wird (Dummy-Hash-Muster)
- [ ] `DatabaseConstraintException` wird für doppelte E-Mail/Benutzername-Erkennung abgefangen
- [ ] `password_hash` / `password`-Felder sind aus allen API-Antworten ausgeschlossen
- [ ] Login gibt 401 (nicht 404) für unbekannte E-Mail zurück — niemals verraten, ob die E-Mail existiert
- [ ] Klartext-Passwort wird nicht in Logs geschrieben
