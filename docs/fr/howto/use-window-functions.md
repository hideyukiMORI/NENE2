# How-to : Utiliser les fonctions de fenêtre SQLite

Les fonctions de fenêtre calculent une valeur sur un ensemble de lignes *liées à la ligne courante* sans les réduire en un seul groupe (comme le fait `GROUP BY`). Elles sont le bon outil pour le **classement**, les **totaux cumulés** et la **comparaison période sur période** — trois patterns malaisés et lents à réaliser en PHP après coup.

SQLite prend en charge les fonctions de fenêtre depuis la **3.25.0** (2018). NENE2 est livré avec le SQLite intégré de PHP, qui dépasse largement cette version ; MySQL 8.0+ et PostgreSQL prennent aussi en charge la même syntaxe, donc ces requêtes sont portables entre les trois adaptateurs ciblés par NENE2.

Vous les exécutez via `DatabaseQueryExecutorInterface::fetchAll()` comme n'importe quelle autre requête de lecture — il n'y a aucun support spécial du framework à câbler.

**Prérequis** : Vous avez un repository soutenu par `DatabaseQueryExecutorInterface`. Voir [Add a database-backed endpoint](add-database-endpoint.md).

---

## 1. L'anatomie d'une fenêtre

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — redémarre la fenêtre pour chaque jeu (omettre pour traiter toutes les lignes comme une seule fenêtre).
- `ORDER BY points DESC` — ordonne *à l'intérieur* de la partition ; cela définit ce que « premier » et « précédent » signifient.

Quand plusieurs colonnes réutilisent la même fenêtre, nommez-la une fois avec une clause `WINDOW` :

```sql
SELECT player, game, points,
       ROW_NUMBER()  OVER w AS rn,
       RANK()        OVER w AS rnk,
       DENSE_RANK()  OVER w AS drnk
FROM scores
WINDOW w AS (PARTITION BY game ORDER BY points DESC)
ORDER BY game, points DESC;
```

---

## 2. Classement : `ROW_NUMBER` vs `RANK` vs `DENSE_RANK`

Les trois ne diffèrent que dans la façon dont ils traitent les égalités. Étant donné deux joueurs à égalité à 150 en `chess` :

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — toujours unique (1, 2, 3). À utiliser pour des curseurs de pagination stables ou « choisir exactement un par groupe ».
- **`RANK`** — les égalités partagent un rang, puis il saute (1, 1, 3). À utiliser pour les classements où « 1er ex æquo » est significatif.
- **`DENSE_RANK`** — les égalités partagent un rang, sans trou (1, 1, 2). À utiliser pour les compartiments de « palier » / de note.

> Choisissez la fonction de rang délibérément. Un classement qui affiche deux joueurs « rang 2 » et aucun « rang 1 » est presque toujours une confusion `RANK`/`ROW_NUMBER`.

Dans une méthode de repository :

```php
/**
 * @return list<array{player: string, game: string, points: int, rank: int}>
 */
public function topRankedByGame(string $game): array
{
    return $this->executor->fetchAll(
        'SELECT player, game, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores
         WHERE game = :game
         ORDER BY points DESC',
        ['game' => $game],
    );
}
```

---

## 3. Total cumulé : un agrégat comme fenêtre

Tout agrégat (`SUM`, `AVG`, `COUNT`, …) devient un agrégat *cumulé* lorsqu'on lui donne une clause `OVER (...)` et un cadre :

```sql
SELECT created_at, points,
       SUM(points) OVER (ORDER BY created_at ROWS UNBOUNDED PRECEDING) AS running_total
FROM scores
ORDER BY created_at;
```

| created_at | points | running_total |
|------------|--------|---------------|
| 2026-01-01 | 100    | 100           |
| 2026-01-02 | 150    | 250           |
| 2026-01-03 | 150    | 400           |
| 2026-01-04 | 90     | 490           |

`ROWS UNBOUNDED PRECEDING` signifie « chaque ligne depuis le début de la partition jusqu'à la ligne courante ». Sans cadre explicite, la valeur par défaut (`RANGE UNBOUNDED PRECEDING`) additionne **toutes les lignes à égalité sur la valeur de l'`ORDER BY`** dans la même étape — une source subtile de totaux erronés quand les horodatages se chevauchent. Soyez explicite avec `ROWS` quand vous voulez un véritable total cumulé ligne par ligne.

---

## 4. Période sur période : `LAG` et `LEAD`

`LAG` lit une colonne de la ligne *précédente* dans la fenêtre ; `LEAD` lit la *suivante*. Cela calcule un delta sans auto-jointure :

```sql
SELECT created_at, points,
       points - LAG(points) OVER (ORDER BY created_at) AS delta
FROM scores
ORDER BY created_at;
```

| created_at | points | delta |
|------------|--------|-------|
| 2026-01-01 | 100    | *null* |
| 2026-01-02 | 150    | 50    |
| 2026-01-03 | 150    | 0     |
| 2026-01-04 | 90     | −60   |

Le `delta` de la première ligne est `NULL` car il n'y a pas de ligne précédente. Fournissez une valeur par défaut pour éviter la gestion des null en aval : `LAG(points, 1, 0)` retourne `0` au lieu de `NULL` pour la première ligne. Mappez `NULL` vers une valeur typée dans votre DTO plutôt que de le laisser fuiter dans la réponse JSON.

---

## 5. Filtrer sur un résultat de fenêtre

Vous **ne pouvez pas** mettre une fonction de fenêtre dans une clause `WHERE` — les fenêtres sont évaluées *après* le `WHERE`. Enveloppez la requête dans une sous-requête (ou CTE) et filtrez sur l'alias :

```sql
WITH ranked AS (
    SELECT player, game, points,
           ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC) AS rn
    FROM scores
)
SELECT player, game, points
FROM ranked
WHERE rn <= 3            -- top 3 per game
ORDER BY game, points DESC;
```

Cette forme « top-N-par-groupe » est l'usage réel le plus courant ; privilégiez-la plutôt que `N` requêtes `LIMIT` séparées.

---

## 6. La retourner comme réponse typée

Gardez le SQL dans le repository et mappez vers un DTO readonly avant qu'il n'atteigne le contrôleur — ne passez pas le `array` brut à travers la frontière :

```php
final readonly class GameRanking
{
    public function __construct(
        public int $rank,
        public string $player,
        public int $points,
    ) {}
}
```

```php
/** @return list<GameRanking> */
public function topRankedByGame(string $game): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores WHERE game = :game ORDER BY points DESC',
        ['game' => $game],
    );

    return array_map(
        static fn (array $r): GameRanking => new GameRanking(
            rank: (int) $r['rank'],
            player: (string) $r['player'],
            points: (int) $r['points'],
        ),
        $rows,
    );
}
```

SQLite retourne toutes les valeurs de colonne comme des chaînes via PDO, donc castez (`(int)`, `(float)`) à l'intérieur du mapper — le résultat de la fonction de fenêtre (`rank`, `running_total`) ne fait pas exception.

---

## Pièges

- **`WHERE` ne voit pas les alias de fenêtre** — filtrez dans une requête externe/CTE (§5).
- **Le cadre par défaut est `RANGE`, pas `ROWS`** — soyez explicite avec `ROWS UNBOUNDED PRECEDING` pour les totaux cumulés (§3).
- **`LAG`/`LEAD` retournent `NULL` aux bords** — passez une valeur par défaut ou mappez vers une valeur typée (§4).
- **Portabilité** — la syntaxe ci-dessus est standard et s'exécute sur SQLite 3.25+, MySQL 8.0+ et PostgreSQL. Si vous ciblez un MySQL plus ancien (5.7), les fonctions de fenêtre ne sont pas disponibles ; repliez-vous sur une auto-jointure ou calculez en PHP.
- **Indexez les colonnes de l'`ORDER BY`** — le `PARTITION BY` / `ORDER BY` d'une fenêtre bénéficie des mêmes index qu'un tri ordinaire.

---

## Guides associés

- [Use database transactions](use-transactions.md) — écritures multi-étapes atomiques
- [Leaderboard ranking](leaderboard-ranking.md) — une recette produit construite sur le classement
- [Add a database-backed endpoint](add-database-endpoint.md) — câblage repository + executor
