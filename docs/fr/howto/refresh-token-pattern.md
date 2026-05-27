# How-to : Pattern de token de rafraîchissement

> **Référence FT** : FT281 (`NENE2-FT/refreshlog`) — Pattern de token de rafraîchissement : token d'accès de courte durée (JWT 5 min) + token de rafraîchissement de longue durée (7 jours), stockage de hash SHA-256, rotation de token à l'utilisation, détection d'attaque par rejeu (token révoqué → révoquer tout), déconnexion retourne toujours 204, 15 tests / 63 assertions PASS.

Ce guide montre comment implémenter le pattern de token de rafraîchissement — des tokens d'accès de courte durée pour la sécurité, des tokens de rafraîchissement pour la continuité de session.

## Pourquoi c'est important

Les JWTs sont sans état. Une fois émis, ils ne peuvent pas être révoqués jusqu'à leur expiration. Une TTL de 5 minutes limite l'exposition si un token est volé. Les tokens de rafraîchissement étendent les sessions sans invites de mot de passe répétées, et peuvent être tournés (révoqués et réémis) à chaque utilisation pour détecter les vols.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` stocke le SHA-256 du token brut — jamais la valeur brute. `revoked` est un flag de suppression douce (vs suppression physique pour la détection de rejeu).

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/auth/login` | Aucune | Email + mot de passe → token d'accès + token de rafraîchissement |
| `POST` | `/auth/refresh` | Token de rafraîchissement dans le body | Tourner le token de rafraîchissement, émettre une nouvelle paire |
| `POST` | `/auth/logout` | Token de rafraîchissement dans le body | Révoquer le token de rafraîchissement |
| `GET` | `/auth/me` | Token d'accès Bearer | Obtenir les informations de l'utilisateur actuel |

## Durées de vie des tokens

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 minutes — courte pour la sécurité
// Tokens de rafraîchissement : 7 jours (RefreshTokenRepository::TTL_DAYS)
```

Les tokens d'accès courts limitent l'exposition si volés. Les tokens de rafraîchissement longs permettent aux utilisateurs de rester connectés entre les sessions sans ressaisir les mots de passe.

## Émission de la paire de tokens

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // ID de token unique — permet le suivi de révocation futur
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## Stocker uniquement le hash

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 chars hex = 256 bits d'entropie
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← retourner la valeur brute au client ; jamais stockée
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

Si la DB est compromise, les attaquants obtiennent des hashes — inutiles sans les tokens bruts détenus par les clients.

## Rotation de token

```php
// Lors d'un /auth/refresh réussi :
$this->refreshTokens->revoke($stored->id);      // révoquer l'ancien
return $this->json->create($this->issueTokenPair($user));  // émettre une nouvelle paire
```

Chaque rafraîchissement tourne le token. L'ancien token devient immédiatement invalide, donc un token de rafraîchissement volé ne peut être utilisé qu'une seule fois avant que la rotation l'invalide.

## Détection d'attaque par rejeu

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // Un token révoqué étant réutilisé → attaque par rejeu potentielle
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

Si un attaquant vole un token de rafraîchissement, l'utilise, et le client légitime essaie ensuite de l'utiliser (maintenant révoqué) — le système détecte cela et révoque toutes les sessions de l'utilisateur, forçant une re-authentification.

## La déconnexion retourne toujours 204

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Toujours 204 — ne jamais révéler si le token était valide
    return $this->json->createEmpty(204);
}
```

Retourner 401 pour un token déjà révoqué à la déconnexion permettrait à un attaquant de sonder s'il a été déconnecté.

## Vérification de validité du token

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

La révocation et l'expiration sont toutes deux vérifiées. Les tokens expirés mais non révoqués sont également rejetés.

## Résumé des propriétés de sécurité

| Propriété | Implémentation |
|---|---|
| TTL token d'accès | 5 minutes (minimiser l'exposition en cas de vol) |
| TTL token de rafraîchissement | 7 jours (continuité de session) |
| Stockage des tokens | Hash SHA-256 uniquement ; valeur brute jamais stockée |
| Rotation de token | Ancien token révoqué à chaque rafraîchissement réussi |
| Détection de rejeu | Réutilisation de token révoqué → révoquer toutes les sessions utilisateur |
| Déconnexion | Toujours 204 (ne jamais fuiter la validité du token) |
| Claim `jti` | Unique par token (suivi de révocation futur) |

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker le token de rafraîchissement brut en DB | Une fuite DB expose toutes les sessions actives |
| Utiliser la suppression physique à la révocation | Impossible de détecter les attaques par rejeu (besoin de `revoked = 1` pour savoir que le token existait) |
| TTL de token d'accès longue (heures/jours) | Un token volé fournit un accès à long terme ; annule l'utilité des tokens de rafraîchissement |
| Retourner 401 à la déconnexion avec token invalide | L'attaquant peut sonder s'il est encore connecté |
| Pas de `jti` dans le token d'accès | Impossible de suivre les tokens individuels pour les listes de révocation futures |
| Token unique (accès seul, pas de rafraîchissement) | L'utilisateur doit se re-authentifier toutes les 5 minutes, ou utiliser des TTL dangereusement longues |
| MD5 ou SHA-1 pour le hash de token | Hash faible ; utiliser SHA-256 ou mieux |
| Pas d'expiration sur les tokens de rafraîchissement | Les tokens de rafraîchissement vivent indéfiniment ; un token volé fournit un accès indefini |
