# How-to : Authentification par magic link

> **Référence FT** : FT309 (`NENE2-FT/magiclog`) — Authentification passwordless par magic link : token stocké comme hash SHA-256 (jamais en clair), TTL 15 minutes, `used_at` empêche la réutilisation, expiration vérifiée avant `used_at`, session token 64+ caractères hex stocké en SHA-256, sessions révoquées/expirées refusées, 202 toujours sur /auth/request (prévention de l'énumération d'utilisateurs), Bearer token requis (en-tête X-User-Id ignoré), VULN-A〜L tous SAFE, 43 tests / 91 assertions PASS.

Ce guide montre comment construire un système d'authentification passwordless par magic link où la sécurité repose sur l'entropie du token, le stockage par hash, un TTL court et l'application à usage unique.

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- now + 15 minutes
    used_at    TEXT,                      -- défini à la première vérification réussie
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`magic_links.token_hash` et `auth_sessions.session_token_hash` stockent tous les deux des hashes SHA-256. Les tokens bruts ne sont jamais stockés.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/auth/request` | — | Demander un magic link (toujours 202) |
| `POST` | `/auth/verify` | — | Vérifier le token → session |
| `POST` | `/auth/logout` | `Bearer` | Révoquer la session |
| `GET` | `/me` | `Bearer` | Obtenir l'utilisateur courant |

## Génération et hachage du token

```php
// Générer 64 caractères hex (256 bits d'entropie)
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// Retourner le token brut à l'appelant (envoyé par email à l'utilisateur)
return ['token' => $rawToken];
```

Le token brut est retourné dans la réponse (pour être envoyé comme paramètre d'URL dans l'email). Seul le hash SHA-256 est stocké. `UNIQUE(token_hash)` prévient les collisions de hash.

## Token de session

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 caractères hex
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // retourné une fois, puis hash uniquement
```

Token de session : 64 caractères hexadécimaux = 256 bits d'entropie. Stocké comme hash SHA-256. Minimum 64 caractères appliqué par la source d'entropie (`bin2hex(random_bytes(32))`).

## Vérification — L'ordre des contrôles est important

```php
// 1. Rechercher par hash
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // non trouvé
}

// 2. Vérifier l'expiration EN PREMIER
if ($magicLink['expires_at'] < date('c')) {
    return 401; // 'expired' dans le message d'erreur
}

// 3. Vérifier used_at EN SECOND
if ($magicLink['used_at'] !== null) {
    return 401; // 'already been used' dans le message d'erreur
}

// 4. Marquer comme utilisé
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. Créer la session
```

L'expiration est vérifiée **avant** `used_at`. Si un token est à la fois expiré et utilisé, l'erreur dit "expired" — pas "already been used". Cela prévient les attaques temporelles où un attaquant sonde si un token a été utilisé.

## Prévention de l'énumération d'utilisateurs — Toujours 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // Créer un magic link et (en production) envoyer l'email
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // Toujours retourner 202 peu importe si l'email existe
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

Les adresses email inexistantes retournent la même réponse 202 que les valides. Aucun message "email not found" n'est jamais retourné.

## Validation de session

```php
$token = substr($authHeader, 7); // supprimer 'Bearer '
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // session non trouvée
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

Trois contrôles de session : existence → révoquée → expirée. Les sessions révoquées après déconnexion retournent "revoked" — distinct de "expired" pour des messages d'erreur clairs.

## En-tête X-User-Id ignoré pour l'auth

L'endpoint `/me` nécessite un token de session `Bearer` valide. L'en-tête `X-User-Id` (utilisé par d'autres endpoints comme auth pratique) est explicitement ignoré ici :

```php
// Authentification Bearer uniquement — X-User-Id n'est pas accepté
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## Évaluation des vulnérabilités

### V-01 — Token expiré rejeté avant vérification used ✅ SAFE

**Risque** : Token expiré mais pas encore utilisé ; un attaquant essaie de l'utiliser et obtient l'erreur "already used" révélant l'ordre des contrôles.
**Résultat** : SAFE — l'expiration est vérifiée en premier. Les tokens expirés+utilisés retournent "expired".

---

### V-02 — Token de session stocké comme hash ✅ SAFE

**Risque** : Une fuite DB révèle les tokens de session.
**Résultat** : SAFE — `session_token_hash = SHA-256(raw_token)`. Token brut absent de la DB.

---

### V-03 — Magic link utilisé ne peut pas être réutilisé ✅ SAFE

**Risque** : Un attaquant capture l'URL du magic link et l'utilise après l'utilisateur légitime.
**Résultat** : SAFE — `used_at` est défini à la première utilisation ; la deuxième tentative retourne 401 "already been used".

---

### V-04 — La déconnexion invalide la session ✅ SAFE

**Risque** : Le cookie/token de session fonctionne encore après déconnexion.
**Résultat** : SAFE — la déconnexion définit `revoked_at` ; les appels suivants à `/me` avec ce token retournent 401 "revoked".

---

### V-05 — Email inexistant retourne 202 ✅ SAFE

**Risque** : Un attaquant vérifie quels emails sont enregistrés en observant des réponses d'erreur différentes.
**Résultat** : SAFE — `/auth/request` retourne toujours 202 avec le même corps. Aucune fuite "not found".

---

### V-06 — Session révoquée refusée ✅ SAFE

**Risque** : Une session révoquée manuellement accorde encore l'accès.
**Résultat** : SAFE — la vérification de `revoked_at` refuse l'accès ; le message d'erreur dit "revoked".

---

### V-07 — Session expirée refusée ✅ SAFE

**Risque** : Une ancienne session d'il y a longtemps fonctionne encore.
**Résultat** : SAFE — la vérification de `expires_at` refuse l'accès ; le message d'erreur dit "expired".

---

### V-08 — Token de magic link stocké comme hash ✅ SAFE

**Risque** : Une fuite DB révèle les tokens de magic link ; un attaquant s'authentifie comme n'importe quel utilisateur.
**Résultat** : SAFE — `token_hash = SHA-256(raw_token)`. Token brut absent de la DB.

---

### V-09 — Magic link expire dans 15 minutes ✅ SAFE

**Risque** : Un magic link longtemps valide permet une interception et un replay différés.
**Résultat** : SAFE — TTL ≤ 900 secondes (15 minutes) confirmé par test.

---

### V-10 — La session a une expiration ✅ SAFE

**Risque** : La session n'expire jamais ; les anciens tokens restent valides indéfiniment.
**Résultat** : SAFE — `expires_at` est défini dans le futur à la création de session ; confirmé non-null.

---

### V-11 — Le token de session a une entropie suffisante ✅ SAFE

**Risque** : Token de session court, sujet au brute-force.
**Résultat** : SAFE — `bin2hex(random_bytes(32))` = 64 caractères hex = 256 bits d'entropie.

---

### V-12 — L'en-tête X-User-Id ne peut pas contourner l'auth ✅ SAFE

**Risque** : L'en-tête `X-User-Id: 1` accorde l'accès à `/me` sans session valide.
**Résultat** : SAFE — `/me` nécessite `Authorization: Bearer <token>`. X-User-Id est ignoré.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|----------|
| V-01 | Ordre expiration vs vérification used | ✅ SAFE |
| V-02 | Token de session en clair en DB | ✅ SAFE |
| V-03 | Réutilisation du magic link utilisé | ✅ SAFE |
| V-04 | Session valide après déconnexion | ✅ SAFE |
| V-05 | Énumération d'emails | ✅ SAFE |
| V-06 | Accès session révoquée | ✅ SAFE |
| V-07 | Accès session expirée | ✅ SAFE |
| V-08 | Token magic link en DB | ✅ SAFE |
| V-09 | TTL magic link > 15 min | ✅ SAFE |
| V-10 | Pas d'expiration de session | ✅ SAFE |
| V-11 | Faible entropie token de session | ✅ SAFE |
| V-12 | Contournement X-User-Id | ✅ SAFE |

**12 SAFE, 0 EXPOSED**

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker le token magic link brut en DB | Une fuite DB permet à un attaquant de s'authentifier comme n'importe quel utilisateur |
| Stocker le token de session brut en DB | Une fuite DB invalide toutes les sessions |
| Vérifier used_at avant expires_at | Fuite temporelle révélant si le token a été utilisé |
| Retourner une erreur pour un email inexistant | Un attaquant énumère les emails enregistrés |
| Pas de TTL sur les magic links | Tokens valides indéfiniment ; attaque par interception différée |
| Pas d'expiration de session | Les sessions restent valides indéfiniment |
| Accepter X-User-Id pour l'auth Bearer | Contournement d'auth par en-tête sans token |
| Tokens à faible entropie (`rand()` ou 8 caractères) | Tokens brute-forçables |
| Réutiliser le même magic link pour plusieurs sessions | L'exposition d'un seul token accorde toutes les sessions suivantes |
