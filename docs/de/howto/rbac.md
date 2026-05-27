# How-to: Rollenbasierte Zugriffskontrolle (RBAC)

Implementierung rollenbasierter Zugriffskontrolle mit JWT-Claims und `BearerTokenMiddleware`.

---

## Schnellstart

```php
// 1. Rolle beim Login in das JWT aufnehmen
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' oder 'admin'
    'exp'  => time() + 3600,
]);

// 2. Rolle im Handler prüfen
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "Diese Aktion erfordert die Rolle 'admin'.");
}
```

---

## Rollen in JWT-Claims einbetten

**Zwei Ansätze:**

| Ansatz | Vorteil | Nachteil |
|---|---|---|
| Rolle in JWT-Claims | Keine DB-Abfrage pro Anfrage | Rollenänderungen werden erst nach Token-Ablauf wirksam |
| DB-Lookup pro Anfrage | Sofortige Rollenänderungen | Zusätzliche Abfrage bei jeder authentifizierten Anfrage |

Für die meisten Anwendungen ist der JWT-Ansatz angemessen. Für hochsicherheitskritische Kontexte (Medizin, Finanzen, Admin-Privilegien-Widerruf) eine DB-Abfrage bei sensiblen Operationen hinzufügen.

```php
// Login — Rolle in Claims einbetten
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // String: 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized vs. 403 Forbidden

Diese Unterscheidung ist für die Client-Fehlerbehandlung wichtig (401 → zur Anmeldung weiterleiten, 403 → Berechtigungsfehler anzeigen):

| Situation | Status |
|---|---|
| Kein Token / abgelaufen / ungültige Signatur | **401** Unauthorized |
| Gültiges Token, aber unzureichende Rolle | **403** Forbidden |
| Ressource nicht gefunden | **404** Not Found |

```php
// ❌ Falsch — authentifizierter Benutzer erhält 401 (impliziert "nicht angemeldet")
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ Korrekt — authentifiziert aber keine Berechtigung
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "Diese Aktion erfordert die Rolle 'admin'.");
}
```

---

## `requireAuth()` / `requireRole()`-Muster

Ein wiederverwendbares Hilfs-Paar im Route-Registrar:

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... weitere Abhängigkeiten
    ) {}

    /**
     * Gibt Claims bei Erfolg zurück oder eine 401-ResponseInterface.
     * Prüft zuerst das Middleware-Attribut; fällt auf manuelle Verifizierung
     * für Pfade zurück, die von BearerTokenMiddleware ausgeschlossen sind.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (is_array($claims)) {
            return $claims;
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Authentifizierung erforderlich.');
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Token ist ungültig oder abgelaufen.');
        }
    }

    /**
     * Gibt Claims zurück, wenn der Benutzer die erforderliche Rolle hat,
     * oder eine 401/403-ResponseInterface.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
    {
        $claims = $this->requireAuth($request);

        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

        if ($actualRole !== $required) {
            return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
                "Diese Aktion erfordert die Rolle '{$required->value}'.");
        }

        return $claims;
    }
}
```

Verwendung in Handlern:

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 oder 403
    }
    // $claims ist jetzt das verifizierte JWT-Payload eines Admins
}
```

---

## `BearerTokenMiddleware` unterscheidet nicht nach HTTP-Methode

`BearerTokenMiddleware` verwendet den Request-Pfad, nicht die HTTP-Methode, um zu entscheiden, ob Authentifizierung erforderlich ist. Wenn `GET /posts` (öffentlich) und `POST /posts` (auth-erforderlich) denselben Pfad teilen, `/posts` von der Middleware ausschließen und das Token manuell im Handler verifizieren:

```php
// Middleware: /posts vollständig ausschließen (deckt sowohl GET als auch POST ab)
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// Für DELETE /posts/{id} (Pfad /posts/1, /posts/2 etc.) — NICHT in excludedPaths → Middleware schützt es.
// Für POST /posts (Pfad /posts) — ausgeschlossen → Handler muss requireAuth() manuell aufrufen.
```

Der `requireAuth()`-Helfer oben behandelt dies transparent: er liest `nene2.auth.claims` aus dem Middleware-Attribut, wenn vorhanden, und fällt auf das direkte Parsen des `Authorization`-Headers zurück, wenn nicht.

**Alternative**: Unterschiedliche Pfad-Präfixe verwenden, um die Mehrdeutigkeit vollständig zu vermeiden:
- `GET /public/posts` — keine Auth
- `POST /posts` — Auth erforderlich (Middleware kann `/posts` ohne Konflikt schützen)

---

## `Role`-Enum-Muster

Ein backed Enum für typsicheres Rollen-Handling verwenden:

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() wirft bei unbekannten Werten
$role = Role::from($claims['role']);  // UnhandledMatchError bei 'superuser' oder ''

// ✅ Role::tryFrom() gibt null bei unbekannten Werten zurück
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content — `createEmpty()` verwenden

`JsonResponseFactory::create()` erfordert ein `array`-Argument. Für 204-Antworten ohne Body `createEmpty()` verwenden:

```php
// ❌ Typ-Fehler — create() akzeptiert kein null
return $this->json->create(null, 204);

// ❌ Gibt leeres JSON-Objekt {} zurück (Body sollte für 204 fehlen)
return $this->json->create([], 204);

// ✅ Korrekt — kein Body, korrekter Status
return $this->json->createEmpty(204);
```

---

## Code-Review-Checkliste

- [ ] `role`-Claim wird mit `Role::tryFrom()` dekodiert (nicht `Role::from()` — wirft bei unbekannten Werten)
- [ ] 403 wird für unzureichende Berechtigungen zurückgegeben, 401 für nicht-authentifiziert (nicht beide als 401)
- [ ] `requireRole()` ruft auch `requireAuth()` auf — keine doppelten Auth-Prüfungen nötig
- [ ] `BearerTokenMiddleware`-Ausschluss wird verstanden: ausgeschlossene Pfade umgehen das Claims-Attribut
- [ ] Handler auf ausgeschlossenen Pfaden rufen `requireAuth()` mit manueller Token-Verifizierung auf
- [ ] 204-Antworten verwenden `createEmpty(204)` nicht `create(null, 204)`
- [ ] JWT-Rollen-Caching wird verstanden: Rollenänderungen werden erst nach Token-Ablauf wirksam
