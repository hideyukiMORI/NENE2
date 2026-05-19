# Ajouter l'authentification JWT

Ce guide montre comment ajouter l'authentification JWT Bearer à une application NENE2 —
inscription des utilisateurs, connexion (émission de token) et endpoints protégés.

**Prérequis** : Vous avez une application NENE2 fonctionnelle avec au moins une route.
Sinon, commencez par [Ajouter une route personnalisée](./add-custom-route.md).

---

## Vue d'ensemble

NENE2 fournit le middleware et les interfaces nécessaires ; votre application fournit
l'implémentation concrète du JWT issuer, le câblage du verifier et la logique métier.

| Classe / Interface | Rôle |
|---|---|
| `TokenIssuerInterface` | Contrat pour émettre un JWT signé |
| `TokenVerifierInterface` | Contrat pour vérifier un JWT et retourner les claims |
| `LocalBearerTokenVerifier` | Implémentation HS256 de développement (les deux interfaces) |
| `BearerTokenMiddleware` | Middleware PSR-15 qui enforce le Bearer token sur les requêtes |
| `RuntimeApplicationFactory` | Accepte tout `MiddlewareInterface` en tant que `$authMiddleware` |

---

## Étape 1 — Configurer la variable d'environnement

Ajoutez `NENE2_LOCAL_JWT_SECRET` à votre `.env` :

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

---

## Étape 2 — Créer le domaine Auth

### 2a — Entité User

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

### 2b — Interface du repository

```php
interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function create(string $email, string $passwordHash): User;
}
```

### 2c — UseCase Register

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
        // Validation, vérification des doublons, hachage du mot de passe ...
        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — UseCase Login

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

## Étape 3 — Intégrer le middleware Bearer

Utilisez `$excludedPaths` pour protéger toutes les routes **sauf** les endpoints publics :

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

## Étape 4 — Lire les claims dans un handler

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

---

## Étape 5 — Retourner 403 pour les ressources d'autres utilisateurs

Les vérifications de propriété appartiennent au UseCase, pas au middleware :

```php
if ($task->userId !== $requestingUserId) {
    throw new AccessDeniedException();
}
```

Mappez `AccessDeniedException` vers 403 via un `DomainExceptionHandlerInterface`.

---

## Pour la production : utiliser une bibliothèque JWT

Implémentez `TokenVerifierInterface` et `TokenIssuerInterface` avec une bibliothèque JWT
comme `firebase/php-jwt` et injectez-la à la place de `LocalBearerTokenVerifier`.

---

## Références

- [ADR 0008 — Authentification JWT](../adr/0008-jwt-authentication.md)
- [Ajouter la limitation de débit](./add-rate-limiting.md)
- Version complète en anglais : [add-jwt-authentication.md](../../howto/add-jwt-authentication.md)
