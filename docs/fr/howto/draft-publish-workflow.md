# How-to : Workflow Brouillon → Publication → Archive

> **Référence FT** : FT305 (`NENE2-FT/draftlog`) — Machine à états du cycle de vie d'article : transitions one-way draft→published→archived, accès en écriture auteur uniquement, les non-auteurs voient uniquement les articles publiés (les brouillons retournent 404), impossible de modifier les articles publiés, la liste publiée exclut les brouillons et archives, 20 tests / 28 assertions PASS.

Ce guide montre comment implémenter un cycle de vie de contenu où les articles démarrent comme brouillons, sont publiés pour devenir visibles, et peuvent être archivés pour les retirer des listes publiques.

## Schéma

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`CHECK (status IN (...))` garantit que seuls les états connus sont stockés. Les horodatages `published_at` et `archived_at` enregistrent quand les transitions ont eu lieu.

## Machine à états

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| Transition | Précondition | Erreur si violée |
|---|---|---|
| draft → published | le statut doit être `'draft'` | 422 |
| published → archived | le statut doit être `'published'` | 422 |
| published → draft | ❌ non autorisé | — |
| archived → quoi que ce soit | ❌ non autorisé | — |

```php
// Gestionnaire de publication
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// Gestionnaire d'archivage
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/articles` | `X-User-Id` | Créer un article (démarre comme brouillon) |
| `GET` | `/articles` | — | Lister les articles publiés uniquement |
| `GET` | `/articles/{id}` | `X-User-Id` | Obtenir un article (vérification de visibilité) |
| `PUT` | `/articles/{id}` | `X-User-Id` (auteur) | Mettre à jour le brouillon (uniquement si brouillon) |
| `POST` | `/articles/{id}/publish` | `X-User-Id` (auteur) | Publier |
| `POST` | `/articles/{id}/archive` | `X-User-Id` (auteur) | Archiver |

## Les nouveaux articles démarrent comme brouillons

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

Le `status` est toujours `'draft'` à la création quels que soient les champs du corps. Le client ne peut pas choisir le statut initial.

## Visibilité — Les non-auteurs voient uniquement les publiés

```php
// Les non-auteurs peuvent uniquement voir les articles publiés
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

Les articles non publiés (brouillon ou archivé) retournent 404 aux non-auteurs. Cela empêche :
- D'autres utilisateurs de lire des brouillons non publiés
- De révéler si un article a été archivé

## Impossible de modifier les articles publiés

```php
// Gestionnaire de mise à jour — seuls les brouillons sont modifiables
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Une fois publié, le contenu de l'article est figé. L'auteur doit dépublier (non pris en charge ici) pour modifier — dans cette conception, la publication est une porte à sens unique.

## Endpoint de liste — Publiés uniquement

```php
// Repository : SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

L'endpoint de liste filtre sur `status = 'published'` uniquement. Les brouillons et les articles archivés n'apparaissent jamais dans la liste publique.

## Actions auteur uniquement

Toutes les opérations d'écriture (update, publish, archive) vérifient que l'acteur est l'auteur de l'article :

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Autoriser le statut dans le corps de création | Le client démarre l'article comme `'published'` en contournant le workflow de révision |
| Retourner 403 pour GET brouillon par non-auteur | Révèle que l'article existe ; utiliser 404 pour cacher le contenu non publié |
| Autoriser la modification des articles publiés | Modifie rétroactivement le contenu en direct ; viole la confiance des lecteurs |
| Autoriser la transition archive → published | Les articles archivés réapparaissent de façon inattendue |
| Lister les brouillons dans la liste publique | Du contenu non publié est exposé avant d'être prêt |
| Pas de `CHECK (status IN (...))` | Les insertions directes en DB peuvent définir des chaînes de statut arbitraires |
| Les articles archivés retournent 200 aux non-auteurs | Indique aux non-auteurs que le contenu existait et a été archivé |
