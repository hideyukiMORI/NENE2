# Relations de contenu — Liens auto-référentiels M:N typés

Liez des articles (ou n'importe quelle ressource) entre eux en utilisant une **table de jointure avec une colonne `relation_type`**. Prise en charge des types asymétriques (sequel ↔ prequel) avec insertion inverse automatique, et des types symétriques (related, reference) avec la même logique inverse.

**Implémentation de référence :** `FT173 relatedlog` dans [hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Quand utiliser ce pattern

| Utilisez ceci quand… | Envisagez des alternatives quand… |
|---|---|
| Les ressources se lient entre elles avec des arêtes typées | Vous n'avez besoin que de liens "related" non typés |
| Des arêtes asymétriques sont nécessaires (A est une suite de B) | Un simple système de tags est suffisant |
| Les requêtes bidirectionnelles doivent rester rapides | La traversée de graphe sur plusieurs sauts est requise |
| Le type de relation conduit le comportement UI ("Voir les suites") | — |

---

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- auto-relation empêchée au niveau DB
);
```

### Notes de conception

- La contrainte `UNIQUE (article_id, related_id, relation_type)` empêche les arêtes en doublon du même type. La même paire peut avoir **plusieurs** types (ex. A → B à la fois comme `related` et `reference`).
- `CHECK (article_id != related_id)` empêche les boucles sur soi au niveau de la base de données.
- **Les deux sens sont stockés** : ajouter `A → B (sequel)` insère aussi `B → A (prequel)`. Cela rend les requêtes par article triviales (`WHERE article_id = ?`) sans jointures.

---

## Types de relation

```php
enum RelationType: string
{
    case Related   = 'related';    // symétrique : A related B ↔ B related A
    case Sequel    = 'sequel';     // asymétrique : A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // asymétrique : inverse de sequel
    case Reference = 'reference';  // symétrique : citation bidirectionnelle

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related, reference sont auto-inverses
        };
    }
}
```

---

## Opération principale : Ajouter une relation avec inverse automatique

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. Valider que les deux articles existent
    // 2. Vérifier les doublons (la contrainte UNIQUE capturerait aussi cela)
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. Insérer la relation directe
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. Insérer l'inverse (si pas déjà présent)
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### Supprimer une relation (cascade inverse)

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // Supprimer l'inverse
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer un article |
| `GET` | `/articles/{id}` | Obtenir un article avec les stubs des articles liés embarqués |
| `POST` | `/articles/{id}/relations` | Ajouter une relation (+ insère l'inverse automatiquement) |
| `GET` | `/articles/{id}/relations` | Lister les relations (`?type=sequel` pour filtrer) |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | Supprimer une relation (`?type=sequel` requis) |

---

## Formes de réponse

### GET /articles/{id} — avec relations embarquées

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations — requête

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

Le paramètre de requête `type` est **requis** — une paire peut avoir plusieurs types de relation simultanément, donc le type désambiguïse quelle arête supprimer.

---

## Structure de la couche domaine

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # enum avec inverse()
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## Cas limites

| Scénario | Comportement |
|---|---|
| Auto-relation (`article_id == related_id`) | 422 — vérifié dans le gestionnaire avant la DB |
| Type en doublon entre la même paire | 409 Conflict |
| Même paire avec un type différent | 201 — valide, stocké comme lignes séparées |
| Supprimer une relation inexistante | 404 |
| Supprimer sans paramètre `type` | 422 |
| Articles manquants | 404 pour chaque ID invalide |

---

## Voir aussi

- [Système de tags (M:N)](./tagging-system.md) — M:N ressource-vers-tag sans arêtes typées
- [Commentaires en fil](./threaded-comments.md) — `parent_id` auto-référentiel
- [Données hiérarchiques](./hierarchical-data.md) — arbre de chemin matérialisé
- [Système de suivi d'utilisateurs](./user-follow-system.md) — M:N dirigé entre utilisateurs
