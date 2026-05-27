# Comment construire une gestion des tokens d'accès avec NENE2

Ce guide explique comment construire un système de tokens d'accès personnels (PAT) — les utilisateurs émettent, listent et révoquent leurs propres tokens API, chacun avec une portée (`read`/`write`/`admin`). Les tokens ne sont jamais stockés en clair ; seul leur hash SHA-256 est conservé.

**Field Trial** : FT136  
**Version NENE2** : ^1.5  
**Sujets couverts** : hachage des tokens, enums de portée, contrôle de propriété, idempotence de révocation, endpoint de vérification

---

## Ce que nous construisons

- `POST /users/{id}/tokens` — émettre un token (propriétaire uniquement, retourne le token brut une seule fois)
- `GET /users/{id}/tokens` — lister les tokens (propriétaire uniquement, pas de token brut dans la réponse)
- `DELETE /users/{id}/tokens/{tokenId}` — révoquer un token (propriétaire uniquement, 409 si déjà révoqué)
- `POST /tokens/verify` — vérifier un token brut (retourne valide/invalide + portée)

---

## Schéma de base de données

```sql
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

- `token_hash` — SHA-256 du token brut ; ne jamais stocker le token brut
- `revoked_at` — timestamp nullable ; `NULL` = actif, non-null = révoqué
- `CHECK (scope IN (...))` — contrainte de portée au niveau DB en défense en profondeur

---

## Enum de portée du token

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` retourne `null` pour les portées inconnues — utilisez ceci pour valider l'entrée avant de la stocker.

---

## Émission de tokens

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // chaîne hexadécimale de 64 caractères
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // retourné une seule fois, jamais stocké
}
```

Le token brut est retourné à l'appelant exactement une fois. Après cela, seul le hash est en base de données — il n'est pas possible de récupérer le token brut.

---

## Vérification des tokens

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**Pourquoi `!isset($arr['revoked_at'])` et pas `=== null` ?** Après qu'`isset()` retourne true, PHPStan élimine `null` du type — comparer à `null` produirait `identical.alwaysFalse`. Utilisez `isset()` seul pour vérifier la valeur null.

L'endpoint de vérification retourne toujours 200 avec `{ "valid": false }` pour les tokens inconnus ou révoqués — jamais 404. Cela empêche l'énumération des tokens.

---

## Contrôle de propriété

Chaque endpoint mutant vérifie que l'acteur authentifié correspond au propriétaire de la ressource :

```php
$actorId = $this->resolveActorId($request); // depuis l'en-tête X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Pour la révocation, il y a une seconde vérification de propriété sur le token lui-même :

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Cela empêche ATK-04 — Bob utilisant son propre chemin utilisateur mais révoquant l'ID de token d'Alice.

---

## Révocation — 409 pour un token déjà révoqué

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

La garde `WHERE revoked_at IS NULL` signifie que l'UPDATE est un no-op si le token est déjà révoqué. Le handler mappe `$count === 0` vers 409 Conflict.

---

## Liste des tokens — ne jamais inclure le token brut

La réponse de liste inclut `id`, `scope`, `label`, `created_at`, `revoked` (bool). Le token brut n'est jamais retourné après l'appel d'émission initial.

---

## Écueil PHPStan level 8 : isset + comparaison à null

```php
// INCORRECT — PHPStan signale `notIdentical.alwaysTrue`
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// CORRECT — isset() implique déjà non-null
'revoked' => isset($arr['revoked_at']),

// INCORRECT — PHPStan signale `identical.alwaysFalse`
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// CORRECT
'valid' => !isset($arr['revoked_at']),
```

---

## Résultats des tests d'attaque de cracker (FT136)

| Attaque | Attendu | Résultat |
|--------|----------|--------|
| ATK-01 : Émettre un token pour un autre utilisateur (IDOR) | 403 | Pass |
| ATK-02 : Lister les tokens d'un autre utilisateur (IDOR) | 403 | Pass |
| ATK-03 : Révoquer le token d'un autre utilisateur via son chemin | 403 | Pass |
| ATK-04 : Révoquer le token d'un autre utilisateur via son propre chemin | 403 | Pass |
| ATK-05 : Portée invalide (`superuser`) | 422 | Pass |
| ATK-06 : Utiliser un token révoqué pour vérifier | valid=false | Pass |
| ATK-07 : Token aléatoire par force brute | valid=false | Pass |
| ATK-08 : Injection SQL dans le corps de vérification | valid=false | Pass |
| ATK-09 : X-User-Id non numérique (`admin`) | pas 201 | Pass |
| ATK-10 : ID utilisateur négatif | 404 | Pass |
| ATK-11 : Chaîne de portée de 10 Ko | 422 | Pass |
| ATK-12 : Token vide ou contenant uniquement des espaces | 422 | Pass |

Les 12 tests d'attaque sont passés.

---

## Écueils courants

| Écueil | Correction |
|---------|-----|
| `isset($x) && $x !== null` | Utilisez `isset($x)` seul — PHPStan level 8 rejette la vérification redondante |
| Stocker le token brut en DB | Stocker uniquement `hash('sha256', $raw)` |
| Retourner le token brut dans la réponse de liste | Retourner le token brut uniquement dans la réponse d'émission |
| Ne pas vérifier la propriété du token lors de la révocation | Vérifier `token['user_id'] === userId` après avoir trouvé le token |
| Retourner 404 pour un token invalide dans verify | Toujours retourner 200 avec `valid: false` — empêche l'énumération |
