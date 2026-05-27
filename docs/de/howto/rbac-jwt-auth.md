# Anleitung: RBAC + JWT-Authentifizierung

> **FT-Referenz**: FT279 (`NENE2-FT/rbaclog`) — Rollenbasierte Zugriffskontrolle mit JWT: Argon2id-Passwort-Hashing mit Timing-Angriffs-Schutz, Rollen-Claim in JWT, Unterscheidung zwischen 401 und 403, BearerTokenMiddleware mit manuellem Fallback, 14 Tests / 48 Assertions BESTANDEN.
>
> **VULN-Bewertung**: V-01 bis V-10 am Ende dieses Dokuments.

Diese Anleitung zeigt, wie ein rollenbasiertes Zugriffskontrollsystem (RBAC) mit JWT-Tokens in NENE2 erstellt wird.

## Funktionen

- E-Mail + Passwort-Anmeldung (Argon2id-Hashing)
- Rollen-Claim im JWT (`user` / `admin`)
- Öffentliche, authentifizierte und nur-Admin-Endpunkte
- `BearerTokenMiddleware` mit Per-Handler-Fallback
- Korrekte Semantik: `401 Unauthorized` vs. `403 Forbidden`

## Schema

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author_id  INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Authentifizierung | Beschreibung |
|---------|------|-------------------|-------------|
| `POST` | `/auth/login` | Keine | Anmelden, JWT erhalten |
| `GET` | `/posts` | Keine | Alle Posts auflisten (öffentlich) |
| `POST` | `/posts` | Benutzer oder Admin | Post erstellen |
| `DELETE` | `/posts/{id}` | Nur Admin | Post löschen |

## Anmeldung mit Timing-Angriffs-Schutz

Der Dummy-Hash-Trick stellt sicher, dass die Anmeldung immer dieselbe Zeit benötigt, unabhängig davon, ob die E-Mail-Adresse existiert:

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

Ohne den Dummy-Hash kann ein Timing-Angriff gültige E-Mail-Adressen erkennen, indem die Antwortzeit gemessen wird — die Hash-Berechnung wird für unbekannte E-Mails übersprungen.

## Rollen-Claim im JWT

Die Rolle wird in der JWT-Payload gespeichert, um pro Anfrage einen DB-Abfrageaufwand zu vermeiden:

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## Rollenprüfung mit Enum

```php
private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
{
    $claims = $this->requireAuth($request);
    if ($claims instanceof ResponseInterface) {
        return $claims;
    }

    $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

    if ($actualRole !== $required) {
        return $this->problems->create(
            $request, 'forbidden', 'Forbidden', 403,
            "This action requires the '{$required->value}' role."
        );
    }

    return $claims;
}
```

`Role::tryFrom()` ordnet den String-Claim sicher dem Enum zu — ungültige Rollen-Strings werden zu `null`, was die Prüfung fehlschlagen lässt.

## Unterscheidung zwischen 401 und 403

| Status | Bedeutung | Wann |
|--------|-----------|------|
| `401 Unauthorized` | Nicht authentifiziert | Kein Token, ungültiges Token, abgelaufenes Token |
| `403 Forbidden` | Authentifiziert, aber unzureichende Rolle | Gültiges Token, falsche Rolle |

Diese Unterscheidung ist für Clients wichtig: Ein `401` sollte zu einer erneuten Anmeldung auffordern; ein `403` sollte eine „Zugriff verweigert"-Meldung anzeigen.

## BearerTokenMiddleware mit Fallback

Einige Pfade bedienen sowohl öffentliche als auch geschützte Methoden (z. B. `GET /posts` ist öffentlich, `POST /posts` ist authentifiziert). Die Middleware schließt den Pfad vollständig aus, und Handler, die Auth erfordern, rufen `requireAuth()` manuell auf:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts benötigt Per-Methoden-Behandlung
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // Schneller Pfad: Middleware hat bereits verifiziert
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // Langsamer Pfad: manuelle Extraktion für ausgeschlossene Pfade
    $authorization = $request->getHeaderLine('Authorization');
    if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }
}
```

---

## VULN-Bewertung — Sicherheitsdiagnose

### V-01 — Rollenerhöhung durch gefälschten JWT-Claim 🛡️ SICHER

**Bedrohung**: Angreifer erstellt ein JWT mit `"role": "admin"` und signiert es mit einem zufälligen Secret.
**Abwehr**: `LocalBearerTokenVerifier` validiert die HMAC-HS256-Signatur gegen das Server-Secret. Ein nicht übereinstimmendes Secret verursacht `TokenVerificationException` → 401.
**Ergebnis**: SICHER — Signaturverifizierung verhindert Claim-Fälschung.

---

### V-02 — Timing-Angriff durch E-Mail-Enumeration bei der Anmeldung 🛡️ SICHER

**Bedrohung**: Angreifer sendet Anmeldeanfragen für unbekannte vs. bekannte E-Mails und misst die Antwortzeit, um gültige Konten zu enumerieren.
**Abwehr**: Für unbekannte E-Mails wird `password_verify()` gegen einen Dummy-Argon2id-Hash (gleiche Kostenparameter) aufgerufen. Beide Pfade benötigen ~200ms. Die Anmeldemisserfolgs-Meldung ist identisch für falsche E-Mail und falsches Passwort.
**Ergebnis**: SICHER — Timing ist angeglichen; Fehlermeldung ist generisch.

---

### V-03 — Abgelaufenes Token als gültig akzeptiert 🛡️ SICHER

**Bedrohung**: Angreifer verwendet ein erfasstes JWT erneut, nachdem es abgelaufen ist.
**Abwehr**: `LocalBearerTokenVerifier` prüft den `exp`-Claim gegen `time()`. Abgelaufene Tokens werfen `TokenVerificationException` → 401.
**Ergebnis**: SICHER — `exp`-Prüfung wird erzwungen.

---

### V-04 — Rollen-Downgrade durch Änderung der JWT-Payload (ohne erneutes Signieren) 🛡️ SICHER

**Bedrohung**: Angreifer base64-dekodiert die JWT-Payload, ändert `"role": "user"` zu `"role": "admin"`, rekodiert und sendet mit der ursprünglichen Signatur.
**Abwehr**: Die JWT-Signatur deckt Header + Payload ab. Das Ändern der Payload macht die Signatur ungültig → `TokenVerificationException` → 401.
**Ergebnis**: SICHER — Payload-Manipulation durch HMAC erkannt.

---

### V-05 — Admin-Endpunkt mit Benutzerrolle erreichbar 🛡️ SICHER

**Bedrohung**: Angreifer meldet sich als `user` an und versucht `DELETE /posts/{id}`.
**Abwehr**: `requireRole($request, Role::Admin)` prüft den JWT-`role`-Claim. Ein `user`-Token hat `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403.
**Ergebnis**: SICHER — 403 Forbidden zurückgegeben; Benutzer-Token kann nicht auf Admin erhöht werden.

---

### V-06 — Nicht authentifizierter Zugriff auf geschützten Endpunkt 🛡️ SICHER

**Bedrohung**: Angreifer sendet `POST /posts` oder `DELETE /posts/{id}` ohne Authorization-Header.
**Abwehr**: `requireAuth()` prüft auf `Bearer `-Präfix; fehlender Header → 401 `unauthorized`.
**Ergebnis**: SICHER — 401 Unauthorized zurückgegeben.

---

### V-07 — Verwechslung von 401 und 403 (Informationsleck) 🛡️ SICHER

**Bedrohung**: Falsche 401/403-Verwendung gibt preis, ob eine Ressource existiert oder ob der Benutzer authentifiziert ist.
**Abwehr**: Das System gibt 401 für nicht authentifizierten Zugriff (kein/ungültiges Token) und 403 für authentifizierten Zugriff mit unzureichender Rolle zurück. Die Unterscheidung ist semantisch korrekt und gibt keine Ressourcenexistenz über die Rollenanforderung hinaus preis.
**Ergebnis**: SICHER — 401/403-Semantik ist korrekt; Tests `test401MeansNotAuthenticated` und `test403MeansAuthenticatedButForbidden` bestehen beide.

---

### V-08 — Unbekannter Rollen-String im JWT-Bypass 🛡️ SICHER

**Bedrohung**: Angreifer erstellt ein JWT (mit gültigem Secret, z. B. kompromittiertes Secret-Szenario) und setzt `role` auf einen unbekannten Wert wie `"superadmin"`.
**Abwehr**: `Role::tryFrom((string) ($claims['role'] ?? ''))` gibt `null` für unbekannte Strings zurück → `null !== Role::Admin` → 403.
**Ergebnis**: SICHER — `tryFrom()` ist null-sicher; unbekannte Rollen werden als unzureichend behandelt.

---

### V-09 — SQL-Injection über E-Mail-Feld bei der Anmeldung 🛡️ SICHER

**Bedrohung**: Angreifer sendet `{"email": "' OR '1'='1", "password": "anything"}`.
**Abwehr**: `findByEmail()` verwendet parametrisierte Abfrage (`WHERE email = ?`). Der injizierte String wird als Literalwert behandelt, nicht als SQL.
**Ergebnis**: SICHER — parametrisierte Abfragen verhindern SQL-Injection.

---

### V-10 — Passwort im Klartext gespeichert 🛡️ SICHER

**Bedrohung**: Bei einem DB-Einbruch sind Passwörter lesbar.
**Abwehr**: `password_hash($password, PASSWORD_ARGON2ID)` mit Kostenparametern `m=65536,t=4,p=1`. Nur der Argon2id-Hash wird gespeichert; das Klartext-Passwort wird nie persistiert.
**Ergebnis**: SICHER — Argon2id ist der aktuell empfohlene Algorithmus (RFC 9106); PBKDF2/bcrypt/scrypt würden ebenfalls bestehen.

---

### VULN-Zusammenfassung

| ID | Bedrohung | Ergebnis |
|----|-----------|----------|
| V-01 | Rollenerhöhung durch gefälschtes JWT | 🛡️ SICHER |
| V-02 | Timing-Angriff durch E-Mail-Enumeration | 🛡️ SICHER |
| V-03 | Abgelaufenes Token akzeptiert | 🛡️ SICHER |
| V-04 | JWT-Payload-Manipulation ohne erneutes Signieren | 🛡️ SICHER |
| V-05 | Admin-Endpunkt mit Benutzer-Rollen-Token | 🛡️ SICHER |
| V-06 | Nicht authentifizierter Zugriff auf geschützten Endpunkt | 🛡️ SICHER |
| V-07 | Verwechslung von 401 und 403 | 🛡️ SICHER |
| V-08 | Unbekannter Rollen-String-Bypass | 🛡️ SICHER |
| V-09 | SQL-Injection über E-Mail-Feld | 🛡️ SICHER |
| V-10 | Passwort im Klartext gespeichert | 🛡️ SICHER |

**10 SICHER, 0 EXPONIERT**
Argon2id-Hashing, HMAC-signiertes JWT, `Role::tryFrom()`-Schutz und parametrisierte Abfragen verhindern alle getesteten Schwachstellenvektoren.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Rolle in DB speichern und bei jeder Anfrage nachschlagen | Zusätzliche DB-Abfrage pro Anfrage; Rollenänderungen erfordern Token-Widerrufslogik |
| `Role::from()` statt `Role::tryFrom()` verwenden | Unbekannte Rollen-Strings werfen `ValueError` — 500 statt 403 |
| 403 für nicht authentifizierte Anfragen zurückgeben | Irreführt Clients — 403 sollte „authentifiziert, aber verboten" bedeuten, nicht „nicht angemeldet" |
| 401 für falschen Rollenzugriff zurückgeben | Client könnte erneute Anmeldung versuchen statt „Zugriff verweigert" anzuzeigen |
| Dummy-Hash bei der Anmeldung überspringen | Timing-Angriff gibt gültige E-Mail-Adressen preis |
| Passwörter als MD5/SHA1/Klartext speichern | Brute-Force- oder Rainbow-Table-Angriffe gefährden alle Passwörter bei einem DB-Einbruch |
| Berechtigungen im JWT einbetten (nicht Rollen) | Berechtigungsänderungen erfordern Token-Neuausstellung; Rollen sind stabil, Berechtigungen ändern sich |
| `alg: none` JWT zulassen | Angreifer kann Tokens fälschen, indem die Signatur vollständig entfernt wird |
| `str_contains($role, 'admin')` statt Enum-Prüfung verwenden | `"not-admin"` oder `"superadmin"` könnte unerwartet treffen |
