# How-to : Rotation de token de rafraîchissement JWT

Ce guide couvre l'implémentation de tokens d'accès de courte durée combinés avec des tokens
de rafraîchissement de longue durée. La propriété clé est la **rotation** : chaque utilisation d'un token de rafraîchissement le révoque immédiatement et en émet un nouveau. Un token de rafraîchissement réutilisé (déjà révoqué) déclenche la révocation de tous les tokens de cet utilisateur.

---

## Pourquoi deux tokens ?

| Token | TTL | Stockage | Usage |
|---|---|---|---|
| Token d'accès | 5 min | Mémoire client | Authentifie les requêtes API (sans état, pas de recherche DB) |
| Token de rafraîchissement | 7 jours | DB (hashé) | Émet de nouveaux tokens d'accès ; géré via rotation |

Un token d'accès de courte durée limite les dommages en cas de fuite — il expire en quelques minutes. Le
token de rafraîchissement étend la session sans nécessiter de reconnexion, mais il est révocable parce
qu'il vit dans la base de données.

---

## Schéma

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- hash SHA-256 ; jamais la valeur brute
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` — toujours stocker le hash, jamais le token brut. Si la DB fuit,
les tokens hashés ne peuvent pas être utilisés directement.

---

## Émission des tokens

### Token d'accès : ajouter `jti` pour l'unicité

Sans `jti`, deux tokens émis dans la même seconde pour le même utilisateur sont identiques —
leurs payloads sont identiques octet par octet. `jti` (JWT ID) garantit que chaque token est unique
et est la base pour les futures listes de blocage de tokens d'accès :

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // unique par émission
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 minutes
]);
```

### Token de rafraîchissement : stocker le hash, retourner la valeur brute

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // token aléatoire de 256 bits
    $hash      = hash('sha256', $raw);       // stocker uniquement ceci
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // le client reçoit ceci ; la DB ne le stocke jamais
}
```

Pour rechercher un token à partir d'une valeur fournie par le client :

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);

    $row = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

## Rotation de token

Chaque requête de rafraîchissement doit révoquer l'ancien token avant d'en émettre un nouveau :

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... analyser le body, trouver le token stocké ...

    if ($stored === null || !$stored->isValid()) {
        // La réutilisation d'un token révoqué est une potentielle attaque par rejeu —
        // révoquer tous les tokens de l'utilisateur pour forcer la re-authentification.
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // Rotation : révoquer l'ancien token en premier, puis émettre une nouvelle paire
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**Détection de réutilisation** : si un token de rafraîchissement révoqué arrive à l'endpoint `/auth/refresh`,
cela signifie soit que l'utilisateur relit un ancien token (inhabituel) soit qu'un attaquant l'a volé.
`revokeAllForUser()` force chaque session à se re-authentifier, limitant le rayon d'explosion.

---

## Déconnexion : toujours retourner 204

Ne jamais retourner des codes de statut différents selon que le token de rafraîchissement était valide.
Faire cela permet à un attaquant de sonder si un token est encore actif :

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... analyser le body ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Toujours 204 — ne jamais fuiter si le token était valide ou non
    return $this->json->createEmpty(204);
}
```

Cela signifie également que la double déconnexion (appeler logout deux fois avec le même token) retourne 204 les deux
fois — le client peut toujours appeler logout en toute sécurité sans se préoccuper de l'état du token.

---

## Vérification de validité sur l'entité RefreshToken

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

La comparaison de chaînes fonctionne pour les dates ISO-8601 triées lexicographiquement. Si vous stockez
les timestamps comme entiers Unix, comparez avec `time()` à la place.

---

## BearerTokenMiddleware : exclure les chemins refresh/logout

Les endpoints de rafraîchissement et de déconnexion reçoivent un token de rafraîchissement dans le body, pas un token d'accès Bearer dans l'en-tête Authorization. Excluez-les de `BearerTokenMiddleware` :

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

L'endpoint `/auth/me` (et tout autre chemin protégé) reste protégé par le middleware.

---

## Forme de la réponse

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in` (secondes) permet au client de programmer un rafraîchissement proactif avant que le token d'accès
expire, évitant une requête échouée suivie d'un rafraîchissement.

---

## Liste de contrôle de revue de code

1. La colonne `token_hash` stocke `hash('sha256', $raw)` — jamais la valeur brute
2. `revoke()` est appelé avant `issueTokenPair()` dans le handler de rafraîchissement
3. La réutilisation de token révoqué déclenche `revokeAllForUser()` (pas seulement un 401)
4. La déconnexion retourne toujours 204 — pas de 401/404 conditionnel
5. La TTL du token d'accès est courte (≤ 15 minutes)
6. Le claim `jti` est présent dans les tokens d'accès
7. Les tests couvrent la rotation croisée de tokens (ancien token invalide après rafraîchissement) et la détection de réutilisation

---

## Tester la rotation et la détection de réutilisation

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // L'ancien token doit être rejeté
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // Tourner une fois — l'ancien token est maintenant révoqué
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // L'attaquant rejoue l'ancien token (révoqué) — déclenche revokeAllForUser()
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // Le token de rafraîchissement nouvellement émis est maintenant aussi révoqué
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## Voir aussi

- `docs/howto/jwt-authentication.md` — Émission JWT, BearerTokenMiddleware, `nene2.auth.claims`
- `docs/howto/password-hashing.md` — Argon2id, pattern de hash factice pour la prévention d'énumération d'utilisateurs
- `docs/field-trials/2026-05-field-trial-113.md` — Field trial de rotation de token de rafraîchissement
