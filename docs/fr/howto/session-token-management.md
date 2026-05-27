# How-to : API de gestion de sessions / tokens (ATK-01~12)

Ce guide démontre une API de tokens de session sécurisée couvrant tous les vecteurs d'attaque cracker-mindset ATK-01~12.

## Vue d'ensemble du pattern

- `POST /sessions` — Émettre un nouveau token opaque pour un utilisateur (`X-User-Id` requis).
- `GET /sessions/{token}` — Valider un token (404 si révoqué ou expiré).
- `DELETE /sessions/{token}` — Révoquer un token (propriétaire ou admin).
- `GET /users/{userId}/sessions` — Lister les sessions actives (propriétaire ou admin).

Les tokens sont `bin2hex(random_bytes(32))` — 64 caractères hex minuscules.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## Génération de token

```php
$token = bin2hex(random_bytes(32));  // 64 caractères hex minuscules
```

`random_bytes()` utilise un CSPRNG ; les tokens sont imprévisibles et non séquentiels.

## Validation du format de token

Avant tout lookup DB, valider le format du token avec un regex strict :

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — n'atteint jamais la DB
    }
    return $token;
}
```

Cela rejette les payloads d'injection SQL, les entrées surdimensionnées, l'hex majuscule et les chaînes non-hex avant toute requête DB.

## ATK-01 : Injection SQL dans le chemin token

Le regex de format de token rejette `' OR '1'='1` immédiatement. Même s'il passait, la requête DB utilise une requête paramétrée :

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02~04 : Attaques sur le format de token

Tous rejetés par le regex `/\A[0-9a-f]{64}\z/` :
- Chaîne vide (longueur 0 ≠ 64)
- Chaîne surdimensionnée (256 caractères ≠ 64)
- Caractères non-hex (`g`, `A`–`F` majuscules, caractères spéciaux)
- Longueur incorrecte (63 ou 65 caractères)

## ATK-05 : Débordement d'entier dans X-User-Id

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

Un entier de 19 chiffres dépasse la limite de 18 caractères et est rejeté avant tout cast `(int)`.

## ATK-06 : ID utilisateur négatif / zéro

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` et les valeurs négatives retournent `null`, ce qui déclenche 400.

## ATK-07 : Clé admin fail-closed

Une `adminKey` vide n'accorde jamais les droits admin :

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` prévient les attaques par timing lors de la comparaison de la clé.

## ATK-08 : Contournement d'auth via X-User-Id: 0

`uid()` retourne `null` pour ID=0 → 400, pas 200.

## ATK-09 : ID utilisateur non numérique dans le chemin de liste

`ctype_digit()` rejette `abc`, `1.5`, `-1` :

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10 : TTL flottant

`is_int()` est la vérification de type stricte de PHP — `60.5` retourne `false` :

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11 : Double révocation retourne 404

Le repository vérifie `revoked === 1` avant de mettre à jour :

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

Les sessions déjà révoquées ne peuvent pas être "dé-révoquées" en renvoyant un DELETE.

## ATK-12 : Rejet du format de token par brute-force

Tout token ne correspondant pas exactement à 64 caractères hex minuscules est rejeté avec 404 avant de toucher la base de données. Les tentatives de brute-force heurtent le mur du regex, pas la DB.

## IDOR : Propriétaire vs Admin

- Les non-propriétaires qui révoquent ou listent les sessions d'un autre utilisateur reçoivent 404 (pas 403).
- Les admins utilisent l'en-tête `X-Admin-Key` ; fail-closed quand la clé n'est pas configurée.

## Voir aussi

- Source FT208 : `../NENE2-FT/sessionlog/`
- Connexe : `docs/howto/rate-limiting.md` (FT200, ATK)
- Connexe : `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
