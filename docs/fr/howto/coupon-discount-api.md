# How-to : API de codes de réduction coupon

> **Référence FT** : FT302 (`NENE2-FT/couponlog`) — API de codes de réduction coupon : création réservée aux admins avec `X-Admin-Key` (hash_equals), `CODE_PATTERN` `[A-Z0-9]{4,32}` normalisation automatique en majuscules, UNIQUE(coupon_id, user_id) empêche le double rachat, expiré/épuisé/doublon → 409, 26 tests / 50 assertions PASS.

Ce guide montre comment construire un système de coupons où les admins créent des codes de réduction et les utilisateurs les rachètent selon des limites d'usage et des dates d'expiration.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- en centimes, ex. 500 = 5,00€
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

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` empêche le même utilisateur de racheter deux fois le même coupon. L'index sur `code` accélère les recherches par chaîne de code.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/coupons` | `X-Admin-Key` | Créer un coupon (admin uniquement) |
| `GET` | `/coupons/{code}` | — | Obtenir les détails d'un coupon |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | Racheter un coupon |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | Lister les rachats (admin uniquement) |

## Authentification admin — hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` empêche les attaques par canal auxiliaire de timing sur la comparaison de clé. Si `adminKey` est une chaîne vide (mal configuré), `isAdmin()` retourne false — échec fermé.

## Format du code coupon — CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- Alphanumérique majuscule uniquement
- 4–32 caractères
- Ancres `\A` / `\z` (correspondance sur toute la chaîne, pas seulement une sous-chaîne)

Les codes d'entrée sont normalisés en majuscules avant validation :

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // Générer automatiquement si non fourni
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

Un utilisateur envoyant `"summer50"` obtient le même coupon que `"SUMMER50"` — le système normalise automatiquement en majuscules. `pathCode()` normalise aussi les paramètres de chemin en majuscules, donc `GET /coupons/summer50` et `GET /coupons/SUMMER50` résolvent vers le même coupon.

## Validation de création de coupon

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount` : `is_int()` strict — les floats comme `9.99` sont rejetés
- `max_uses` : vaut `1` par défaut si non fourni
- `expires_at` : doit correspondre au préfixe ISO 8601 `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}`

## Rachat — Quatre modes d'échec

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

Tous les échecs de règle métier retournent **409 Conflict** (pas 422). L'expression `match` est exhaustive — la branche par défaut ne se déclenche que sur un succès avec la chaîne `'redeemed'` du repository.

## Validation de l'ID utilisateur

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` — seules les chaînes de chiffres purs sont acceptées (pas de `-`, `+`, espaces)
- `strlen > 18` — prévient le dépassement d'entier sur PHP 64-bit (`PHP_INT_MAX` fait 19 chiffres)
- `$id > 0` — ID zéro non valide

Retourne `null` → 400 Bad Request si l'en-tête est absent ou malformé.

## UNIQUE(coupon_id, user_id) — Rachat idempotent

La contrainte DB empêche le double rachat au niveau stockage. L'application vérifie aussi via le repository avant d'insérer, retournant `'already_redeemed'` plutôt que de dépendre d'une exception DB.

Plusieurs utilisateurs différents peuvent racheter le même coupon (jusqu'à `max_uses`). Seul le même utilisateur essayant le même coupon deux fois est bloqué.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| `==` ordinaire pour la comparaison de clé admin | Attaque par timing révèle la longueur / correspondances partielles de la clé |
| `adminKey` vide permet l'accès admin | Clé admin mal configurée = accès ouvert — échec fermé |
| Recherche de code sensible à la casse | `"summer50"` et `"SUMMER50"` traités comme des coupons différents |
| `discount` sans `is_int()` | Float `9.99` accepté ; centimes fractionnaires corrompent le grand livre |
| 422 pour expiré/épuisé | Ce sont des conflits d'état métier, pas des erreurs de validation — utiliser 409 |
| Pas de UNIQUE(coupon_id, user_id) | Race condition permet au même utilisateur de racheter deux fois simultanément |
| Pas de borne supérieure sur `max_uses` | L'attaquant crée un coupon avec `max_uses: 999999999` pour une remise effectivement illimitée |
| Ignorer `strlen > N` sur l'ID utilisateur | Les très grandes chaînes d'entiers débordent silencieusement le cast `(int)` |
| Pas d'index sur la colonne `code` | Scan complet de table sur chaque recherche de coupon |
| Retourner la liste des rachats à un non-admin | Révèle quels IDs utilisateur ont racheté — fuite de confidentialité |
