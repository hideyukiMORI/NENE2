# JWT-Authentifizierung hinzufügen

Diese Anleitung zeigt, wie Sie JWT Bearer-Authentifizierung zu einer NENE2-Anwendung hinzufügen —
Benutzerregistrierung, Login (Token-Ausstellung) und geschützte Endpunkte.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung mit mindestens einer Route.
Falls nicht, beginnen Sie mit [Eine benutzerdefinierte Route hinzufügen](./add-custom-route.md).

---

## Übersicht

NENE2 liefert die benötigten Middleware und Interfaces; Ihre Anwendung stellt den konkreten
JWT-Issuer, die Verifier-Verdrahtung und die Domain-Logik bereit.

| Klasse / Interface | Rolle |
|---|---|
| `TokenIssuerInterface` | Vertrag für die Ausstellung eines signierten JWT |
| `TokenVerifierInterface` | Vertrag für die Verifikation eines JWT und Rückgabe der Claims |
| `LocalBearerTokenVerifier` | Entwicklungs-HS256-Implementierung (beide Interfaces) |
| `BearerTokenMiddleware` | PSR-15-Middleware, die Bearer-Token auf Requests erzwingt |
| `RuntimeApplicationFactory` | Akzeptiert beliebige `MiddlewareInterface` als `$authMiddleware` |

---

## Schritt 1 — Umgebungsvariable setzen

Fügen Sie `NENE2_LOCAL_JWT_SECRET` zu Ihrer `.env` hinzu:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

---

## Schritt 2 — Auth-Domain erstellen

### 2a — User-Entity

```php
final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
    ) {}
}
```

### 2b — Repository-Interface

```php
interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function create(string $email, string $passwordHash): User;
}
```

### 2c — Register-UseCase

```php
final class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        // Validierung, Duplikatprüfung, Passwort-Hashing ...
        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — Login-UseCase

```php
final class LoginUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new ValidationException([
                new ValidationError('credentials', 'Invalid email or password.', 'invalid_credentials'),
            ]);
        }

        return ['token' => $this->issuer->issue(['sub' => (string) $user->id])];
    }
}
```

---

## Schritt 3 — Bearer-Middleware einbinden

Verwenden Sie `$excludedPaths`, um alle Routen **außer** den öffentlichen Auth-Endpunkten zu schützen:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier:       $verifier,
    excludedPaths:  ['/auth/register', '/auth/login'],
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [$authRegistrar, $taskRegistrar],
    authMiddleware:  $authMiddleware,
))->create();
```

---

## Schritt 4 — Claims im Handler lesen

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

---

## Schritt 5 — 403 für fremde Ressourcen zurückgeben

Eigentumsüberprüfungen gehören in den UseCase, nicht in die Middleware:

```php
if ($task->userId !== $requestingUserId) {
    throw new AccessDeniedException();
}
```

Bilden Sie `AccessDeniedException` über einen `DomainExceptionHandlerInterface` auf 403 ab.

---

## Für die Produktion: JWT-Bibliothek verwenden

Implementieren Sie `TokenVerifierInterface` und `TokenIssuerInterface` mit einer JWT-Bibliothek
wie `firebase/php-jwt` und injizieren Sie diese statt `LocalBearerTokenVerifier`.

---

## Weiterführende Links

- [ADR 0008 — JWT-Authentifizierung](../adr/0008-jwt-authentication.md)
- [Rate-Limiting hinzufügen](./add-rate-limiting.md)
- Englische Vollversion: [add-jwt-authentication.md](../../howto/add-jwt-authentication.md)
