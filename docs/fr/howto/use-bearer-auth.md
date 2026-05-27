# Comment utiliser l'authentification par token Bearer

NENE2 fournit `BearerTokenMiddleware` et `LocalBearerTokenVerifier` pour l'authentification basée sur JWT. Ce guide couvre la configuration, la mise en place, l'émission de tokens, et les pièges courants.

## Configuration

Câbler le middleware dans `RuntimeApplicationFactory` en utilisant le paramètre nommé `authMiddleware` :

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: 'change-me';
$verifier = new LocalBearerTokenVerifier($secret);
$bearer   = new BearerTokenMiddleware($problemDetails, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearer, // ← le paramètre nommé est authMiddleware, pas middlewares
))->create();
```

> **Note :** Le nom du paramètre est `authMiddleware`, pas `middlewares`. Utiliser `middlewares:` cause une `Error: Unknown named parameter` à l'exécution.

## Protéger toutes les routes vs. protection sélective

`BearerTokenMiddleware` supporte quatre modes de correspondance de chemin (la première correspondance gagne) :

```php
// 1. Protéger uniquement des chemins spécifiques (liste blanche)
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. Protéger les chemins commençant par un préfixe (liste blanche de préfixes)
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. Protéger tout SAUF les chemins listés (liste noire — pattern courant)
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. Protéger tous les chemins (défaut — aucun tableau fourni)
new BearerTokenMiddleware($problems, $verifier);
```

## Lire les claims dans un handler

Après une vérification réussie, les claims sont stockés comme attribut de requête :

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

Le type d'identifiant est stocké séparément :

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## Émettre des tokens (local / test)

`LocalBearerTokenVerifier` implémente également `TokenIssuerInterface` :

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← toujours inclure exp
]);
```

> **Toujours inclure `exp`.** Les tokens sans `exp` sont traités comme non-expirables. C'est sûr pour les tests mais dangereux si de tels tokens atteignent la production. Si `exp` est absent, le vérificateur ignore la vérification d'expiration.

## Réponses d'erreur

En cas d'échec, `BearerTokenMiddleware` retourne une réponse Problem Details `401 Unauthorized` avec un en-tête `WWW-Authenticate` :

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

Codes d'erreur dans `WWW-Authenticate` :
- `missing_token` — pas d'en-tête `Authorization`
- `invalid_token` — schéma incorrect, expiré, signature invalide, malformé, mauvais algorithme, `nbf` dans le futur

## Propriétés de sécurité de `LocalBearerTokenVerifier`

| Menace | Protection |
|--------|-----------|
| Falsification de signature | HMAC-HS256, `hash_equals` en temps constant |
| Substitution d'algorithme (`alg:none`) | Seul `HS256` accepté |
| Token expiré | Claim `exp` vérifié |
| Token pas encore valide | Claim `nbf` vérifié |
| Payload altéré | La signature couvre l'en-tête + le payload ; l'altération casse la signature |

> `LocalBearerTokenVerifier` est conçu pour le développement local et les tests. En production, injecter une implémentation de `TokenVerifierInterface` basée sur une bibliothèque (ex. firebase/php-jwt) qui supporte la rotation de clés et les algorithmes asymétriques.

## Patterns de test

```php
// Dans setUp() : créer le vérificateur avec un secret de test
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// Émettre un token valide pour un utilisateur
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// Émettre un token expiré (pour un test négatif)
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// Émettre un token pas encore valide
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
