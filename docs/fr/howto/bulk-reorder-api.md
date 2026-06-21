# How-to : API de réorganisation en masse (ordre par glisser-déposer)

Une interface de glisser-déposer envoie *l'intégralité* du nouvel ordre d'une liste dans une seule requête : `[itemC, itemA, itemD, itemB]`. Le serveur naïf fait un `UPDATE` par élément — N allers-retours et un ordre à moitié appliqué si l'un échoue.

La bonne forme est **une transaction** qui réécrit chaque position avec des valeurs attribuées par le serveur, scopée au tableau du propriétaire. La façon de l'écrire dépend d'une seule chose : **si `position` porte ou non une contrainte `UNIQUE (board_id, position)`.**

> **Piège vérifié (FT352).** SQLite vérifie `UNIQUE` **par ligne** au fur et à mesure qu'un `UPDATE` est appliqué. Donc *toute* instruction qui échange des positions — même un unique `CASE WHEN` sur toutes les lignes — place transitoirement deux lignes à la même position et échoue avec `UNIQUE constraint failed: items.board_id, items.position`. Une seule instruction suffit **uniquement quand `position` n'a pas de contrainte `UNIQUE`** (§1). Avec la contrainte, il faut une écriture en deux phases à l'intérieur d'une transaction (§1.1). La preuve exécutable est dans [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

**Prérequis** : Une table avec une colonne entière `position` scopée à un parent (`board_id`, `list_id`, …). Voir [Content pinning](content-pinning.md) pour le cas à élément unique.

---

## 1. Une seule instruction (pas de contrainte `UNIQUE` sur `position`)

Le client envoie uniquement la *liste ordonnée des ids*. Le serveur dérive les positions de l'index du tableau — il ne fait jamais confiance aux numéros de position fournis par le client. Quand `position` est juste une colonne indexée (pas de `UNIQUE`), une seule instruction suffit :

```php
/**
 * @param list<int> $orderedIds  ids in their new display order
 * @return int  number of rows actually updated
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = array index, not client input
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

Vérifié contre SQLite — réorganiser `[1,2,3,4]` vers les ids `[3,1,4,2]` en une seule instruction :

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

Les positions sont réattribuées `0..n-1` à partir de l'index du tableau, donc le résultat est toujours contigu quel que soit ce que le client a envoyé.

---

## 1.1. Écriture en deux phases quand `position` est `UNIQUE`

Si `UNIQUE (board_id, position)` protège votre ordre (recommandé — cela empêche les positions dupliquées au niveau de la base de données), l'instruction unique ci-dessus échoue dès qu'elle échange deux lignes. Décalez d'abord chaque position dans une plage sans collision, puis attribuez les valeurs finales — les deux étapes dans **une seule transaction** afin que l'état intermédiaire ne soit jamais observable :

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // Phase 1: move every position to a unique negative value (no collisions).
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // Phase 2: assign final positions from the array index.
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` mappe `0,1,2,…` vers `-1,-2,-3,…` — des valeurs distinctes qui ne peuvent pas entrer en collision avec les `0..n-1` finaux. Voir [Use transactions](use-transactions.md) pour la règle `transactional()` (instancier les repositories *à l'intérieur* du callback). Le `testReorderAdjacentSwapDoesNotCollide` de `reorderlog` exerce exactement l'échange qui casse une instruction unique.

---

## 2. Le décompte des lignes affectées est votre contrôle d'intégrité

`execute()` retourne le nombre de lignes correspondant à `WHERE board_id = ? AND id IN (...)`. Comparez-le à la taille de la requête :

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // The client referenced ids that are not in this board (or do not exist).
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

Ce seul contrôle déjoue la plupart de la surface d'attaque ci-dessous : tout id qui appartient à un autre tableau, ou qui n'existe pas, ne correspond tout simplement pas au `WHERE`, donc le décompte est insuffisant et toute la réorganisation est rejetée.

> Enveloppez le contrôle du décompte et l'`UPDATE` dans `transactional()` si vous mutez aussi des lignes liées ; l'`UPDATE` unique est déjà atomique en lui-même. Voir [Use transactions](use-transactions.md).

---

## ATK Assessment — Test d'attaque cracker-mindset

Cible : `PUT /boards/{boardId}/order` avec le corps `{ "ids": [...] }`, authentifié, `board_id` scopé à l'appelant.

### ATK-01 — Réorganiser un tableau que vous ne possédez pas (IDOR) 🚫 BLOCKED

**Attaque** : Envoyer un tableau `ids` valide mais un `boardId` appartenant à un autre utilisateur.
**Résultat** : BLOCKED — la propriété est vérifiée avant la requête (`board.owner_id === caller`), retournant `404` ; même si elle est ignorée, `WHERE board_id = ?` ne correspond à aucune ligne à laquelle appartiennent les ids de l'appelant, donc le décompte affecté est 0 et la requête est rejetée.

---

### ATK-02 — Faire passer en fraude un élément étranger dans l'ordre 🚫 BLOCKED

**Attaque** : Inclure un `id` d'un tableau différent pour le déplacer ou le faire fuiter.
**Résultat** : BLOCKED — `WHERE board_id = ? AND id IN (...)` exclut l'id étranger ; décompte affecté < taille de la requête → `422`, aucune écriture partielle.

---

### ATK-03 — Ordre partiel (omettre des ids pour créer des trous) 🚫 BLOCKED

**Attaque** : Envoyer seulement la moitié des ids du tableau pour laisser le reste à des positions périmées.
**Résultat** : BLOCKED — le handler exige que l'ensemble soumis soit égal à l'ensemble actuel des ids du tableau (décompte + appartenance), rejetant les payloads incomplets.

---

### ATK-04 — Injecter des numéros de position explicites 🚫 BLOCKED

**Attaque** : Envoyer `{ "ids": [...], "positions": [99, -1, ...] }` en espérant que le serveur les honore.
**Résultat** : BLOCKED — le serveur ignore toute position client ; `position` est l'index du tableau. Les champs de corps supplémentaires sont supprimés par le DTO readonly.

---

### ATK-05 — Injection SQL via id / position 🚫 BLOCKED

**Attaque** : `ids: ["1); DROP TABLE items;--", ...]`.
**Résultat** : BLOCKED — chaque id et position est un paramètre lié ; les placeholders `CASE`/`IN` sont générés par décompte, jamais par concaténation de chaînes.

---

### ATK-06 — Ids dupliqués pour corrompre les positions 🚫 BLOCKED

**Attaque** : `ids: [5, 5, 5]` pour qu'une ligne reçoive plusieurs branches `CASE`.
**Résultat** : BLOCKED — le DTO valide l'unicité des ids ; SQLite appliquerait de toute façon le dernier `WHEN` correspondant, et le contrôle du décompte (`distinct ids` vs taille du tableau) échoue en premier.

---

### ATK-07 — Payload surdimensionné (DoS) 🚫 BLOCKED

**Attaque** : Poster 1 000 000 d'ids pour construire un `CASE` géant.
**Résultat** : BLOCKED — `RequestSizeLimitMiddleware` plafonne le corps, et le handler rejette les tableaux plus grands que le nombre de lignes du tableau.

---

### ATK-08 — Ids non entiers / négatifs 🚫 BLOCKED

**Attaque** : `ids: ["abc", -1, 1.5]`.
**Résultat** : BLOCKED — la validation du DTO convertit/valide chaque entrée comme un entier positif (`422` en cas d'échec) avant l'exécution de tout SQL.

---

### ATK-09 — Course de réorganisations concurrentes 🚫 BLOCKED

**Attaque** : Déclencher deux réorganisations simultanément pour entrelacer les positions.
**Résultat** : BLOCKED — chaque réorganisation s'exécute dans une seule transaction ; le dernier écrivain l'emporte avec un ordre `0..n-1` entièrement cohérent, jamais un mélange entrelacé. L'écriture en deux phases (§1.1) garde l'état intermédiaire à l'intérieur de la transaction, donc un lecteur concurrent ne voit jamais un ordre partiel ou en collision.

---

### ATK-10 — Débordement de position / résultat non contigu 🚫 BLOCKED

**Attaque** : Espérer que des réorganisations répétées fassent dériver les positions vers des valeurs énormes ou éparses.
**Résultat** : BLOCKED — chaque réorganisation réécrit les positions à partir de `0`, donc la colonne est toujours dense et bornée par le nombre de lignes.

---

### ATK-11 — Ordre vide pour effacer les positions 🚫 BLOCKED

**Attaque** : `ids: []`.
**Résultat** : BLOCKED — les tableaux vides échouent à la validation (`min 1`), et un `IN ()` vide serait une erreur de syntaxe qui ne s'exécute jamais.

---

### ATK-12 — Énumération inter-locataires des ids de tableau 🚫 BLOCKED

**Attaque** : Itérer sur `boardId` pour découvrir lesquels existent via des réponses différentes.
**Résultat** : BLOCKED — les tableaux inconnus et non possédés retournent tous deux un `404` identique ; aucun oracle de décompte ou de timing ne les distingue.

---

### ATK Summary

| ID | Attaque | Résultat |
|----|--------|--------|
| ATK-01 | Réorganiser un tableau non possédé (IDOR) | 🚫 BLOCKED |
| ATK-02 | Faire passer un élément étranger | 🚫 BLOCKED |
| ATK-03 | Ordre partiel / trous | 🚫 BLOCKED |
| ATK-04 | Injecter des positions explicites | 🚫 BLOCKED |
| ATK-05 | Injection SQL | 🚫 BLOCKED |
| ATK-06 | Ids dupliqués | 🚫 BLOCKED |
| ATK-07 | Payload surdimensionné | 🚫 BLOCKED |
| ATK-08 | Ids non entiers / négatifs | 🚫 BLOCKED |
| ATK-09 | Course de réorganisations concurrentes | 🚫 BLOCKED |
| ATK-10 | Débordement / éparpillement de position | 🚫 BLOCKED |
| ATK-11 | Ordre vide | 🚫 BLOCKED |
| ATK-12 | Énumération des ids de tableau | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED.** Aucun constat critique. La combinaison de *positions attribuées par le serveur* (index du tableau, jamais l'entrée client) et du *contrôle d'intégrité décompte affecté / ensemble d'ids* contre un `WHERE` scopé au tableau ferme la surface de réorganisation. Le seul piège de *correction* (qui n'est pas un constat de sécurité) est la contrainte `UNIQUE (board_id, position)` : elle fait échouer une instruction `CASE` unique sur tout échange, donc utilisez l'écriture transactionnelle en deux phases du §1.1 — vérifiée dans [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

---

## Guides associés

- [Content pinning](content-pinning.md) — gestion de position à élément unique
- [Pin / bookmark ordering](pin-bookmark-ordering.md) — ordre par utilisateur
- [Use transactions](use-transactions.md) — envelopper atomiquement les réorganisations multi-tables
