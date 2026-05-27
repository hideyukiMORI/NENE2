# How-to : Gestion de notes avec tags

## Vue d'ensemble

Ce guide couvre la construction d'une API de gestion de notes taguées avec NENE2. Les fonctionnalités incluent l'isolation par utilisateur, le filtrage par tag, la recherche par mot-clé en texte intégral, et le CRUD avec application de propriété.

**Implémentation de référence** : `../NENE2-FT/notelog/`

---

## Conception du schéma

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` supprime automatiquement les tags quand une note est supprimée.

---

## Table des routes

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/notes` | Utilisateur | Créer une note |
| `GET` | `/notes` | Utilisateur | Lister ses propres notes (`?tag=` ou `?q=` optionnel) |
| `GET` | `/notes/{id}` | Utilisateur | Obtenir une note |
| `PUT` | `/notes/{id}` | Utilisateur | Mettre à jour les champs d'une note |
| `DELETE` | `/notes/{id}` | Utilisateur | Supprimer une note |

---

## Filtrage par tag

Filtrer par tag avec `JOIN` :

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## Recherche par mot-clé

Recherche en texte intégral dans le titre et le corps avec `LIKE` :

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

Le placeholder `:kw` est `'%' . $keyword . '%'`. Les requêtes paramétrées préviennent l'injection SQL.

---

## Analyse des tags

Les tags doivent être des tableaux de chaînes ; normaliser en minuscules :

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // rejeter non-string → 422
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## Pattern IDOR / Propriété

Toutes les opérations de lecture et d'écriture sont scopées à `user_id`. Retourner 404 (pas 403) sur les lectures pour éviter de révéler l'existence ; retourner 403 sur les écritures pour que l'utilisateur sache que la ressource existe mais qu'il manque la permission :

```php
// Lecture : 404 pour prévenir la divulgation d'information
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// Écriture : 403 quand la ressource existe mais n'est pas possédée
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## Mise à jour partielle (PUT)

Accepter `null` pour tout champ signifiant "pas de changement" :

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

Dans le repository, mettre à jour uniquement les champs non-null.

---

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Note créée | 201 |
| Note récupérée / liste | 200 |
| Note mise à jour / supprimée | 200 |
| Pas de X-User-Id | 400 |
| Titre vide | 422 |
| Valeurs de tag non-string | 422 |
| Note non trouvée (ou IDOR) | 404 |
| Mise à jour/suppression de la note d'un autre | 403 |
