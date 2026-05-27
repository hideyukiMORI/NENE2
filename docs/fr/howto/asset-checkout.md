# How-to : Gestion de check-out / check-in d'actifs

Démontre le suivi exclusif de possession d'actifs avec un journal d'audit en ajout seul.
Field trial : FT194 (`../NENE2-FT/assetlog/`).

---

## Résumé du pattern

| Préoccupation | Approche |
|---|---|
| Possession exclusive | `holder_id INTEGER` — NULL = disponible, non-null = détenu |
| Conflit de check-out | 409 si `holder_id IS NOT NULL` avant la mise à jour |
| Check-in par le mauvais détenteur | 403 si `holder_id != userId` |
| Journal d'audit | Lignes `asset_history` en ajout seul à chaque changement d'état |
| Prévention IDOR | L'API publique cache `holder_id` ; clé admin requise pour le voir |
| Clé admin | Comparaison à temps constant `hash_equals()`, fail-closed sur clé vide |
| Identité utilisateur | En-tête `X-User-Id` ; garde `ctype_digit()` + longueur, pas de regex |

---

## Routes

| Méthode | Chemin | Auth | Description |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | Créer un actif |
| `GET` | `/assets` | — | Lister tous les actifs |
| `GET` | `/assets/{id}` | — | Obtenir un actif unique |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | Check-out d'un actif |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | Check-in d'un actif |
| `GET` | `/assets/{id}/history` | — | Historique d'audit |

---

## Schéma de base de données

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = disponible
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## Pattern de check-out exclusif

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

La garde `WHERE holder_id IS NULL` empêche le double check-out même sous des requêtes concurrentes
(SQLite sérialise les écritures ; MySQL/PgSQL ont besoin d'une transaction ou de `SELECT FOR UPDATE`).

---

## Prévention IDOR

```php
// Réponse publique — pas de holder_id
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// Réponse admin — inclut holder_id
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

Le gestionnaire vérifie `isAdmin()` et choisit la projection correcte :

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## Clé admin (fail-closed)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // pas de clé configurée → refuser
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

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

`ctype_digit()` est O(n) et sûr contre les ReDoS. Le plafond de longueur empêche le débordement d'entier.

---

## Correspondance des erreurs

| Résultat du repository | Statut HTTP |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## Notes de test

- `AppFactory::create(?PDO, ?string)` accepte SQLite en mémoire pour les tests unitaires.
- `withParsedBody($body)` doit être appelé sur les requêtes de test — Nyholm PSR-7 n'analyse pas automatiquement le JSON.
- Les assertions de liste/get publiques vérifient que la clé `holder_id` est absente (`assertArrayNotHasKey`).
- Test de cycle de vie : check-out → conflit → check-in → re-check-out par un utilisateur différent.
