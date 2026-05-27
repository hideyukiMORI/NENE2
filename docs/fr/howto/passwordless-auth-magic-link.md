# Authentification sans mot de passe (Magic Link)

Guide d'implémentation de l'authentification sans mot de passe (Magic Link). Explique les patterns de conception sécurisés pour un système de lien à usage unique permettant l'authentification avec une seule adresse email.

## Vue d'ensemble

L'authentification Magic Link fonctionne selon le flux suivant :

1. L'utilisateur soumet son adresse email
2. Le serveur génère un token à usage unique (Magic Link) et l'envoie par email
3. L'utilisateur soumet le token pour obtenir un token de session
4. Le token de session est utilisé pour accéder à l'API

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/auth/request` | Présentation de l'email → génération du Magic Link (toujours 202) |
| `POST` | `/auth/verify` | Vérification du token Magic Link → émission du token de session |
| `POST` | `/auth/logout` | Invalidation de la session (toujours 204) |
| `GET` | `/me` | Obtenir les informations de l'utilisateur authentifié |

## Conception de la base de données

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,  -- stockage du hash SHA-256 (valeur brute non stockée)
    expires_at TEXT NOT NULL,          -- expiration après 15 minutes
    used_at TEXT,                      -- usage unique (NULL = non utilisé)
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- stockage du hash SHA-256
    expires_at TEXT NOT NULL,                  -- expiration après 24 heures
    revoked_at TEXT,                           -- défini lors de la déconnexion
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Conception sécurisée

### Token stocké avec hash SHA-256

```php
// Génération : aléatoire 256 bits → chaîne hex (64 caractères)
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// Seul tokenHash est stocké en DB
// Seul rawToken est envoyé par email (sécurité en cas de fuite DB)
```

Même si la DB fuite, le token ne peut pas être reconstitué depuis le hash. Même principe que le hachage de mot de passe.

### Prévention de l'énumération d'utilisateurs

```php
// POST /auth/request retourne toujours 202
// La réponse ne change pas selon email enregistré / non enregistré
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

L'attaquant ne peut pas vérifier la validité d'une adresse email.

### La vérification d'expiration avant used_at

```php
// Vérifier l'expiration en premier
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// Ensuite vérifier used_at
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

Empêche de savoir si un token expiré a été "utilisé" (prévention de fuite d'information de timing).

### Usage unique (prévention d'attaque par replay)

```php
// Définir used_at immédiatement lors de la vérification réussie
$this->repository->markMagicLinkUsed($linkId, $now);
```

Impossible d'utiliser le même Magic Link deux fois. Prévient la réutilisation d'un lien intercepté.

### Invalidation de session (déconnexion)

```php
// logout retourne toujours 204 — ne révèle pas l'existence de la session
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

`/me` retourne 401 si `revoked_at !== null`.

## Flux de validation de session

```php
private function handleMe(ServerRequestInterface $request): ResponseInterface
{
    $rawToken = $this->extractBearerToken($request);
    if ($rawToken === '') {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }

    $tokenHash = hash('sha256', $rawToken);
    $session = $this->repository->findSessionByTokenHash($tokenHash);

    if ($session === null) { return 401; }
    if ($session['revoked_at'] !== null) { return 401 révoqué; }
    if ($now > $session['expires_at']) { return 401 expiré; }

    // ...
}
```

## Extraction du token Bearer

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

Ne pas utiliser l'en-tête `X-User-Id` pour l'authentification. Utiliser uniquement `Authorization: Bearer <token>`.

## Création automatique de nouvel utilisateur

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    return (int) $this->executor->lastInsertId();
}
```

L'utilisateur est créé automatiquement à la première connexion. Caractéristique de l'authentification sans mot de passe.

## Expiration du Magic Link

- **Magic Link** : 15 minutes (900 secondes) — délai pour ouvrir l'email et cliquer
- **Token de session** : 24 heures (86400 secondes) — session API normale

```php
$expiresAt = date('c', time() + 900);    // magic link : 15 min
$sessionExpiresAt = date('c', time() + 86400);  // session : 24h
```

## Considérations pour la production

- **Envoi d'email** : Ce FT inclut `token` dans la réponse (pour les tests). En production, envoyer par SMTP à l'adresse email de l'utilisateur et retirer du corps de la réponse.
- **Rate limiting** : Limiter les requêtes sur `/auth/request` par IP / email.
- **Invalidation des anciens liens non utilisés** : Quand `/auth/request` est appelé plusieurs fois avec le même email, envisager d'invalider explicitement les anciens liens non utilisés.
- **HTTPS obligatoire** : Le token Magic Link étant inclus dans les paramètres URL, HTTPS est obligatoire (prévention des attaques de l'homme du milieu).
