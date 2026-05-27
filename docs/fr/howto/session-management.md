# Comment construire un gestionnaire de sessions multi-appareils

> **Pattern prouvé par FT186 sessionlog** — suivi de sessions multi-appareils, prévention IDOR, garde contre le mass assignment, révocation sans oracle de timing.

---

## Ce que ce guide couvre

Un gestionnaire de sessions multi-appareils permet aux utilisateurs de :

1. **Créer des sessions** à la connexion (chaque appareil obtient son propre token)
2. **Lister les sessions actives** scopées à leur ID utilisateur
3. **Révoquer une session** (déconnexion d'un appareil)
4. **Révoquer toutes les sessions sauf la courante** (déconnexion de tous les autres appareils)

Garanties de sécurité démontrées :

| Préoccupation | Technique |
|---|---|
| Prévention IDOR | Toutes les mutations scopent `WHERE token = ? AND user_id = ?` |
| Mass assignment | `token`, `user_id`, `created_at`, `revoked_at` définis côté serveur uniquement |
| Oracle de timing | 404 générique pour tous les échecs — pas de fuite de propriété |
| Débordement d'entier | Garde strlen 18 chiffres de `V::queryInt()` |
| Confusion de type | `V::str()` rejette les `device_name`/`ip_address` non-chaînes |
| Entropie du token | `bin2hex(random_bytes(32))` — 256 bits, 64 caractères hex |
| Injection SQL | Requêtes paramétrées PDO + porte `/^[0-9a-f]{64}$/` |

---

## Schéma

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = active
);
```

`revoked_at IS NULL` est le prédicat de session active. La suppression douce évite de perdre l'historique d'audit.

---

## Conception de l'API

| Méthode | Chemin | En-tête | Description |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Créer une session |
| `GET` | `/sessions` | `X-User-Id` | Lister ses propres sessions actives |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Révoquer une session |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Révoquer toutes sauf la courante |

---

## Pattern principal : Génération de token 256 bits

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // entropie 256 bits, 64 caractères hex
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` produit 64 caractères hex minuscules depuis une source cryptographiquement sûre. Ne jamais accepter des tokens depuis l'entrée utilisateur.

---

## Pattern principal : Prévention IDOR

```php
// INCORRECT — permet à tout utilisateur authentifié de révoquer n'importe quelle session
UPDATE sessions SET revoked_at = ? WHERE token = ?

// CORRECT — doit posséder la session
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

`rowCount() > 0` retourne `false` quand le token existe mais appartient à un autre utilisateur — le handler répond avec un 404 générique (voir section oracle de timing).

---

## Pattern principal : Garde contre le mass assignment

```php
// Handler POST /sessions — body attaquant : {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId vient de l'en-tête X-User-Id — jamais du body
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // Seuls les champs sûrs et validés sont transmis
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at définis par le repository — pas depuis le body
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## Pattern principal : Prévention de l'oracle de timing

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I : format invalide → 404 immédiat (pas de requête DB)
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // Garde IDOR : revokeForUser retourne false quand :
    //   - le token n'existe pas
    //   - le token appartient à un autre utilisateur
    //   - le token est déjà révoqué
    // Tous les cas retournent le MÊME 404 — pas d'oracle de propriété
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

Ne jamais distinguer "non trouvé" de "mauvais utilisateur" dans la réponse. Un attaquant qui connaît le token d'une victime ne doit pas savoir s'il est actif ou appartient à cet utilisateur.

---

## Pattern principal : Révoquer toutes sauf la courante

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

L'appelant passe l'en-tête `X-Current-Session`. Les deux conditions `user_id` et d'exclusion sont imposées dans la requête unique.

---

## Pattern principal : Validation de limit sûre contre les débordements

```php
// VULN-A : V::queryInt refuse les >18 chiffres — prévient le débordement silencieux d'int PHP
// VULN-F : ctype_digit est O(n) — pas de risque de backtracking regex
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` rejette les nombres négatifs, flottants, chaînes hex (`0x10`), et les nombres > 18 chiffres.

---

## Validation du token de route

Toujours valider le format du token au niveau de la route avant d'interroger la DB :

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Dans le handler :
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

Cela bloque les chaînes d'injection SQL, les tentatives de traversée de chemin, et les tokens trop courts/longs avant toute interaction avec la base de données.

---

## Résultats des tests (FT186)

```
54 tests / 116 assertions — tous PASS
PHPStan level 8 — aucune erreur
PHP CS Fixer — propre
```

Couverture VULN-A~L :

| Vuln | Pattern | Test |
|---|---|---|
| A | Débordement 19 chiffres de limit | `testVulnALimitOverflow19Digits` |
| B | Confusion de type device_name | `testVulnBDeviceNameAsInteger` |
| C | Injection SQL dans le token | `testVulnCSqlInjectionToken` |
| D | limit négatif/flottant/hex | `testVulnDNegativeLimitRejected` |
| E | Révocation IDOR | `testVulnECannotRevokeOtherUsersSession` |
| F | Long limit style ReDoS | `testVulnFVeryLongLimitRejected` |
| H | Oracle de timing | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | Token vide/court/traversal | `testVulnIEmptyTokenSegmentNotMatched` |
| L | Mass assignment | `testVulnLTokenFromBodyIsIgnored` |

Source : [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
