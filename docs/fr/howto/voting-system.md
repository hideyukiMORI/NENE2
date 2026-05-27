# Système de vote (vote positif / vote négatif)

Permettre aux utilisateurs de voter positivement ou négativement sur des éléments. Chaque utilisateur peut émettre au maximum un vote par élément. Voter deux fois dans la même direction désactive le vote. Voter dans la direction opposée change le vote.

## Vue d'ensemble

Un système de vote implique :
- **Voter** : vote positif ou négatif sur un élément
- **Bascule** : voter deux fois dans la même direction supprime le vote
- **Changement** : voter dans la direction opposée remplace le vote actuel
- **Score** : votes positifs − votes négatifs, retourné avec chaque réponse de vote
- **Vote actuel** : récupérer le vote actuel d'un utilisateur pour un élément (pour la mise en évidence dans l'UI)

## Schéma de base de données

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

La contrainte `UNIQUE (user_id, item_id)` applique un vote par utilisateur par élément au niveau de la base de données. `CHECK (direction IN ('up', 'down'))` empêche les valeurs invalides même si la validation au niveau applicatif est contournée.

## Direction comme enum

Utiliser un enum backed pour empêcher les valeurs de direction invalides d'atteindre le repository :

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

Analyser avec `VoteDirection::tryFrom($dirStr)` — retourne `null` pour les entrées invalides, permettant une gestion propre des 422 sans match/switch.

## Logique de bascule et de changement

Les trois cas (désactiver, changer de direction, nouveau vote) sont gérés dans le repository :

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // même direction → désactiver
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // direction différente → changer
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // pas de vote existant → insérer
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

La valeur de retour `?VoteDirection` permet au handler de savoir si le vote est maintenant défini (`'up'`/`'down'`) ou supprimé (`null`).

## Retourner le score avec chaque vote

Inclure le score mis à jour dans la réponse de vote pour que les clients puissent rafraîchir les compteurs sans GET séparé :

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## Calcul du score

Des requêtes COUNT séparées par direction sont plus simples et plus lisibles qu'un seul GROUP BY :

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = votes positifs - votes négatifs`. Zéro est l'état initial avant qu'aucun vote ne soit émis.

## État du vote de l'utilisateur

Un endpoint séparé permet à l'UI de montrer dans quelle direction l'utilisateur actuel a voté (pour la mise en évidence des boutons) :

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

Retourne `null` quand l'utilisateur n'a pas voté (ou a désactivé son vote par bascule).

## Propriétés de sécurité

| Propriété | Implémentation |
|-----------|----------------|
| Un vote par utilisateur par élément | Contrainte DB `UNIQUE (user_id, item_id)` |
| Direction invalide rejetée | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| Utilisateur/élément inconnu | Retourne 404 — pas de fuite d'existence de ressource |
| Sécurité de la bascule | Vérifie le vote actuel avant DELETE/UPDATE |

## Résumé des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Créer un utilisateur |
| `POST` | `/items` | Créer un élément |
| `POST` | `/items/{itemId}/vote` | Voter, changer ou basculer un vote |
| `GET` | `/items/{itemId}/score` | Obtenir les votes positifs, votes négatifs et score |
| `GET` | `/items/{itemId}/vote/{userId}` | Obtenir le vote actuel d'un utilisateur pour un élément |
