# Système de marque-pages

Permettre aux utilisateurs de sauvegarder des éléments dans des collections nommées. L'ajout de marque-pages est idempotent — ajouter le même élément deux fois retourne le marque-page existant sans erreur.

## Vue d'ensemble

Un système de marque-pages comprend :
- **Ajouter un marque-page** — sauvegarder un élément dans la collection d'un utilisateur (idempotent)
- **Supprimer un marque-page** — supprimer un marque-page sauvegardé (404 si introuvable)
- **Lister les marque-pages** — tous les marque-pages d'un utilisateur, optionnellement filtrés par collection
- **Compter les marque-pages** — compteur léger pour badge
- **Obtenir un marque-page** — vérifier si un élément spécifique est en marque-page

## Schéma de la base de données

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE (user_id, item_id)` impose un marque-page par utilisateur par élément. Le champ `collection` regroupe les marque-pages dans des catégories nommées avec `'default'` comme repli.

## Ajout idempotent

Vérifier l'existence d'un marque-page avant d'insérer. En cas de conflit (race condition), capturer `DatabaseConstraintException` et retourner l'enregistrement existant :

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // déjà en marque-page — pas une erreur
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race condition — une autre requête a gagné ; retourner le marque-page existant
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

Le pattern vérification-puis-insertion gère le cas courant efficacement. La capture de `DatabaseConstraintException` gère la race condition sous requêtes concurrentes.

## Filtrage par collection

Utiliser un paramètre de requête `collection` optionnel pour filtrer les marque-pages :

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Une collection `null` retourne tous les marque-pages ; une chaîne non vide filtre sur cette collection.

## La suppression retourne 204 vs 404

- `204 No Content` — le marque-page existait et a été supprimé
- `404 Not Found` — le marque-page n'existait pas

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` retourne le nombre de lignes affectées — zéro signifie qu'aucun marque-page n'a été trouvé.

## Schéma MySQL

MySQL nécessite `ENGINE=InnoDB` et la syntaxe `AUTO_INCREMENT` explicites :

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Pour les tests d'intégration MySQL, utiliser `SET FOREIGN_KEY_CHECKS = 0` avant de supprimer les tables pour éviter les problèmes d'ordre des dépendances FK.

## Pattern de test d'intégration MySQL

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## Propriétés de sécurité

| Propriété | Implémentation |
|---|---|
| Un marque-page par utilisateur par élément | Contrainte DB `UNIQUE (user_id, item_id)` |
| Race condition à l'ajout | Capture `DatabaseConstraintException` → retourner l'existant |
| Isolation des utilisateurs | Toutes les requêtes filtrent par `user_id` |
| Suppression d'un inexistant | Retourne 404 (pas silencieux) |

## Résumé des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Créer un utilisateur |
| `POST` | `/items` | Créer un élément |
| `POST` | `/users/{userId}/bookmarks` | Ajouter un marque-page (idempotent) |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Supprimer un marque-page (204 ou 404) |
| `GET` | `/users/{userId}/bookmarks` | Lister les marque-pages (filtre `?collection=`) |
| `GET` | `/users/{userId}/bookmarks/count` | Nombre total de marque-pages |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Obtenir le statut d'un marque-page spécifique |
