# How-to : API de réactions emoji

> **Référence FT** : FT306 (`NENE2-FT/emojilog`) — Réactions emoji : UNIQUE(post_id, user_id, emoji) permet le même emoji par plusieurs utilisateurs mais empêche un utilisateur de réagir deux fois avec le même emoji, mb_strlen max 8 caractères, urldecode() pour l'emoji dans le chemin DELETE, user_reactions montre les réactions de l'acteur courant, réactions ordonnées par count DESC, 18 tests / 28 assertions PASS.

Ce guide montre comment implémenter un système de réactions emoji où plusieurs utilisateurs peuvent réagir à un post avec n'importe quel emoji, mais chaque utilisateur ne peut utiliser un emoji donné qu'une seule fois par post.

## Schéma

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

`UNIQUE(post_id, user_id, emoji)` autorise :
- Même emoji par plusieurs utilisateurs : Alice et Bob peuvent tous les deux réagir avec `👍`
- Différents emojis par le même utilisateur : Alice peut utiliser à la fois `👍` et `❤️`

Mais empêche :
- Même utilisateur + même emoji deux fois : Alice ne peut pas utiliser `👍` sur le même post deux fois

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id` (optionnel) | Obtenir compteurs + réactions de l'acteur |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | Ajouter une réaction |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | Supprimer une réaction |

## Ajouter une réaction — Validation stricte

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- La vérification `is_string()` rejette les types non-chaîne
- `trim()` avant la vérification vide empêche les emojis uniquement composés d'espaces
- `mb_strlen()` — pas `strlen()` — pour un comptage correct de caractères multi-octets
- Ajout en doublon → 409 Conflict (pas 422)

## Supprimer une réaction — Décodage URL pour l'emoji dans le chemin

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

Les caractères emoji dans les segments de chemin URL doivent être encodés par les clients. `urldecode()` restaure l'emoji original pour la recherche en DB. Exemple : `DELETE /posts/1/reactions/%F0%9F%91%8D` → recherche `👍`.

## Réponse avec compteurs de réactions

```php
// Grouper par emoji, compter, ordonner par count DESC
$counts = $this->repository->getReactionCounts($postId);

// Si l'acteur est fourni, montrer quels emojis il a utilisés
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] ordonné par count DESC
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] pour l'acteur courant
]);
```

`user_reactions` est vide quand aucun en-tête `X-User-Id` n'est fourni — ce champ montre les réactions du spectateur courant pour aider les frontends à mettre en évidence ses réactions actives.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| `UNIQUE(post_id, user_id)` (sans colonne emoji) | Un utilisateur peut seulement jamais utiliser un emoji par post |
| `strlen()` pour la vérification de longueur d'emoji | Les emojis multi-octets comme `🎉` (4 octets) seraient comptés incorrectement |
| Pas de `urldecode()` sur l'emoji du chemin | `👍` comme `%F0%9F%91%8D` ne correspond jamais au `👍` stocké |
| Retourner 404 pour une réaction en double | Cache la sémantique 409 — les réactions en double sont des conflits, pas des ressources manquantes |
| Pas de limite de longueur d'emoji | Des chaînes de longueur arbitraire stockées comme colonne emoji |
| `user_reactions` vide quand pas d'acteur, mais inclure quand même la clé | Omettre ou retourner `[]` — les deux sont corrects, mais documenter le comportement |
| `trim()` après la vérification vide | Un emoji `"  "` uniquement composé d'espaces passerait comme valide |
