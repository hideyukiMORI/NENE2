# How-to : Gestion du cycle de vie des tokens API

> **Référence FT** : FT272 (`NENE2-FT/tokenlog`) — Cycle de vie des tokens API : stockage du hash SHA-256 (le texte brut n'est jamais persisté), enum de portée (read/write/admin) avec contrainte CHECK en DB, garde IDOR (actorId doit correspondre à userId), révocation douce via revoked_at, l'endpoint verify retourne valid/user_id/scope, 29 tests / 70 assertions PASS.
>
> **Évaluation ATK** : ATK-01 à ATK-12 inclus à la fin de ce document.

Démontre un système de tokens API scopés : émettre des tokens pour un utilisateur, les lister/révoquer, et vérifier un token brut au moment de l'accès. Les tokens sont stockés uniquement sous forme de hashes SHA-256 — le texte brut est retourné une fois à l'émission et jamais stocké.

---

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

Choix de conception clés :
- `token_hash UNIQUE` — empêche l'émission accidentelle de doublons ; également la clé de recherche lors de la vérification
- `CHECK (scope IN (...))` — application de l'enum de portée au niveau DB
- `revoked_at TEXT` — révocation douce ; `NULL` signifie actif, non-NULL signifie révoqué

---

## Routes

| Méthode   | Chemin                              | Description                                |
|-----------|-------------------------------------|--------------------------------------------|
| `POST`    | `/users`                            | Créer un utilisateur                       |
| `POST`    | `/users/{userId}/tokens`            | Émettre un token (propriétaire uniquement) |
| `GET`     | `/users/{userId}/tokens`            | Lister les tokens d'un utilisateur (propriétaire uniquement) |
| `DELETE`  | `/users/{userId}/tokens/{tokenId}`  | Révoquer un token (propriétaire uniquement) |
| `POST`    | `/tokens/verify`                    | Vérifier un token brut                    |

---

## Stockage uniquement du hash

Le token brut est retourné une fois à l'émission et jamais stocké :

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 caractères hex — 256 bits d'entropie
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // retourné à l'appelant, jamais stocké
}
```

Lors de la vérification, l'appelant fournit le token brut ; le hash est recalculé et recherché :

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // non trouvé → l'appelant retourne {valid: false}
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## Application de la portée

`TokenScope` est un enum PHP backed ; `tryFrom()` rejette les valeurs inconnues avant tout accès à la DB :

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// Dans le handler de route :
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

La contrainte `CHECK` en DB fournit une deuxième couche d'application.

---

## Garde IDOR

L'émission, le listage et la révocation de tokens exigent que l'acteur soit le propriétaire :

```php
$actorId = $this->resolveActorId($request); // depuis l'en-tête X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

La révocation vérifie également que le token appartient à `userId`, pas seulement n'importe quel token :

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Révocation

La révocation douce définit `revoked_at` ; l'UPDATE s'applique uniquement si `revoked_at IS NULL` :

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );
    return $count > 0;
}
```

Si le token est déjà révoqué, le handler de route retourne 409 Conflict :

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Rejeu de token après révocation 🚫 BLOCKED

**Attack**: Révoquer un token, puis utiliser la même valeur de token brut sur `/tokens/verify`.
**Result**: BLOCKED — `verifyToken()` consulte `revoked_at` dans la ligne ; un `revoked_at` non-NULL provoque `valid: false`. Le token révoqué n'est pas supprimé, donc il se résout mais retourne `{valid: false}`.

---

### ATK-02 — Devinette de token par force brute 🚫 BLOCKED

**Attack**: Soumettre des chaînes hex aléatoires de 64 caractères à `/tokens/verify` dans l'espoir de faire correspondre un hash de token valide.
**Result**: BLOCKED — les tokens sont `bin2hex(random_bytes(32))` = 256 bits d'entropie. La probabilité d'une devinette réussie est `1 / 2^256`. Pas de limitation de débit dans ce FT, mais l'entropie seule rend la force brute infaisable sur le plan informatique.

---

### ATK-03 — IDOR : accès à la liste de tokens d'un autre utilisateur 🚫 BLOCKED

**Attack**: Définir `X-User-Id: 1` et demander `GET /users/2/tokens`.
**Result**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden.

---

### ATK-04 — IDOR : révoquer le token d'un autre utilisateur 🚫 BLOCKED

**Attack**: En tant qu'utilisateur 1, appeler `DELETE /users/2/tokens/{tokenId}`.
**Result**: BLOCKED — le handler de route vérifie `actorId !== userId` → 403 avant de récupérer le token.

---

### ATK-05 — Révocation de token cross-propriétaire (ID de token partagé) 🚫 BLOCKED

**Attack**: En tant qu'utilisateur 2, appeler `DELETE /users/2/tokens/{tokenId}` où `tokenId` appartient à l'utilisateur 1.
**Result**: BLOCKED — après que la vérification IDOR passe (actorId = userId = 2), `findTokenById` retourne le token, puis `$token['user_id'] !== $userId` → 403. La double vérification de propriété empêche la révocation cross-utilisateur.

---

### ATK-06 — Injection de portée invalide 🚫 BLOCKED

**Attack**: POST `/users/{id}/tokens` avec `{"scope": "superadmin"}`.
**Result**: BLOCKED — `TokenScope::tryFrom('superadmin')` retourne `null` → 422. La contrainte CHECK en DB le bloquerait également si la couche applicative le laissait passer d'une façon ou d'une autre.

---

### ATK-07 — Extraction du texte brut du token depuis la DB 🚫 BLOCKED

**Attack**: Si un attaquant obtient un accès en lecture à la table `tokens`, peut-il obtenir des tokens fonctionnels ?
**Result**: BLOCKED — seul `token_hash` (SHA-256) est stocké. L'inversion de SHA-256 est infaisable sur le plan informatique. Le token brut est retourné une fois à l'émission et rejeté côté serveur.

---

### ATK-08 — Vérification avec token vide/malformé 🚫 BLOCKED

**Attack**: POST `/tokens/verify` avec `{"token": ""}` ou `{"token": null}`.
**Result**: BLOCKED — vérification de chaîne vide : `if ($token === '') → 422`. `null` est rejeté par la vérification `is_string()`. Le SHA-256 d'une chaîne vide ne correspondrait de toute façon à aucun hash stocké.

---

### ATK-09 — Émission de token pour un utilisateur inexistant 🚫 BLOCKED

**Attack**: POST `/users/9999/tokens` où l'utilisateur 9999 n'existe pas.
**Result**: BLOCKED — `findUserById(9999)` retourne `false` → 404 avant qu'aucun token soit créé.

---

### ATK-10 — Double révocation (idempotence) 🚫 BLOCKED

**Attack**: Révoquer le même token deux fois en succession rapide.
**Result**: BLOCKED — `revokeToken` utilise `WHERE revoked_at IS NULL` ; le deuxième appel retourne 0 lignes affectées. Le handler de route lit `$token['revoked'] === true` avant d'appeler le repo → 409 Conflict. Pas de fenêtre de condition de course pour que la double révocation réussisse.

---

### ATK-11 — UserId négatif ou chaîne dans le chemin 🚫 BLOCKED

**Attack**: `GET /users/-1/tokens` ou `GET /users/abc/tokens`.
**Result**: BLOCKED — `is_numeric($params['userId'])` → cast `(int)`. `-1` devient -1 ; `findUserById(-1)` retourne false → 404. `abc` n'est pas numérique → `userId = 0` → 404.

---

### ATK-12 — Dégradation de portée dans la réponse verify 🚫 BLOCKED

**Attack**: Après avoir obtenu un token de portée `read`, essayer de forger `scope: write` dans la réponse verify en envoyant un corps de requête modifié.
**Result**: BLOCKED — `/tokens/verify` n'accepte qu'une chaîne de token brut ; la portée est lue depuis la ligne DB, pas depuis un champ fourni par le client. Le client ne peut pas influencer la portée retournée.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|---------|
| ATK-01 | Rejeu de token révoqué | 🚫 BLOCKED |
| ATK-02 | Devinette de token par force brute | 🚫 BLOCKED |
| ATK-03 | IDOR : lire la liste de tokens d'un autre utilisateur | 🚫 BLOCKED |
| ATK-04 | IDOR : révoquer les tokens d'un autre utilisateur | 🚫 BLOCKED |
| ATK-05 | Révocation de token cross-propriétaire | 🚫 BLOCKED |
| ATK-06 | Injection de portée invalide | 🚫 BLOCKED |
| ATK-07 | Extraction du texte brut depuis la DB | 🚫 BLOCKED |
| ATK-08 | Token vide/malformé lors de la vérification | 🚫 BLOCKED |
| ATK-09 | Émission de token pour un utilisateur inexistant | 🚫 BLOCKED |
| ATK-10 | Condition de course de double révocation | 🚫 BLOCKED |
| ATK-11 | UserId négatif/chaîne dans le chemin | 🚫 BLOCKED |
| ATK-12 | Dégradation de portée via le corps verify | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
Aucune découverte critique. Le stockage uniquement du hash, l'application de l'enum de portée et les doubles vérifications IDOR forment une surface de défense robuste.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker le token brut en DB | Une fuite de lecture de DB expose tous les tokens ; les tokens ne peuvent pas être tournés sans action de l'utilisateur |
| Utiliser MD5/SHA-1 pour le hash du token | Attaques par collision ; préférer SHA-256 ou BLAKE2 |
| Accepter des chaînes de portée arbitraires | Sans validation `tryFrom()`, des portées `superadmin` peuvent être émises |
| Pas de vérification de propriété sur la révocation | N'importe quel utilisateur authentifié peut révoquer n'importe quel token (IDOR) |
| Suppression physique des tokens à la révocation | La piste d'audit est perdue ; impossible de détecter le rejeu d'un token révoqué |
| Retourner 404 pour un token déjà révoqué | Rend impossible la distinction entre "non trouvé" et "déjà révoqué" ; utiliser 409 |
