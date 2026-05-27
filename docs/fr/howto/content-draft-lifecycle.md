# How-to : Construire un cycle de vie de brouillon de contenu (Draft → Published → Archived) avec NENE2

Ce guide explique comment construire un système de gestion d'articles avec une machine à états draft/publish/archive, où seul l'auteur peut effectuer les transitions et où seuls les articles publiés sont visibles par les lecteurs.

**Field Trial** : FT142
**Version NENE2** : ^1.5
**Sujets couverts** : machine à états avec enum, gardes de transition, vérification de propriété de l'auteur, liste publique filtrée par statut, stabilité du tri à la même seconde

---

## Ce que nous construisons

- `POST /articles` — créer un article (commence toujours en `draft`)
- `GET /articles` — lister uniquement les articles publiés
- `GET /articles/{id}` — obtenir un article (l'auteur voit n'importe quel statut ; les autres voient seulement `published`)
- `PUT /articles/{id}` — modifier un article (draft uniquement, auteur uniquement)
- `POST /articles/{id}/publish` — transition `draft → published` (auteur uniquement)
- `POST /articles/{id}/archive` — transition `published → archived` (auteur uniquement)

---

## Schéma de la base de données

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

`published_at` et `archived_at` sont nullables — ils ne sont définis qu'à la transition correspondante.

---

## Enum ArticleStatus avec gardes de transition

```php
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canPublish(): bool
    {
        return $this === self::Draft;
    }

    public function canArchive(): bool
    {
        return $this === self::Published;
    }
}
```

Le gestionnaire lit le statut actuel, appelle la méthode de garde et retourne 422 si la transition est invalide :

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

Transitions valides :
- `draft → published` (via publish)
- `published → archived` (via archive)
- Il n'y a pas de retour vers draft.

---

## Visibilité de l'auteur — brouillon caché aux autres

Les non-auteurs ne peuvent pas lire les brouillons. Retourner 404 (pas 403) pour éviter de révéler que l'article existe :

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

Retourner 403 confirmerait que l'article existe. 404 est le bon choix pour du contenu qui n'est pas encore public.

---

## Stabilité du tri à la même seconde

Quand plusieurs articles sont publiés dans la même seconde, `ORDER BY published_at DESC` seul donne un ordre non déterministe. Ajouter `id DESC` comme critère de départage :

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

Un `id` plus élevé signifie créé plus tard, ce qui effectivement trie par ordre d'insertion dans la même seconde.

---

## Pièges courants

| Piège | Correction |
|-------|-----------|
| Retourner 403 pour les lectures de brouillon par des non-auteurs | Retourner 404 — empêche la révélation de l'existence du contenu |
| Autoriser la réouverture `published → draft` | `canEdit()` retourne false sauf pour `Draft` ; pas d'endpoint "dépublication" |
| Publier un article déjà publié | `canPublish()` retourne false pour `Published` → 422 |
| Archiver un brouillon | `canArchive()` retourne false sauf pour `Published` → 422 |
| Ordre de liste non déterministe au même timestamp | Ajouter `id DESC` comme tri secondaire |
