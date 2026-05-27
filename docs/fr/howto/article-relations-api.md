# How-to : API de relations d'articles

> **Référence FT** : FT334 (`NENE2-FT/relatedlog`) — Relations typées entre articles avec création d'inverse automatique, types de relation symétriques et asymétriques, filtre par type, et stubs de relation intégrés dans les réponses GET, 17 tests / 40+ assertions PASS.

Ce guide montre comment modéliser des relations typées entre des éléments de contenu — `related`, `sequel`, `prequel`, `reference` — avec une gestion automatique des inverses pour que chaque relation reste cohérente dans les deux sens.

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
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` empêche les arêtes de relation en doublon pour le même type. Différents types entre la même paire sont autorisés.

## Types de relation et inverses

| Type soumis | Inverse créé automatiquement |
|---|---|
| `related` | `related` (symétrique) |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference` (symétrique) |

Quand A→B est `sequel`, le serveur insère atomiquement B→A comme `prequel`. Supprimer A→B supprime aussi B→A.

## Endpoints

| Méthode | Chemin | Description |
|--------|------|-------------|
| `POST` | `/articles` | Créer un article |
| `GET` | `/articles/{id}` | Obtenir un article avec les relations intégrées |
| `POST` | `/articles/{id}/relations` | Ajouter une relation |
| `GET` | `/articles/{id}/relations` | Lister les relations (optionnel ?type=) |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | Supprimer une relation (et son inverse) |

## Créer un article

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// Titre manquant
POST /articles  {"body": "No title"}
→ 422

// Corps manquant
POST /articles  {"title": "No body"}
→ 422
```

## GET Article avec relations intégrées

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// Pas encore de relations
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## Ajouter une relation

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// L'inverse est auto-inséré : l'article 2 a maintenant une relation "prequel" pointant vers 1
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### Relation symétrique

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B obtient aussi automatiquement une relation "related" vers A
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### Cas d'erreur

```php
// related_id inconnu
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// Doublon (même paire + même type existe déjà)
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// Auto-relation
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// Type de relation invalide
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### Plusieurs types entre la même paire

La même paire peut avoir plusieurs types de relation différents :

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## Lister les relations

```php
// Toutes les relations
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// Filtrer par type
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// Article inconnu
GET /articles/9999/relations
→ 404
```

## Supprimer une relation

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// L'inverse est aussi supprimé automatiquement
GET /articles/2/relations
→ 200  {"data": []}  // n'a plus de "related" vers 1

// Non trouvé
DELETE /articles/1/relations/2?type=related
→ 404

// Paramètre de requête type manquant
DELETE /articles/1/relations/2
→ 422
```

## Implémentation — Gestion atomique des inverses

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related, reference → symétrique
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

Envelopper les deux insertions/suppressions dans une transaction — si l'une échoue, aucune n'est commitée.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Insérer une relation sans vérifier l'existence de l'article | Violation FK ou insertion silencieuse de 0 ligne ; toujours 404 sur les IDs inconnus |
| Pas de transaction autour de l'insertion directe + inverse | Un échec partiel laisse des données asymétriques (A→B existe mais B→A n'existe pas) |
| Pas de `UNIQUE(article_id, related_id, relation_type)` | Les arêtes en doublon gonflent les compteurs de liste |
| Autoriser les auto-relations | Cycles dans la traversée des relations ; `sequel` de lui-même n'a aucun sens |
| Supposer que tous les types sont symétriques | `sequel`→`sequel` (incorrect) au lieu de `prequel` |
| Supprimer uniquement l'arête directe | L'inverse orphelin reste ; B "voit" toujours A comme un prequel après la suppression de A |
