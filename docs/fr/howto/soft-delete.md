# Suppression douce (suppression logique)

La suppression douce conserve un enregistrement dans la base de données mais le marque comme supprimé en définissant un timestamp `deleted_at`. Cela permet :
- La fonctionnalité d'annulation / restauration
- Les pistes d'audit (qui a supprimé quoi, quand)
- L'intégrité référentielle (les enregistrements peuvent encore être référencés jusqu'à la purge)

## Schéma

Ajouter une colonne `deleted_at` qui est `NULL` pour les enregistrements actifs et un timestamp pour les enregistrements supprimés :

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = actif, timestamp = supprimé
);
```

## La règle critique : toujours filtrer deleted_at

**Chaque requête qui doit retourner uniquement les enregistrements actifs doit inclure `AND deleted_at IS NULL`.** Oublier ce filtre est l'erreur la plus courante — le code fonctionne mais les données supprimées s'infiltrent dans les réponses API.

```php
// ❌ Filtre manquant — retourne aussi les enregistrements supprimés
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ Exclure les supprimés
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

Cela s'applique à chaque requête : `findById`, `findAll`, `findByUser`, requêtes de pagination, et cibles JOIN.

## Entité

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## Pattern Repository

Utiliser un flag `$includeTrashed = false`. La valeur par défaut de `false` signifie que les appelants doivent explicitement opter pour voir les enregistrements supprimés, ce qui prévient les fuites accidentelles :

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // actifs uniquement
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // non trouvé, ou pas dans la corbeille
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** Suppression permanente — uniquement autorisée depuis la corbeille. */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // garde : doit être dans la corbeille d'abord
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### Utiliser `insert()` pour INSERT

Lors de la création d'enregistrements, utiliser `insert()` (pas `execute()` + `lastInsertId()`) :

```php
// ❌ Deux appels
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ Un seul appel — retourne l'ID de la ligne insérée
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## Endpoints

Une API de suppression douce typique :

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/articles` | Créer |
| `GET` | `/articles` | Enregistrements actifs uniquement |
| `GET` | `/articles/trash` | Enregistrements supprimés uniquement |
| `GET` | `/articles/{id}` | Obtenir un enregistrement (404 si supprimé) |
| `DELETE` | `/articles/{id}` | Suppression douce → 404 si déjà supprimé |
| `POST` | `/articles/{id}/restore` | Restaurer → 404 si pas dans la corbeille |
| `DELETE` | `/articles/{id}/purge` | Suppression physique → 404 si pas dans la corbeille |

**Note sur la sémantique REST :** `DELETE /articles/{id}` se comporte comme une suppression douce, pas une suppression permanente. Si cela surprend les clients, le documenter clairement dans la spec OpenAPI, ou utiliser `POST /articles/{id}/trash` pour l'action de suppression douce.

## Toujours inclure `deleted_at` dans les réponses

Inclure `deleted_at` dans chaque réponse afin que les clients puissent déterminer l'état de la ressource sans requêtes supplémentaires :

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = actif ; timestamp = supprimé
]);
```

## Clés étrangères et suppression douce

Quand d'autres tables font référence à un enregistrement supprimé doucement :
- La suppression douce ne brise pas les contraintes de clé étrangère — la ligne existe encore
- La suppression physique (purge) peut violer les contraintes si des lignes référencées existent
- Avant la purge, vérifier les enregistrements dépendants ou cascader la suppression douce aux dépendants

## Liste de vérification pour la revue de code

- [ ] Chaque requête pour les enregistrements actifs inclut `AND deleted_at IS NULL`
- [ ] Le défaut de `findById()` est `$includeTrashed = false` — les appelants optent explicitement
- [ ] `purge()` protège contre la suppression physique d'enregistrements actifs (vérification `isDeleted()`)
- [ ] `restore()` retourne `null` (→ 404) quand l'enregistrement n'est pas dans la corbeille
- [ ] Les requêtes JOIN sur des tables avec suppression douce filtrent aussi `deleted_at IS NULL` sur la table jointe
- [ ] `deleted_at` est inclus dans les réponses API afin que les clients puissent déterminer l'état
- [ ] Le comportement de `DELETE /articles/{id}` (doux vs physique) est documenté dans OpenAPI
- [ ] Les tests couvrent : supprimer → 404 sur GET, liste exclut les supprimés, restaurer → visible à nouveau, purger → parti partout, double suppression → 404, purger un actif → 404
- [ ] `insert()` est utilisé pour INSERT (pas `execute()` + `lastInsertId()`)
