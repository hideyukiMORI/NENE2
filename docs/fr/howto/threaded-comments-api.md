# How-to : API de commentaires en fil

> **Référence FT** : FT343 (`NENE2-FT/threadlog`) — Système de commentaires en fil à deux niveaux avec suppression par tombstone (contenu remplacé par `[deleted]`), enforcement de profondeur de réponse, isolation scopée au post, et prévention de réponse à un commentaire supprimé, 14 tests / 40+ assertions PASS.

Ce guide montre comment construire un système de commentaires avec un niveau de réponses : les commentaires racine peuvent recevoir des réponses, mais les réponses ne peuvent pas être répondues (profondeur maximale = 1). Les commentaires supprimés sont tombstonés, préservant la structure du fil.

## Schéma

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- identifiant opaque du post
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = commentaire racine
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = commentaire racine ; `parent_id IS NOT NULL` = réponse.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/posts/{postId}/comments` | Créer un commentaire racine |
| `GET` | `/posts/{postId}/comments` | Lister les commentaires avec les réponses |
| `GET` | `/posts/{postId}/comments/{id}` | Obtenir un commentaire unique |
| `POST` | `/posts/{postId}/comments/{id}/replies` | Ajouter une réponse |
| `DELETE` | `/posts/{postId}/comments/{id}` | Suppression douce (tombstone) |

## Créer un commentaire racine

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Great post!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Great post!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// Champs manquants
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // content requis
```

## Lister les commentaires

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Root comment",
      "replies": [
        {"id": 2, "author": "bob", "content": "My reply", "parent_id": 1}
      ]
    }
  ]
}
```

Les commentaires sont scopés à `post_id`. Les commentaires de `post-1` n'apparaissent jamais dans la liste de `post-2`.

## Obtenir un commentaire unique

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Root comment",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## Ajouter une réponse (profondeur max = 1)

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "My reply"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "My reply"
}

// Répondre à une réponse est rejeté (la profondeur serait 2)
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Deep reply"}
→ 409  // limite de profondeur dépassée

// Répondre à un commentaire inexistant
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// Répondre à un commentaire supprimé
// (commentaire 1 déjà supprimé)
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // impossible de répondre à un commentaire supprimé

// Champs manquants → 422
```

### Implémentation de la vérification de profondeur

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // Seuls les commentaires racines (parent_id = null) peuvent avoir des réponses
    return $row['parent_id'] === null;
}
```

Retourner 409 quand `canReceiveReply()` retourne false.

## Suppression par tombstone

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// Le commentaire supprimé apparaît toujours dans la liste (tombstone)
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // les réponses restent visibles
    }
  ]
}
```

Le tombstoning préserve la structure du fil. Les réponses restent visibles même après la suppression du parent.

```php
// Supprimer un commentaire déjà supprimé → 404
DELETE /posts/post-1/comments/1  (déjà supprimé)
→ 404

// Commentaire inconnu → 404
DELETE /posts/post-1/comments/999
→ 404
```

### SQL de tombstone

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- Correspond uniquement aux lignes non supprimées
-- 0 lignes mises à jour → 404
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Supprimer physiquement un commentaire parent | Les réponses deviennent des orphelines ; la structure du fil se brise |
| Permettre une profondeur d'imbrication illimitée | Les chaînes profondes créent des requêtes SQL récursives ou des débordements de pile |
| Retourner 404 pour réponse-à-supprimé | Masquer l'état du parent déroute les clients ; 409 avec un `detail` clair est mieux |
| Pas de scope `post_id` dans les requêtes | Les commentaires d'autres posts apparaissent dans la liste |
| Vérifier la profondeur uniquement côté client | L'attaquant contourne la vérification en envoyant des requêtes API directes |
| Afficher l'auteur/contenu du commentaire supprimé | Annule l'objectif de la suppression ; toujours tombstoner |
