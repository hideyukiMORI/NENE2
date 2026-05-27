# How-to : RBAC + Authentification JWT

> **Référence FT** : FT279 (`NENE2-FT/rbaclog`) — Contrôle d'accès basé sur les rôles avec JWT : hachage de mot de passe Argon2id avec protection contre les attaques de timing, claim de rôle dans JWT, distinction 401 vs 403, BearerTokenMiddleware avec fallback manuel, 14 tests / 48 assertions PASS.
>
> **Évaluation VULN** : V-01 à V-10 inclus à la fin de ce document.

Ce guide montre comment construire un système de contrôle d'accès basé sur les rôles (RBAC) en utilisant des tokens JWT avec NENE2.

## Fonctionnalités

- Connexion email + mot de passe (hachage Argon2id)
- Claim de rôle intégré dans JWT (`user` / `admin`)
- Endpoints publics, authentifiés et réservés admin
- `BearerTokenMiddleware` avec fallback par handler
- Sémantique correcte `401 Unauthorized` vs `403 Forbidden`

## Schéma

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

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/auth/login` | Aucune | Connexion, recevoir JWT |
| `GET` | `/posts` | Aucune | Lister tous les posts (public) |
| `POST` | `/posts` | Utilisateur ou Admin | Créer un post |
| `DELETE` | `/posts/{id}` | Admin uniquement | Supprimer un post |

## Connexion avec protection contre les attaques de timing

L'astuce du hash factice assure que la connexion prend toujours le même temps que l'email existe ou non :

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

Sans le hash factice, une attaque de timing peut détecter des adresses email valides en mesurant le temps de réponse — le calcul de hash est ignoré pour les emails inconnus.

## Claim de rôle dans JWT

Le rôle est stocké dans le payload JWT pour éviter un aller-retour DB à chaque requête :

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## Vérification du rôle avec Enum

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

`Role::tryFrom()` mappe en toute sécurité le claim de chaîne vers l'enum — les chaînes de rôle invalides deviennent `null`, ce qui échoue la vérification.

## Distinction 401 vs 403

| Statut | Signification | Quand |
|--------|---------------|-------|
| `401 Unauthorized` | Non authentifié | Pas de token, token invalide, token expiré |
| `403 Forbidden` | Authentifié mais rôle insuffisant | Token valide, mauvais rôle |

Cette distinction est importante pour les clients : un `401` devrait déclencher une reconnexion ; un `403` devrait afficher un message "accès refusé".

## BearerTokenMiddleware avec Fallback

Certains chemins servent à la fois des méthodes publiques et protégées (ex. `GET /posts` est public, `POST /posts` est authentifié). Le middleware exclut entièrement le chemin, et les handlers qui nécessitent une auth appellent `requireAuth()` manuellement :

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts nécessite une gestion par méthode
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // Chemin rapide : middleware a déjà vérifié
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // Chemin lent : extraction manuelle pour les chemins exclus
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

## Évaluation VULN — Diagnostic de vulnérabilité

### V-01 — Élévation de rôle via claim JWT forgé 🛡️ SAFE

**Menace** : L'attaquant crée un JWT avec `"role": "admin"` et le signe avec un secret aléatoire.
**Défense** : `LocalBearerTokenVerifier` valide la signature HMAC-HS256 contre le secret serveur. Un secret non correspondant cause `TokenVerificationException` → 401.
**Résultat** : SAFE — la vérification de signature prévient la falsification de claim.

---

### V-02 — Attaque de timing via énumération email à la connexion 🛡️ SAFE

**Menace** : L'attaquant envoie des requêtes de connexion pour des emails inconnus vs connus et mesure le temps de réponse pour énumérer les comptes valides.
**Défense** : Pour les emails inconnus, `password_verify()` est appelé contre un hash Argon2id factice (mêmes paramètres de coût). Les deux chemins prennent ~200ms. Le message d'échec de connexion est identique pour mauvais email et mauvais mot de passe.
**Résultat** : SAFE — le timing est équalisé ; le message d'erreur est générique.

---

### V-03 — Token expiré accepté comme valide 🛡️ SAFE

**Menace** : L'attaquant réutilise un JWT capturé après son expiration.
**Défense** : `LocalBearerTokenVerifier` vérifie le claim `exp` contre `time()`. Les tokens expirés lèvent `TokenVerificationException` → 401.
**Résultat** : SAFE — la vérification `exp` est appliquée.

---

### V-04 — Dégradation de rôle en modifiant le payload JWT (sans re-signer) 🛡️ SAFE

**Menace** : L'attaquant décode le payload JWT en base64, change `"role": "user"` en `"role": "admin"`, re-encode et soumet avec la signature originale.
**Défense** : La signature JWT couvre l'en-tête + le payload. Modifier le payload invalide la signature → `TokenVerificationException` → 401.
**Résultat** : SAFE — la falsification du payload est détectée par HMAC.

---

### V-05 — Endpoint admin accessible avec le rôle user 🛡️ SAFE

**Menace** : L'attaquant se connecte en tant qu'`user` et tente `DELETE /posts/{id}`.
**Défense** : `requireRole($request, Role::Admin)` vérifie le claim `role` du JWT. Un token `user` a `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403.
**Résultat** : SAFE — 403 Forbidden retourné ; le token utilisateur ne peut pas s'élever à admin.

---

### V-06 — Accès non authentifié à l'endpoint protégé 🛡️ SAFE

**Menace** : L'attaquant envoie `POST /posts` ou `DELETE /posts/{id}` sans en-tête Authorization.
**Défense** : `requireAuth()` vérifie le préfixe `Bearer ` ; en-tête absent → 401 `unauthorized`.
**Résultat** : SAFE — 401 Unauthorized retourné.

---

### V-07 — Confusion 401 vs 403 (fuite d'information) 🛡️ SAFE

**Menace** : L'utilisation incorrecte 401/403 révèle si une ressource existe ou si l'utilisateur est authentifié.
**Défense** : Le système retourne 401 pour les accès non authentifiés (token absent/invalide) et 403 pour les accès authentifiés avec rôle insuffisant. La distinction est sémantiquement correcte et ne révèle pas l'existence de ressources au-delà de l'exigence de rôle.
**Résultat** : SAFE — sémantique 401/403 correcte ; les tests `test401MeansNotAuthenticated` et `test403MeansAuthenticatedButForbidden` passent tous les deux.

---

### V-08 — Contournement par chaîne de rôle invalide dans JWT 🛡️ SAFE

**Menace** : L'attaquant forge un JWT (avec le secret valide, ex. scénario de secret compromis) et définit `role` à une valeur inconnue comme `"superadmin"`.
**Défense** : `Role::tryFrom((string) ($claims['role'] ?? ''))` retourne `null` pour les chaînes inconnues → `null !== Role::Admin` → 403.
**Résultat** : SAFE — `tryFrom()` est null-safe ; les rôles inconnus sont traités comme insuffisants.

---

### V-09 — Injection SQL via le champ email à la connexion 🛡️ SAFE

**Menace** : L'attaquant envoie `{"email": "' OR '1'='1", "password": "anything"}`.
**Défense** : `findByEmail()` utilise une requête paramétrée (`WHERE email = ?`). La chaîne injectée est traitée comme une valeur littérale, pas du SQL.
**Résultat** : SAFE — les requêtes paramétrées préviennent l'injection SQL.

---

### V-10 — Mot de passe stocké en clair 🛡️ SAFE

**Menace** : Si la DB est compromise, les mots de passe sont lisibles.
**Défense** : `password_hash($password, PASSWORD_ARGON2ID)` avec les paramètres de coût `m=65536,t=4,p=1`. Seul le hash Argon2id est stocké ; le mot de passe en clair n'est jamais persisté.
**Résultat** : SAFE — Argon2id est l'algorithme recommandé actuel (RFC 9106) ; PBKDF2/bcrypt/scrypt passeraient également.

---

### Résumé VULN

| ID | Menace | Résultat |
|----|--------|----------|
| V-01 | Élévation de rôle via JWT forgé | 🛡️ SAFE |
| V-02 | Attaque de timing via énumération email | 🛡️ SAFE |
| V-03 | Token expiré accepté | 🛡️ SAFE |
| V-04 | Falsification du payload JWT sans re-signer | 🛡️ SAFE |
| V-05 | Endpoint admin avec token rôle user | 🛡️ SAFE |
| V-06 | Accès non authentifié à l'endpoint protégé | 🛡️ SAFE |
| V-07 | Confusion 401 vs 403 | 🛡️ SAFE |
| V-08 | Contournement par chaîne de rôle inconnue | 🛡️ SAFE |
| V-09 | Injection SQL via champ email | 🛡️ SAFE |
| V-10 | Mot de passe stocké en clair | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Le hachage Argon2id, JWT signé HMAC, la garde `Role::tryFrom()` et les requêtes paramétrées préviennent tous les vecteurs de vulnérabilité testés.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker le rôle en DB et rechercher à chaque requête | Requête DB supplémentaire par requête ; les changements de rôle nécessitent une logique de révocation de token |
| Utiliser `Role::from()` au lieu de `Role::tryFrom()` | Les chaînes de rôle inconnues lèvent `ValueError` — 500 au lieu de 403 |
| Retourner 403 pour les requêtes non authentifiées | Induit les clients en erreur — 403 devrait signifier "authentifié mais interdit", pas "pas connecté" |
| Retourner 401 pour l'accès avec mauvais rôle | Le client peut tenter une reconnexion au lieu d'afficher "accès refusé" |
| Ignorer le hash factice à la connexion | L'attaque de timing révèle les adresses email valides |
| Stocker les mots de passe en MD5/SHA1/clair | Les attaques par force brute ou rainbow table exposent tous les mots de passe en cas de fuite DB |
| Intégrer les permissions dans JWT (pas les rôles) | Les changements d'ensemble de permissions nécessitent la réémission des tokens ; les rôles sont stables, les permissions changent |
| Autoriser `alg: none` JWT | L'attaquant peut forger des tokens en supprimant entièrement la signature |
| Utiliser `str_contains($role, 'admin')` au lieu de la vérification enum | `"not-admin"` ou `"superadmin"` pourraient correspondre de manière inattendue |
