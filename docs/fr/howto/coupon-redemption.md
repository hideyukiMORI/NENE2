# How-to : API de rachat de coupon / code de réduction

Ce guide montre comment construire un système de rachat de coupons avec des limites d'usage et d'expiration en utilisant NENE2.
Pattern démontré par le field trial **couponlog** (FT218).

## Fonctionnalités

- Créer des codes coupon avec un montant de réduction, une limite d'usage et une expiration (admin uniquement)
- Génération automatique optionnelle de codes aléatoires (`bin2hex(random_bytes(6))`)
- Un rachat par utilisateur par coupon (`UNIQUE(coupon_id, user_id)`)
- Application de la limite d'usage (`max_uses`)
- Vérification d'expiration contre l'heure UTC actuelle
- Listing des rachats réservé aux admins

## Schéma

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/coupons` | Admin | Créer un coupon |
| `GET` | `/coupons/{code}` | Public | Obtenir les infos du coupon |
| `POST` | `/coupons/{code}/redeem` | Utilisateur | Racheter le coupon |
| `GET` | `/coupons/{code}/redemptions` | Admin | Lister les rachats |

## Validation du code

Les codes coupon utilisent un pattern strict pour éviter l'injection :

```php
/** Code coupon : alphanumérique majuscule, 4–32 caractères */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

Le paramètre de chemin est normalisé en majuscules avant validation :

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## Logique de rachat

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // Vérifier l'expiration
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // Vérifier la limite d'usage
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // Vérifier la limite par utilisateur
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // Enregistrer + incrémenter le compteur
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

Le gestionnaire de route utilise une expression `match` pour un branchement propre :

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## Codes générés automatiquement

Quand aucun `code` n'est fourni dans le corps de la requête, un code est généré :

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 caractères hex majuscules
}
```

## Patterns de sécurité

- **Admin fail-closed** : `if ($this->adminKey === '') return false;` avant `hash_equals()`
- **Pattern de code** : équivalent `ctype_digit()` pour les codes — regex `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`** : vérification de type stricte pour `discount` et `max_uses` — rejette les floats
- **Expiration ISO 8601** : validation par regex + comparaison lexicographique (chaînes UTC)
- **Incrément atomique** : `UPDATE SET used_count = used_count + 1` empêche les race conditions
- **Contrainte UNIQUE** : filet de sécurité au niveau de la base de données contre les doublons
