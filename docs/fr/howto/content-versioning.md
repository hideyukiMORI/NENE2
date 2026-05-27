# Guide d'implémentation du versionnage de contenu

## Vue d'ensemble

Ce guide explique comment implémenter le versionnage de contenu avec NENE2 (conservation de tout l'historique, référence à une version spécifique, rollback).
Les modifications d'articles sont conservées en mode append-only avec tous les versions, et un rollback vers n'importe quelle révision est possible.

---

## Schéma DB

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

`articles` est la table parente qui contient la version actuelle la plus récente.
`article_versions` accumule l'historique des modifications de contenu en mode **append-only**.

---

## Conception des endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| POST | `/articles` | Créer un article (commit initial en v1) |
| GET | `/articles/{id}` | Obtenir la dernière version |
| PUT | `/articles/{id}` | Mettre à jour (appender une nouvelle version) |
| GET | `/articles/{id}/versions` | Liste des versions |
| GET | `/articles/{id}/versions/{version}` | Obtenir une version spécifique |
| POST | `/articles/{id}/rollback` | Rollback vers la version indiquée |

---

## Points clés de conception

### Versionnage append-only

Les deux opérations update et rollback **appendenent une nouvelle version**. L'existant n'est pas écrasé :

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

**Avantage** : n'importe quelle version est toujours accessible. Le rollback DB et le rollback logique sont indépendants.

### Rollback = Sauvegardé comme nouvelle version

Le rollback est l'opération "créer une nouvelle version avec le contenu d'une version spécifique".
Cela permet que **le rollback lui-même reste dans l'historique**, utilisable pour les audits :

```
v1: Titre original
v2: Titre modifié
v3: Titre original  ← rollback vers v1 sauvegardé ici comme nouvelle version
```

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target      = $this->findVersion($id, $version);   // cible du rollback
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    // Sauvegarder le contenu de la version cible comme nouvelle version
    $this->db->insert('UPDATE articles SET title = ?, body = ?, current_version = ? ...', [...]);
    $this->db->insert('INSERT INTO article_versions ...', [$id, $nextVersion, $target['title'], $target['body'], $now]);
    return true;
}
```

### La liste des versions exclut le corps

L'API de liste retourne uniquement les métadonnées sans `body`. `body` est inclus lors de la récupération individuelle :

```
GET /articles/{id}/versions → [{version: 1, title: "...", created_at: "..."}, ...]
GET /articles/{id}/versions/1 → {version: 1, title: "...", body: "...", created_at: "..."}
```

### PHPStan : cohérence entre valeur de retour nullable et vérification null

Lors du rappel de `find()` après un rollback, PHPStan peut considérer la vérification null comme "toujours vraie".
Concevoir `formatArticle(?array)` pour accepter null évite d'avoir besoin d'assert :

```php
// Incorrect : l'assert est considéré "toujours vrai" par PHPStan
$article = $this->repo->find($id);
assert($article !== null);
return $this->json->create($this->formatArticle($article));

// Correct : concevoir formatArticle pour accepter null
return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
```

---

## Exemples de réponse

### POST /articles

```json
{
  "id": 1,
  "title": "My Post",
  "body": "Hello world",
  "current_version": 1,
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-01-01T00:00:00Z"
}
```

### GET /articles/{id}/versions

```json
{
  "versions": [
    {"id": 1, "article_id": 1, "version": 1, "title": "My Post", "created_at": "..."},
    {"id": 2, "article_id": 1, "version": 2, "title": "Updated", "created_at": "..."}
  ],
  "count": 2
}
```

### POST /articles/{id}/rollback

```json
{
  "id": 1,
  "title": "My Post",
  "current_version": 3,
  "rolled_back_from": 1
}
```

---

## Implémentation de référence

`../NENE2-FT/contentvlog/` — FT162 field trial (18 tests · historique append-only · rollback)
