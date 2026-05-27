# How-To : API de coffre-fort à secrets personnel

Démontre un stockage clé-valeur par utilisateur avec intégrité HMAC, prévention IDOR et accès aux métadonnées réservé à l'admin.
Field trial : FT195 (`../NENE2-FT/vaultlog/`). Inclut un audit de sécurité VULN-A~L.

---

## Résumé du pattern

| Préoccupation | Approche |
|---|---|
| Isolation utilisateur | `WHERE user_id = :uid` sur chaque requête — IDOR impossible |
| L'admin ne voit jamais les valeurs | Les endpoints admin retournent uniquement `user_id + key` |
| Intégrité HMAC | `HMAC-SHA256(userId|key|value, secret)` stocké par entrée |
| Validation de clé | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — sûr, sans risque ReDoS |
| Validation d'ID utilisateur | `ctype_digit()` + garde de longueur + vérification `> 0` |
| Clé admin | `hash_equals()` en temps constant, fail-closed sur clé vide |
| Upsert | `UNIQUE(user_id, key_name)` → premier stockage (201) ou mise à jour (200) |

---

## Routes

| Méthode | Chemin | Auth | Description |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Stocker ou mettre à jour un secret |
| `GET` | `/vault` | `X-User-Id` | Lister les clés secrètes de l'utilisateur (sans les valeurs) |
| `GET` | `/vault/{key}` | `X-User-Id` | Obtenir la valeur secrète de l'utilisateur |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Supprimer le secret de l'utilisateur |
| `GET` | `/admin/vault` | `X-Admin-Key` | Lister tous les utilisateurs + clés (sans les valeurs) |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | Lister les clés d'un utilisateur spécifique |

---

## Schéma de base de données

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- tag d'intégrité HMAC-SHA256
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

La contrainte `UNIQUE(user_id, key_name)` impose une seule entrée par paire (utilisateur, clé).

---

## Intégrité HMAC

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

Sur GET, le handler vérifie le HMAC stocké :

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

Cela détecte toute altération directe en DB (par exemple, un DBA compromis modifiant les valeurs sans passer par l'API).

---

## Prévention IDOR

Chaque requête inclut `user_id = :uid` :

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

L'utilisateur 200 qui interroge la clé `private-key` appartenant à l'utilisateur 100 reçoit un 404 — identique à "non trouvé",
empêchant l'énumération des clés existantes pour d'autres utilisateurs.

Les endpoints admin ne retournent jamais `value` :

```php
// L'utilisateur voit sa propre valeur
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// L'admin ne voit que les métadonnées — pas de valeur
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## Validation de clé

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

Les ancres `\A` et `\z` préviennent les correspondances partielles. La classe de caractères est minimale :
alphanumérique minuscule, tiret, underscore. La longueur est bornée `{1,64}` — pas d'amplification par backtracking.

Cela rejette :
- Les lettres majuscules (`UPPER_CASE`)
- Les espaces ou caractères spéciaux
- Les fragments de traversée de chemin (`../etc/passwd`)
- Les chaînes injectables SQL (`' OR '1'='1`)
- La chaîne vide ou les chaînes > 64 caractères

---

## Validation de l'ID utilisateur

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` rejette les nombres négatifs (le signe `-` n'est pas un chiffre)
- `strlen > 18` prévient le débordement d'entier (`PHP_INT_MAX` a 19 chiffres)
- `> 0` rejette `"0"` comme ID utilisateur invalide

---

## Pattern upsert

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

Retourne `'stored'` (201) à la première écriture, `'updated'` (200) à l'écrasement.
Le handler mappe ces valeurs aux codes de statut HTTP.

---

## Résultats VULN-A~L

| Vérification | Test | Résultat |
|---|---|---|
| VULN-A | Injection SQL dans le paramètre de clé / le body | PASS — la validation de clé rejette avant la requête |
| VULN-B | IDOR : un utilisateur lit/supprime la clé d'un autre | PASS — 404 sur accès cross-utilisateur |
| VULN-C | La liste retourne uniquement ses propres entrées | PASS — WHERE user_id scopé |
| VULN-D | Brute force / contournement de la clé admin | PASS — hash_equals + fail-closed |
| VULN-E | XSS dans la valeur | PASS — stocké tel quel, réponse JSON pas HTML |
| VULN-F | Idempotence upsert de clé | PASS — la dernière écriture gagne, pas de doublons |
| VULN-G | Traversée de chemin dans la clé | PASS — le pattern rejette `..` et les slashes |
| VULN-H | user-id négatif / zéro | PASS — garde ctype_digit + > 0 |
| VULN-I | user-id très grand (débordement) | PASS — garde strlen > 18 |
| VULN-J | Octet nul dans le chemin | PASS — le routeur / pattern rejette |
| VULN-K | Clé trop longue dans le body | PASS — validation 422 |
| VULN-L | Secret HMAC vide (pas de panique) | PASS — HMAC déterministe avec clé vide, pas de crash |

---

## Notes de test

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — tous injectables pour les tests unitaires.
- `withParsedBody($body)` est requis dans les helpers de test (Nyholm PSR-7 ne parse pas automatiquement le JSON).
- Tests IDOR : stocker en tant qu'utilisateur 100, tenter l'accès en tant qu'utilisateur 200 → doit obtenir 404.
- Tests admin : vérifier que la clé `value` est absente de chaque tableau de réponse.
