# How-to : Authentification JWT

> **Référence FT** : FT261 (`NENE2-FT/jwtlog`) — Authentification JWT avec hachage de mot de passe Argon2id et BearerTokenMiddleware
> **VULN** : FT261 — évaluation des vulnérabilités (V-01 à V-10)

Émission et vérification de tokens Bearer JWT avec `LocalBearerTokenVerifier` et `BearerTokenMiddleware`.

---

## Démarrage rapide

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// Protéger tous les chemins sauf /auth/login
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## Émission de tokens

`LocalBearerTokenVerifier` implémente à la fois `TokenIssuerInterface` et `TokenVerifierInterface` — une seule instance gère les deux.

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // sujet : identifiant utilisateur (int ou string)
    'email' => $user->email,    // claim personnalisée
    'iat'   => $now,            // émis-à (timestamp Unix — int)
    'exp'   => $now + 3600,     // expiry   (timestamp Unix — int, requis pour que l'expiry fonctionne)
]);
```

**`exp` doit être un timestamp Unix (int).** Passer une chaîne de date (`'2026-06-01'`) ignore silencieusement l'application de l'expiry car `LocalBearerTokenVerifier` vérifie `is_int($claims['exp'])` avant de comparer.

---

## Lecture des claims dans un gestionnaire

`BearerTokenMiddleware` stocke les claims décodées dans l'attribut de requête `nene2.auth.claims` après vérification réussie :

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // Cette garde null ne devrait pas se déclencher — le middleware a déjà rejeté les tokens manquants.
    // L'inclure quand même pour PHPStan level 8 et la clarté défensive.
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

Aussi disponible : `$request->getAttribute('nene2.auth.credential_type')` retourne `'bearer'`.

---

## Modes de protection de chemin

`BearerTokenMiddleware` supporte trois modes — la première configuration non vide gagne :

| Configuration | Comportement | Quand utiliser |
|---|---|---|
| `protectedPaths: ['/me', '/admin']` | Seuls les chemins exacts listés sont protégés | Les chemins publics sont la majorité |
| `protectedPathPrefixes: ['/api/']` | Les chemins commençant par le préfixe sont protégés | Protection d'un sous-arbre entier |
| `excludedPaths: ['/login', '/register']` | Tous les chemins sauf ceux listés sont protégés | Les chemins publics sont la minorité |
| (défaut — tous les tableaux vides) | Chaque chemin est protégé | API entièrement privée |

```php
// ✅ /auth/login est public, tout le reste nécessite un token
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ Seul /auth/me est protégé
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ Tous les chemins /api/ sont protégés
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] n'est PAS "protéger rien" — cela désactive le mode allowlist
//     et passe au mode suivant (préfixes, puis blocklist, puis protect-all).
```

---

## Attaque `alg: none` — déjà rejetée

`LocalBearerTokenVerifier` vérifie que `alg == 'HS256'` dans l'en-tête du token avant de vérifier la signature. Tout autre algorithme — incluant `none` — lève `TokenVerificationException` :

```
Token algorithm must be HS256.
```

Cela prévient le contournement classique `alg: none` où un attaquant crée un token sans en-tête avec aucune signature. Lors de l'implémentation d'un vérificateur personnalisé, toujours appliquer explicitement l'algorithme attendu.

---

## Réponses d'erreur

`BearerTokenMiddleware` retourne 401 Problem Details et ajoute automatiquement l'en-tête `WWW-Authenticate` (RFC 6750) :

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

Valeurs `error` possibles : `missing_token` (pas d'en-tête), `invalid_token` (mauvais schéma, mauvaise signature, expiré, `nbf` dans le futur, malformé).

---

## Gestion du secret

Ne jamais coder en dur le secret JWT. Le lire depuis une variable d'environnement :

```php
// ❌ Secret codé en dur — commité dans le contrôle de version
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ Variable d'environnement
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

Utiliser un secret aléatoire fort dans tous les environnements. En production, utiliser une implémentation supportée par une bibliothèque (`firebase/php-jwt`, `lcobucci/jwt`) au lieu de `LocalBearerTokenVerifier` — le préfixe "Local" signale sa portée.

---

## Révocation de token

JWT est sans état — il n'y a pas de révocation intégrée. Les tokens restent valides jusqu'à `exp`. Si vous avez besoin d'une révocation immédiate (ex: déconnexion, changement de mot de passe) :

- Stocker une liste de blocage de tokens dans Redis avec TTL correspondant à `exp`
- Ou utiliser des tokens de courte durée (15 minutes) avec des tokens de rafraîchissement

---

## Nom de paramètre `authMiddleware`

Le paramètre nommé `RuntimeApplicationFactory` est `authMiddleware:`, pas `middlewares:` ni `middleware:` :

```php
// ❌ Paramètre nommé inconnu $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ Correct
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## Checklist de revue de code

- [ ] La claim `exp` est un timestamp Unix (int), pas une chaîne de date
- [ ] Le secret JWT est lu depuis une variable d'environnement (pas codé en dur)
- [ ] `LocalBearerTokenVerifier` n'est pas utilisé en production (utiliser une implémentation de bibliothèque)
- [ ] L'attribut `nene2.auth.claims` est vérifié null avant utilisation
- [ ] Le choix du mode `excludedPaths` / `protectedPaths` correspond à l'intention
- [ ] La réponse de token ne contient pas `password_hash` ni d'autres secrets
- [ ] L'en-tête `Authorization` n'est pas enregistré dans les logs
- [ ] 401 est retourné pour les échecs d'auth (pas 404)

---

## Protection contre les attaques temporelles : hash factice pour l'énumération d'utilisateurs

Quand un email n'est pas trouvé, `$user === null`. Sans hash factice, le code sauterait entièrement `password_verify()` — rendant la réponse notablement plus rapide pour les emails inconnus.

```php
$user = $this->repo->findByEmail(trim($body['email']));

// Toujours exécuter password_verify — prévient l'énumération d'utilisateurs basée sur le timing.
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  L'ordre est important : password_verify() AVANT || $user === null
// L'évaluation court-circuitée sauterait password_verify() si $user était vérifié en premier.
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // même erreur peu importe si l'email est inconnu ou si le mot de passe est incorrect
}
```

---

## VULN — Évaluation des vulnérabilités (FT261)

### V-01 — Pas de protection contre le brute-force sur le login

**Risque** : `POST /auth/login` n'a pas de rate limiting.

**Impact** : Un attaquant peut soumettre des tentatives de login illimitées. Argon2id est intentionnellement lent (~100ms), mais sans rate limiting, des requêtes distribuées peuvent quand même essayer des milliers de mots de passe.

**Verdict** : **EXPOSED** — ajouter `ThrottleMiddleware` sur `POST /auth/login` (ex: 5 req/min/IP). Retourner 429 avec `Retry-After`.

---

### V-02 — La force du secret JWT dépend de l'environnement

**Risque** : Si `NENE2_LOCAL_JWT_SECRET` est vide ou faible (`secret`, `test`), les tokens HMAC-HS256 peuvent être brute-forcés ou devinés. Un token falsifié avec des claims admin serait accepté.

**Verdict** : **EXPOSED** — vérification de démarrage à défaut fermé :
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — Pas de révocation de token

**Risque** : Les JWT émis restent valides jusqu'à `exp`. Les tokens volés, ou les tokens appartenant à des utilisateurs supprimés, restent acceptés pendant jusqu'à 1 heure.

**Verdict** : **EXPOSED** — implémenter une liste de blocage de tokens (ex: `revoked_tokens(jti TEXT PK, revoked_at TEXT)`) ou utiliser des tokens de courte durée (15 min) avec des tokens de rafraîchissement.

---

### V-04 — Pas d'endpoint d'inscription

**Risque** : Pas de route `POST /auth/register` n'existe. Les utilisateurs de test nécessitent une insertion directe en DB, contournant la politique de hachage de mot de passe appliquée par l'application.

**Verdict** : **LACUNE DE CONCEPTION** — ajouter `POST /auth/register` avec validation d'email et hachage Argon2id.

---

### V-05 — Sensibilité à la casse de l'email : pas de normalisation

**Risque** : `WHERE email = ?` est sensible à la casse. `USER@EXAMPLE.COM` et `user@example.com` sont des recherches différentes. Deux comptes avec des casses différentes peuvent coexister.

**Verdict** : **EXPOSED** — normaliser l'email en minuscules (`strtolower()`) à l'inscription et au login.

---

### V-06 — TTL du token : 1 heure peut être trop long pour les APIs sensibles

**Risque** : `TOKEN_TTL_SECONDS = 3600`. Les tokens volés restent valides jusqu'à une heure.

**Verdict** : **CONSIDÉRATION DE CONCEPTION** — 1 heure est acceptable pour la plupart des APIs. Pour les opérations sensibles, utiliser des TTL plus courts (5–15 min) avec des tokens de rafraîchissement. Rendre le TTL configurable.

---

### V-07 — `password_hash` n'est pas dans les claims JWT ✅ SAFE

**Risque** : L'appel `issue()` inclut seulement `sub`, `email`, `iat`, `exp`.

**Verdict** : **SAFE** — les claims sont minimaux. Même si un token est décodé (base64, non chiffré), aucune donnée interne sensible n'est exposée.

---

### V-08 — Injection SQL via email 🚫 BLOCKED

**Attaque** : `{"email": "' OR '1'='1", "password": "x"}`

**Observé** : `WHERE email = ?` est une requête paramétrée. L'injection est traitée comme une chaîne littérale. Aucun utilisateur n'est trouvé ; 401 est retourné.

**Verdict** : **BLOCKED** — les requêtes paramétrées préviennent l'injection SQL.

---

### V-09 — Pas de validation du format email

**Risque** : N'importe quelle chaîne non vide est acceptée comme email (ex: `"not-an-email"`).

**Impact** : Calcul Argon2id gaspillé ; utilisateurs invalides en DB ; flux de réinitialisation de mot de passe cassés.

**Verdict** : **EXPOSED** — ajouter `filter_var($email, FILTER_VALIDATE_EMAIL)` à l'inscription et au login.

---

### V-10 — Pas d'application de HTTPS

**Risque** : Les tokens JWT et les mots de passe sont transmis en clair sur HTTP.

**Verdict** : **EXPOSED** — appliquer HTTPS en production. Ajouter l'en-tête `Strict-Transport-Security` via `SecurityHeadersMiddleware`.

---

## Résumé VULN

| # | Vulnérabilité | Verdict |
|---|---------------|---------|
| V-01 | Pas de protection brute-force | EXPOSED |
| V-02 | Force du secret JWT (dépend de l'env) | EXPOSED |
| V-03 | Pas de révocation de token | EXPOSED |
| V-04 | Pas d'endpoint d'inscription | LACUNE DE CONCEPTION |
| V-05 | Sensibilité casse email / pas de normalisation | EXPOSED |
| V-06 | TTL token 1 heure | CONSIDÉRATION DE CONCEPTION |
| V-07 | password_hash pas dans les claims JWT | SAFE |
| V-08 | Injection SQL via email | BLOCKED |
| V-09 | Pas de validation format email | EXPOSED |
| V-10 | Pas d'application de HTTPS | EXPOSED |

**Corrections critiques avant production** :
1. **V-01** — `ThrottleMiddleware` sur `POST /auth/login` (5 req/min/IP)
2. **V-02** — Validation du secret JWT à défaut fermé au démarrage (`strlen >= 32`)
3. **V-03** — Liste de révocation de tokens ou TTL court + tokens de rafraîchissement
4. **V-05** — Normaliser l'email en minuscules à l'inscription et au login
5. **V-09** — `filter_var($email, FILTER_VALIDATE_EMAIL)` à l'inscription

---

## How-tos associés

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — verrouillage brute-force pour la vérification PIN
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — middleware de rate limiting
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + comparaison sûre temporellement
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelist explicite DTO
