# How-to : Construire un système de réactions emoji avec NENE2

Ce guide décrit la construction d'un système de réactions où les utilisateurs réagissent aux posts avec des emojis, avec des compteurs groupés et le suivi des réactions par utilisateur.

**Essai terrain** : FT143
**Version NENE2** : ^1.5
**Sujets couverts** : contrainte UNIQUE(post_id, user_id, emoji), compteurs GROUP BY emoji, suivi des réactions par utilisateur, validation de longueur d'emoji, tests d'intégration MySQL

---

## Ce que nous construisons

- `POST /posts` — créer un post
- `POST /posts/{id}/reactions` — ajouter une réaction (chaîne emoji, une par emoji par utilisateur)
- `DELETE /posts/{id}/reactions/{emoji}` — supprimer une réaction (la sienne uniquement)
- `GET /posts/{id}/reactions` — obtenir les compteurs de réactions et les réactions de l'utilisateur courant

---

## Schéma de la base de données

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (post_id, user_id, emoji)` — une ligne par emoji par utilisateur par post. Le même utilisateur peut réagir avec différents emojis (👍 et ❤️ = 2 lignes). Plusieurs utilisateurs peuvent utiliser le même emoji (chacun obtient sa propre ligne).

---

## Réaction en double → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

Le gestionnaire retourne 409 quand `addReaction()` retourne `false`. Pas de vérification d'existence séparée nécessaire.

---

## Compteurs de réactions groupés avec GROUP BY

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

Trié par compteur décroissant (emoji le plus populaire en premier), puis alphabétiquement comme critère de départage. Le résultat se mappe directement à un `array<string, int>` PHP :

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## Réactions par utilisateur (acteur optionnel)

L'endpoint `GET /reactions` accepte un en-tête optionnel `X-User-Id`. Quand présent, la réponse inclut la liste des emojis que l'appelant a utilisés :

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

Cela permet à l'interface d'afficher quels emojis l'utilisateur courant a déjà utilisés pour réagir.

---

## Validation de l'emoji

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` compte les points de code Unicode, pas les octets. Un seul emoji comme 🧑‍💻 (personne : technologiste) représente 3 points de code ; une limite de 8 caractères accommode la plupart des séquences emoji. Ajuster selon vos besoins.

---

## Tests d'intégration MySQL (FT143)

L'ordre de teardown MySQL est important :

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

Le schéma MySQL utilise `VARCHAR(32)` pour les emojis (pas `TEXT`) pour permettre la colonne dans une clé UNIQUE sans longueur de préfixe. `VARCHAR(32)` stocke jusqu'à 32 caractères, ce qui couvre toutes les séquences emoji.

---

## Pièges courants

| Piège | Correction |
|-------|-----------|
| Autoriser les réactions emoji en double | `UNIQUE (post_id, user_id, emoji)` + capturer `DatabaseConstraintException` |
| Utiliser `strlen()` pour la longueur d'emoji | Utiliser `mb_strlen()` — les emojis sont Unicode multi-octets |
| La colonne de compteur mutable se désynchronise | Compter depuis la table `reactions` avec `GROUP BY emoji` |
| Support emoji MySQL manquant | Utiliser le charset `utf8mb4` et `VARCHAR` (pas `CHAR`) pour la colonne emoji |
| `is_array()` sur le résultat `fetchAll` est toujours true | Ignorer la vérification ; `fetchAll` retourne déjà `array<int, array<string, mixed>>` |
