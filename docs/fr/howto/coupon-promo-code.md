# Gestion des coupons et codes promo

Guide d'implémentation d'un système de coupons avec RBAC admin, suivi d'utilisation par utilisateur, date d'expiration et contrôle des limites.

## Vue d'ensemble

- Seul le rôle admin peut créer/désactiver des coupons et consulter l'historique d'utilisation
- Les utilisateurs ordinaires ne peuvent utiliser chaque coupon qu'une seule fois (`UNIQUE (coupon_id, user_id)`)
- `discount_pct` : entier de 1 à 100 (validation obligatoire)
- `max_uses = 0` signifie illimité
- `expires_at` est une chaîne ISO 8601 (NULL = sans expiration)
- user_id est obtenu **uniquement depuis l'en-tête X-User-Id** (pas d'injection via le corps)

## Endpoints

| Méthode | Chemin | Description | Permission |
|---------|--------|-------------|-----------|
| `POST` | `/coupons` | Créer un coupon | admin |
| `GET` | `/coupons/{code}` | Obtenir les informations d'un coupon | tout le monde |
| `POST` | `/coupons/{code}/use` | Utiliser un coupon (1 fois par utilisateur) | authentifié |
| `GET` | `/coupons/{code}/uses` | Historique d'utilisation | admin |
| `DELETE` | `/coupons/{code}` | Désactiver un coupon | admin |

## Conception de la base de données

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_pct INTEGER NOT NULL CHECK (discount_pct >= 1 AND discount_pct <= 100),
    max_uses INTEGER NOT NULL DEFAULT 0,
    use_count INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE coupon_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    used_at TEXT NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (coupon_id, user_id)` empêche le double usage par le même utilisateur au niveau DB.

## Pattern de vérification admin

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}

// Au début de handleCreate / handleDeactivate / handleListUses
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
if (!$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'admin role required'], 403);
}
```

## Ordre des vérifications d'utilisation de coupon

```php
// 1. Vérification d'authentification
if ($actorId === null) { return 401; }

// 2. Vérification d'existence du coupon
$coupon = $this->repository->findByCode($code);
if ($coupon === null) { return 404; }

// 3. Vérification is_active
if (!(bool) $coupon['is_active']) { return 422 'not active'; }

// 4. Vérification d'expiration
$now = date('c');
if ($coupon['expires_at'] !== null && $now > $coupon['expires_at']) { return 422 'expired'; }

// 5. Vérification max_uses (0 = illimité)
if ($maxUses > 0 && $coupon['use_count'] >= $maxUses) { return 422 'limit reached'; }

// 6. Vérification de doublon utilisateur (confirmation côté app de la contrainte UNIQUE)
$existing = $this->repository->findUse($coupon['id'], $actorId);
if ($existing !== null) { return 422 'already used'; }

// 7. Enregistrement de l'utilisation + incrémentation de use_count
$this->repository->recordUse($coupon['id'], $actorId, $now);
return 201;
```

## Enregistrement d'utilisation de coupon

```php
public function recordUse(int $couponId, int $userId, string $now): int
{
    $id = $this->executor->insert(
        'INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)',
        [$couponId, $userId, $now]
    );
    $this->executor->execute(
        'UPDATE coupons SET use_count = use_count + 1 WHERE id = ?',
        [$couponId]
    );
    return $id;
}
```

L'incrémentation de `use_count` s'exécute dans la même transaction que l'INSERT.
Sur MySQL, `use_count = use_count + 1` fonctionne de façon atomique lors d'accès concurrents.

## Validation de discount_pct

```php
$discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
    return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
}
```

`CHECK (discount_pct >= 1 AND discount_pct <= 100)` est aussi garanti côté DB,
mais l'application rejette d'abord pour retourner un 422 approprié.

## Exemples de réponse

### POST /coupons (création)
```json
{
  "id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "max_uses": 100,
  "use_count": 0,
  "is_active": true,
  "expires_at": "2026-08-31T23:59:59+00:00",
  "created_by": 1,
  "created_at": "2026-05-21T..."
}
```

### POST /coupons/{code}/use (utilisation)
```json
{
  "id": 42,
  "coupon_id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "user_id": 7,
  "used_at": "2026-05-21T..."
}
```

## Prévention de l'injection user_id

user_id doit toujours être obtenu depuis l'en-tête `X-User-Id`.
Le champ `user_id` du corps de la requête est ignoré.

```php
// Incorrect : $userId = (int) $body['user_id'];  // Manipulable par l'attaquant
// Correct :
$actorId = $this->requireUserId($request);  // En-tête X-User-Id uniquement
```
