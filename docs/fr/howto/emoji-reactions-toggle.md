# How-to : Réactions emoji avec bascule et compteurs groupés

> **Référence FT** : FT263 (`NENE2-FT/reactionlog`) — Réactions emoji : bascule (ajout/suppression), compteurs groupés, liste de réactions par utilisateur

Démontre une API de réactions où chaque utilisateur peut réagir à n'importe quelle cible (post, commentaire, etc.) avec n'importe quel emoji ou type de réaction. Un seul endpoint `PUT` bascule la réaction : l'ajoute si absente, la supprime si déjà présente. Les compteurs groupés par type de réaction sont retournés dans une requête de résumé. Une contrainte `UNIQUE` composite impose une réaction par utilisateur par type, et `DatabaseConstraintException` gère les races de bascule concurrentes.

---

## Routes

| Méthode   | Chemin                                               | Description                              |
|-----------|------------------------------------------------------|------------------------------------------|
| `PUT`     | `/reactions/{targetType}/{targetId}`                 | Basculer une réaction (ajout ou suppression) |
| `DELETE`  | `/reactions/{targetType}/{targetId}/{reactionType}`  | Supprimer explicitement une réaction spécifique |
| `GET`     | `/reactions/{targetType}/{targetId}`                 | Obtenir le résumé des réactions (compteurs groupés) |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` impose un enregistrement par combinaison unique (cible, utilisateur, réaction). Une tentative d'insertion d'un doublon lève une violation de contrainte, que l'application capture comme `DatabaseConstraintException`.

`target_type` permet au même système de réactions de servir plusieurs types d'entités (`post`, `comment`, `message`) sans tables séparées.

---

## Pattern de bascule

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // la réaction a été supprimée
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race condition : bascule concurrente du même utilisateur — traiter comme supprimé
        return false;
    }

    return true;   // la réaction a été ajoutée
}
```

**Flux** :
1. `SELECT` pour vérifier si la réaction existe.
2. Si trouvée : `DELETE` → retourner `false` (supprimée).
3. Si non trouvée : `INSERT` → retourner `true` (ajoutée).
4. Si l'`INSERT` échoue avec une violation UNIQUE (`DatabaseConstraintException`) : une requête concurrente a inséré la même ligne entre notre `SELECT` et notre `INSERT`. Traiter cela comme "supprimé" (la bascule concurrente a gagné) → retourner `false`.

**Pourquoi `SELECT` puis `INSERT` ?** Une alternative est `INSERT OR IGNORE` et vérifier `changes() == 0` pour détecter le cas où la ligne existait déjà. L'approche `SELECT` explicite rend l'intention plus claire et produit une valeur de retour plus propre (ajouté vs supprimé) sans nécessiter une requête ultérieure.

---

## Contrôleur : 201 à l'ajout, 200 à la suppression

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

`201 Created` quand la réaction est ajoutée ; `200 OK` quand elle est supprimée. Le champ `added` dans le corps de la réponse permet aux clients de distinguer les deux cas sans vérifier le code de statut.

**Pourquoi `PUT` pour la bascule ?** `PUT` est idempotent par sémantique HTTP. Une bascule mono-utilisateur est idempotente en effet (deux `PUT` identiques reviennent à l'état original). Alternativement, `POST` est acceptable pour une bascule non idempotente ; le choix dépend de la convention d'équipe.

---

## Résumé des compteurs groupés

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

Deux requêtes :
1. Compteurs groupés : `GROUP BY reaction_type ORDER BY cnt DESC` — les plus populaires en premier.
2. Réactions par utilisateur (si `$userId` est fourni) : quels types de réactions cet utilisateur a appliqués.

`ORDER BY cnt DESC` met les réactions les plus utilisées en premier, correspondant à la priorité d'affichage typique.

---

## Exemple de réponse de résumé

**Requête** : `GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` est une map du type de réaction au compteur. `user_reactions` est la liste des réactions qu'`alice` a appliquées. Le client peut mettre en évidence `👍` pour indiquer la réaction active d'alice.

---

## Endpoint de suppression explicite

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

`DELETE /reactions/{targetType}/{targetId}/{reactionType}` avec `user_id` dans le corps supprime une réaction spécifique sans sémantique de bascule. Utile quand le client veut supprimer un type de réaction spécifique quel que soit l'état actuel.

Retourne 404 si aucune réaction correspondante n'a été trouvée (`$count == 0`).

---

## Contrainte UNIQUE composite comme filet de sécurité

La contrainte `UNIQUE(target_id, target_type, reaction_type, user_id)` :
- **Imposition primaire** : empêche les réactions en double au niveau DB.
- **Bénéfice secondaire** : capture les races qui passent malgré la vérification `SELECT`.
- **Logique applicative** : `toggle()` capture `DatabaseConstraintException` et le traite comme une suppression.

Sans la contrainte, une race entre deux requêtes `PUT` concurrentes du même utilisateur insérerait deux lignes identiques. La contrainte + le gestionnaire d'exception maintient l'invariant (une ligne par utilisateur par type de réaction) même sous concurrence.

---

## Notes de conception

| Décision | Choix | Justification |
|---|---|---|
| Endpoint de bascule | `PUT` | Sémantiquement approprié ; idempotent |
| Identité de réaction | Clé composite à 4 colonnes | Pas de table de types de réaction séparée nécessaire |
| `target_type` | Paramètre PATH | Permet à un endpoint de servir plusieurs types d'entités |
| `user_id` dans le corps de requête | Champ requis | Évite de nécessiter un middleware d'auth pour ce FT |
| `user_id` dans le résumé | Paramètre de requête | Optionnel — le résumé est public ; le détail par utilisateur est opt-in |

---

## Guides associés

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — table de jointure M:N avec INSERT OR IGNORE pour la déduplication de tags
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — clés uniques composites comme filets de sécurité au niveau DB
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — opérations atomiques quand plusieurs écritures doivent réussir ou échouer ensemble
