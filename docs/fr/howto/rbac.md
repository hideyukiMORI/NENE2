# How-to : Contrôle d'accès basé sur les rôles (RBAC)

Implémenter le contrôle d'accès basé sur les rôles avec les claims JWT et `BearerTokenMiddleware`.

---

## Démarrage rapide

```php
// 1. Inclure le rôle dans le JWT à la connexion
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' ou 'admin'
    'exp'  => time() + 3600,
]);

// 2. Vérifier le rôle dans le handler
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## Intégrer les rôles dans les claims JWT

**Deux approches :**

| Approche | Avantage | Inconvénient |
|---|---|---|
| Rôle dans les claims JWT | Pas de requête DB par requête | Les changements de rôle ne prennent effet qu'après l'expiration du token |
| Recherche DB par requête | Changements de rôle immédiats | Requête supplémentaire à chaque requête authentifiée |

Pour la plupart des applications, l'approche JWT est appropriée. Pour les contextes haute sécurité (médical, financier, révocation de privilège admin), ajouter une recherche DB sur les opérations sensibles.

```php
// Connexion — intégrer le rôle dans les claims
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // chaîne : 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized vs 403 Forbidden

Cette distinction est importante pour la gestion des erreurs côté client (401 → rediriger vers la connexion, 403 → afficher l'erreur de permission) :

| Situation | Statut |
|---|---|
| Pas de token / expiré / signature invalide | **401** Unauthorized |
| Token valide mais rôle insuffisant | **403** Forbidden |
| Ressource non trouvée | **404** Not Found |

```php
// ❌ Incorrect — l'utilisateur authentifié reçoit 401 (implique "non connecté")
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ Correct — authentifié mais manque de permission
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## Pattern `requireAuth()` / `requireRole()`

Une paire d'helpers réutilisables dans le registraire de routes :

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... autres dépendances
    ) {}

    /**
     * Retourne les claims en cas de succès ou une ResponseInterface 401.
     * Vérifie d'abord l'attribut middleware ; repli sur la vérification manuelle
     * pour les chemins exclus de BearerTokenMiddleware.
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
                'Authentication required.');
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Token is invalid or expired.');
        }
    }

    /**
     * Retourne les claims si l'utilisateur a le rôle requis, ou une ResponseInterface 401/403.
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
                "This action requires the '{$required->value}' role.");
        }

        return $claims;
    }
}
```

Utilisation dans les handlers :

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 ou 403
    }
    // $claims est maintenant le payload JWT d'un admin vérifié
}
```

---

## `BearerTokenMiddleware` ne différencie pas par méthode HTTP

`BearerTokenMiddleware` utilise le chemin de la requête, pas la méthode HTTP, pour décider si l'authentification est requise. Quand `GET /posts` (public) et `POST /posts` (auth requise) partagent le même chemin, exclure `/posts` du middleware et vérifier le token manuellement dans le handler :

```php
// Middleware : exclure /posts entièrement (couvre à la fois GET et POST)
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// Pour DELETE /posts/{id} (chemin /posts/1, /posts/2 etc.) — PAS dans excludedPaths → middleware le protège.
// Pour POST /posts (chemin /posts) — exclu → le handler doit appeler requireAuth() manuellement.
```

L'helper `requireAuth()` ci-dessus gère cela de manière transparente : il lit `nene2.auth.claims` depuis l'attribut middleware s'il est présent, et se replie sur l'analyse de l'en-tête `Authorization` directement sinon.

**Alternative** : utiliser des préfixes de chemin distincts pour éviter l'ambiguïté entièrement :
- `GET /public/posts` — pas d'auth
- `POST /posts` — auth requise (le middleware peut protéger `/posts` sans conflit)

---

## Pattern enum `Role`

Utiliser un backed enum pour une gestion des rôles avec typage fort :

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() lève une exception sur les valeurs inconnues
$role = Role::from($claims['role']);  // UnhandledMatchError si 'superuser' ou ''

// ✅ Role::tryFrom() retourne null pour les valeurs inconnues
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content — utiliser `createEmpty()`

`JsonResponseFactory::create()` requiert un argument `array`. Pour les réponses 204 sans body, utiliser `createEmpty()` :

```php
// ❌ Erreur de type — create() n'accepte pas null
return $this->json->create(null, 204);

// ❌ Retourne un objet JSON vide {} (le body devrait être absent pour 204)
return $this->json->create([], 204);

// ✅ Correct — pas de body, statut correct
return $this->json->createEmpty(204);
```

---

## Liste de contrôle de revue de code

- [ ] Le claim `role` est décodé avec `Role::tryFrom()` (pas `Role::from()` — lève une exception sur les valeurs inconnues)
- [ ] 403 est retourné pour les permissions insuffisantes, 401 pour non authentifié (pas les deux en 401)
- [ ] `requireRole()` appelle aussi `requireAuth()` — pas de vérifications auth dupliquées nécessaires
- [ ] L'exclusion `BearerTokenMiddleware` est comprise : les chemins exclus contournent l'attribut claims
- [ ] Les handlers sur les chemins exclus appellent `requireAuth()` avec la vérification manuelle de token
- [ ] Les réponses 204 utilisent `createEmpty(204)` pas `create(null, 204)`
- [ ] La mise en cache du rôle JWT est comprise : les changements de rôle ne prennent effet qu'après l'expiration du token
