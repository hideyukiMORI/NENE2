# How-to: RBAC + JWT-Authentifizierung

> **FT-Referenz**: FT279 (`NENE2-FT/rbaclog`) — Rollenbasierte Zugriffskontrolle mit JWT: Argon2id-Passwort-Hashing mit Timing-Angriffs-Schutz, Rollen-Claim im JWT, Unterscheidung 401 vs. 403, BearerTokenMiddleware mit manuellem Fallback, 14 Tests / 48 Assertions bestanden.
>
> **VULN-Assessment**: V-01 bis V-10 am Ende dieses Dokuments enthalten.

Diese Anleitung zeigt, wie ein rollenbasiertes Zugriffskontrollsystem (RBAC) mit JWT-Tokens in NENE2 gebaut wird.

## Funktionen

- E-Mail + Passwort-Login (Argon2id-Hashing)
- Rollen-Claim im JWT eingebettet (`user` / `admin`)
- Öffentliche, authentifizierte und nur-Admin-Endpunkte
- `BearerTokenMiddleware` mit per-Handler-Fallback
- Korrekte `401 Unauthorized` vs. `403 Forbidden`-Semantik

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

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/auth/login` | Keine | Anmelden, JWT erhalten |
| `GET` | `/posts` | Keine | Alle Beiträge auflisten (öffentlich) |
| `POST` | `/posts` | Benutzer oder Admin | Beitrag erstellen |
| `DELETE` | `/posts/{id}` | Nur Admin | Beitrag löschen |

## Login mit Timing-Angriffs-Schutz

Der Dummy-Hash-Trick stellt sicher, dass der Login immer gleich lang dauert, ob die E-Mail existiert oder nicht:

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Ungültige Anmeldedaten', 401, '...');
}
```

Ohne den Dummy-Hash kann ein Timing-Angriff gültige E-Mail-Adressen erkennen, indem die Antwortzeit gemessen wird — die Hash-Berechnung wird für unbekannte E-Mails übersprungen.

## Rollen-Claim im JWT

Die Rolle wird im JWT-Payload gespeichert, um einen DB-Roundtrip bei jeder Anfrage zu vermeiden:

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
            $request, 'forbidden', 'Verboten', 403,
            "Diese Aktion erfordert die Rolle '{$required->value}'."
        );
    }

    return $claims;
}
```

`Role::tryFrom()` ordnet den String-Claim sicher dem Enum zu — ungültige Rollen-Strings werden zu `null`, was die Prüfung scheitern lässt.

## Unterscheidung 401 vs. 403

| Status | Bedeutung | Wann |
|---|---|---|
| `401 Unauthorized` | Nicht authentifiziert | Kein Token, ungültiges Token, abgelaufenes Token |
| `403 Forbidden` | Authentifiziert aber unzureichende Rolle | Gültiges Token, falsche Rolle |

Diese Unterscheidung ist für Clients wichtig: Ein `401` sollte eine erneute Anmeldung auslösen; ein `403` sollte eine "Zugriff verweigert"-Meldung anzeigen.

## BearerTokenMiddleware mit Fallback

Einige Pfade bedienen sowohl öffentliche als auch geschützte Methoden (z.B. `GET /posts` ist öffentlich, `POST /posts` ist authentifiziert). Die Middleware schließt den Pfad vollständig aus, und Handler, die Auth benötigen, rufen `requireAuth()` manuell auf:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts benötigt per-Methode-Behandlung
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
        return $this->problems->create($request, 'unauthorized', 'Nicht autorisiert', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Nicht autorisiert', 401, '...');
    }
}
```

---

## VULN-Assessment — Schwachstellenanalyse

### V-01 — Rollenerweiterung via gefälschtem JWT-Claim 🛡️ SAFE

**Bedrohung**: Angreifer erstellt einen JWT mit `"role": "admin"` und signiert ihn mit einem zufälligen Secret.
**Abwehr**: `LocalBearerTokenVerifier` validiert die HMAC-HS256-Signatur gegen das Server-Secret. Ein nicht übereinstimmendes Secret verursacht `TokenVerificationException` → 401.
**Ergebnis**: SAFE — Signaturverifizierung verhindert Claim-Fälschung.

---

### V-02 — Timing-Angriff via E-Mail-Enumeration beim Login 🛡️ SAFE

**Bedrohung**: Angreifer sendet Login-Anfragen für unbekannte vs. bekannte E-Mails und misst die Antwortzeit zur Enumeration gültiger Konten.
**Abwehr**: Für unbekannte E-Mails wird `password_verify()` gegen einen Dummy-Argon2id-Hash aufgerufen (gleiche Kostenparameter). Beide Pfade dauern ~200ms. Die Login-Fehlermeldung ist identisch für falsche E-Mail und falsches Passwort.
**Ergebnis**: SAFE — Timing ist ausgeglichen; Fehlermeldung ist generisch.

---

### V-03 — Abgelaufenes Token als gültig akzeptiert 🛡️ SAFE

**Bedrohung**: Angreifer verwendet ein erfasstes JWT nach Ablauf wieder.
**Abwehr**: `LocalBearerTokenVerifier` prüft den `exp`-Claim gegen `time()`. Abgelaufene Tokens werfen `TokenVerificationException` → 401.
**Ergebnis**: SAFE — `exp`-Prüfung wird durchgesetzt.

---

### V-04 — Rollendegradierung durch Modifizieren des JWT-Payloads (ohne erneutes Signieren) 🛡️ SAFE

**Bedrohung**: Angreifer base64-dekodiert den JWT-Payload, ändert `"role": "user"` zu `"role": "admin"`, kodiert erneut und sendet mit ursprünglicher Signatur.
**Abwehr**: JWT-Signatur deckt Header + Payload ab. Das Modifizieren des Payloads macht die Signatur ungültig → `TokenVerificationException` → 401.
**Ergebnis**: SAFE — Payload-Manipulation durch HMAC erkannt.

---

### V-05 — Admin-Endpunkt mit Benutzerrolle zugänglich 🛡️ SAFE

**Bedrohung**: Angreifer meldet sich als `user` an und versucht `DELETE /posts/{id}`.
**Abwehr**: `requireRole($request, Role::Admin)` prüft den JWT-`role`-Claim. Ein `user`-Token hat `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403.
**Ergebnis**: SAFE — 403 Forbidden zurückgegeben; Benutzer-Token kann sich nicht zu Admin erhöhen.

---

### V-06 — Nicht-authentifizierter Zugriff auf geschützten Endpunkt 🛡️ SAFE

**Bedrohung**: Angreifer sendet `POST /posts` oder `DELETE /posts/{id}` ohne Authorization-Header.
**Abwehr**: `requireAuth()` prüft auf `Bearer `-Präfix; fehlender Header → 401 `unauthorized`.
**Ergebnis**: SAFE — 401 Unauthorized zurückgegeben.

---

### V-07 — 401 vs. 403 Verwechslung (Informationsleck) 🛡️ SAFE

**Bedrohung**: Falsche 401/403-Verwendung gibt preis, ob eine Ressource existiert oder ob der Benutzer authentifiziert ist.
**Abwehr**: System gibt 401 für nicht-authentifizierten Zugriff zurück (kein/ungültiges Token) und 403 für authentifizierten Zugriff mit unzureichender Rolle. Die Unterscheidung ist semantisch korrekt und gibt keine Ressourcenexistenz über die Rollenanforderung hinaus preis.
**Ergebnis**: SAFE — 401/403-Semantik ist korrekt.

---

### V-08 — Ungültiger Rollen-String im JWT-Bypass 🛡️ SAFE

**Bedrohung**: Angreifer erstellt einen JWT (mit gültigem Secret, z.B. kompromittiertes Secret-Szenario) und setzt `role` auf einen unbekannten Wert wie `"superadmin"`.
**Abwehr**: `Role::tryFrom((string) ($claims['role'] ?? ''))` gibt `null` für unbekannte Strings zurück → `null !== Role::Admin` → 403.
**Ergebnis**: SAFE — `tryFrom()` ist null-sicher; unbekannte Rollen werden als unzureichend behandelt.

---

### V-09 — SQL-Injection via E-Mail-Feld beim Login 🛡️ SAFE

**Bedrohung**: Angreifer sendet `{"email": "' OR '1'='1", "password": "anything"}`.
**Abwehr**: `findByEmail()` verwendet parametrisierte Abfrage (`WHERE email = ?`). Der injizierte String wird als literaler Wert behandelt, nicht als SQL.
**Ergebnis**: SAFE — parametrisierte Abfragen verhindern SQL-Injection.

---

### V-10 — Passwort im Klartext gespeichert 🛡️ SAFE

**Bedrohung**: Bei einem DB-Einbruch sind Passwörter lesbar.
**Abwehr**: `password_hash($password, PASSWORD_ARGON2ID)` mit Kostenparametern `m=65536,t=4,p=1`. Nur der Argon2id-Hash wird gespeichert; das Klartext-Passwort wird niemals persistiert.
**Ergebnis**: SAFE — Argon2id ist der aktuell empfohlene Algorithmus (RFC 9106); PBKDF2/bcrypt/scrypt würden ebenfalls bestehen.

---

### VULN-Zusammenfassung

| ID | Bedrohung | Ergebnis |
|----|-----------|---------|
| V-01 | Rollenerweiterung via gefälschtem JWT | 🛡️ SAFE |
| V-02 | Timing-Angriff via E-Mail-Enumeration | 🛡️ SAFE |
| V-03 | Abgelaufenes Token akzeptiert | 🛡️ SAFE |
| V-04 | JWT-Payload-Manipulation ohne erneutes Signieren | 🛡️ SAFE |
| V-05 | Admin-Endpunkt mit Benutzerrollen-Token | 🛡️ SAFE |
| V-06 | Nicht-authentifizierter Zugriff auf geschützten Endpunkt | 🛡️ SAFE |
| V-07 | 401 vs. 403 Verwechslung | 🛡️ SAFE |
| V-08 | Unbekannter Rollen-String-Bypass | 🛡️ SAFE |
| V-09 | SQL-Injection via E-Mail-Feld | 🛡️ SAFE |
| V-10 | Passwort im Klartext gespeichert | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Argon2id-Hashing, HMAC-signiertes JWT, `Role::tryFrom()`-Guard und parametrisierte Abfragen verhindern alle getesteten Schwachstellenvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Rolle in DB speichern und bei jeder Anfrage nachschlagen | Zusätzliche DB-Abfrage pro Anfrage; Rollenänderung erfordert Token-Widerruf-Logik |
| `Role::from()` statt `Role::tryFrom()` verwenden | Unbekannte Rollen-Strings werfen `ValueError` — 500 statt 403 |
| 403 für nicht-authentifizierte Anfragen zurückgeben | Führt Clients in die Irre — 403 sollte "authentifiziert aber verboten" bedeuten, nicht "nicht angemeldet" |
| 401 für falschen Rollen-Zugriff zurückgeben | Client versucht möglicherweise erneut anzumelden statt "Zugriff verweigert" anzuzeigen |
| Dummy-Hash beim Login überspringen | Timing-Angriff gibt gültige E-Mail-Adressen preis |
| Passwörter als MD5/SHA1/Klartext speichern | Brute-Force- oder Rainbow-Table-Angriffe setzen alle Passwörter bei einem DB-Einbruch frei |
| Berechtigungen (nicht Rollen) im JWT einbetten | Berechtigungsänderungen erfordern Token-Neuausstellung; Rollen sind stabil, Berechtigungen ändern sich |
| `alg: none` JWT erlauben | Angreifer kann Tokens durch vollständiges Entfernen der Signatur fälschen |
| `str_contains($role, 'admin')` statt Enum-Prüfung verwenden | `"not-admin"` oder `"superadmin"` könnten unerwartet übereinstimmen |
