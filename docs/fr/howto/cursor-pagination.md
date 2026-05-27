# How-to : Pagination basée sur curseur

> **Référence FT** : FT242 (`NENE2-FT/cursorlog`) — API de pagination basée sur curseur

Montre la pagination basée sur curseur (keyset) comme alternative à la pagination par offset. Les éléments sont récupérés en utilisant un curseur basé sur l'ID (`WHERE id < ?`), l'astuce `limit+1` détecte `has_more` sans une requête COUNT, et la réponse porte une valeur `next_cursor` que l'appelant passe dans la requête suivante.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/posts` | Créer un post |
| `GET` | `/posts` | Lister les posts avec pagination par curseur |
| `GET` | `/posts/{id}` | Obtenir un post spécifique |

---

## Pagination par offset vs. curseur

| Préoccupation | Offset (`LIMIT ? OFFSET ?`) | Curseur (`WHERE id < ? ORDER BY id`) |
|---|---|---|
| Performance sur de grands ensembles | Se dégrade — la DB doit sauter N lignes | Constant — recherche d'index à la position du curseur |
| Résultats stables | Les nouvelles lignes décalent les pages suivantes | Stable — ancré à une ligne spécifique |
| Accès aléatoire | Supporté (`?page=5`) | Non supporté (sens unique) |
| Nombre total | Nécessite une requête `COUNT(*)` séparée | Pas de total nécessaire (utiliser l'indicateur `has_more`) |
| Type de curseur | Offset entier (basé sur la position) | Valeur d'identité de ligne (basé sur l'ID) |

La pagination par curseur est préférable pour les flux haute densité et en temps réel où le décalage par offset (nouvelles lignes insérées entre pages) cause des lignes en doublon ou manquantes.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

Un index descendant sur `id` supporte `ORDER BY id DESC` efficacement. L'`INTEGER PRIMARY KEY` de SQLite est déjà un alias pour `rowid`, donc l'index explicite accélère les requêtes de plage au-delà de ce que la clé primaire seule fournit.

---

## Logique de curseur : `WHERE id < ? ORDER BY id DESC LIMIT ?`

Le repository récupère une ligne supplémentaire (`limit + 1`) pour détecter si d'autres pages existent :

```php
/**
 * Récupérer une page de posts dans l'ordre ID descendant.
 *
 * @param int|null $afterCursor  ID du dernier post vu ; retourner les posts avec id < afterCursor
 * @param int      $limit        Nombre maximum d'éléments à retourner (limité à 100)
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // Récupérer un de plus pour détecter s'il y a une page suivante
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // supprimer la ligne supplémentaire
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

Étapes clés :
1. **Limiter le limit** : `max(1, min(100, $limit))` — prévient les requêtes à 0 lignes ou incontrôlées.
2. **Récupérer `limit + 1`** : Si plus de `$limit` lignes reviennent, une page suivante existe.
3. **Supprimer l'extra** : `array_pop($rows)` supprime la (limit+1)-ième ligne utilisée uniquement pour la détection.
4. **Calculer `nextCursor`** : L'`id` du dernier élément devient le curseur que l'appelant envoie ensuite.
5. **`$hasMore = false`** quand `$nextCursor === null` — pas d'autres pages.

La première page n'a pas de curseur (`$afterCursor === null`), retournant les posts les plus récents. Chaque requête suivante envoie `?cursor=<nextCursor>` pour continuer là où elle s'est arrêtée.

---

## Value object `CursorPage`

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

`next_cursor` est `null` sur la dernière page (pas d'autres éléments). `has_more` reflète cela : `true` quand `next_cursor` est défini, `false` sur la dernière page. Les appelants s'arrêtent quand `has_more === false` ou `next_cursor === null`.

Forme de la réponse :
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## Contrôleur : lecture et validation du curseur

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` valide la chaîne du curseur avant de la caster en `int` :
- `ctype_digit()` retourne `false` pour les chaînes vides, les signes négatifs, les floats et les chaînes non numériques — tous traités comme "pas de curseur" (première page).
- Un curseur invalide revient à la première page plutôt que de retourner une erreur — les appelants passant un curseur périmé ou invalide voient la première page, pas un `400`.

C'est un choix pragmatique : les curseurs invalides sont silencieusement traités comme absents. Pour des APIs plus strictes, retourner `422 Unprocessable Entity` quand `$cursorRaw` est non-null mais échoue `ctype_digit()`.

---

## Limitation du limit

```php
$limit = max(1, min(100, $limit));
```

- Minimum `1` : prévient les requêtes à zéro ligne.
- Maximum `100` : plafonne la taille de page pour éviter les récupérations incontrôlées.

La limitation se produit dans le repository plutôt que dans le contrôleur, garantissant qu'aucun appelant de `paginate()` ne peut contourner les limites. Le contrôleur lit `$query['limit']` avec un défaut de `10` quand absent.

---

## Résumé du contrat de pagination

| Paramètre de requête | Type | Défaut | Comportement |
|---|---|---|---|
| `?limit=N` | entier | 10 | Éléments par page (limité 1–100) |
| `?cursor=ID` | chaîne entière | absent | Récupérer les éléments avec `id < ID` ; absent = première page |

| Champ de réponse | Type | Signification |
|---|---|---|
| `items` | tableau | Éléments sérialisés pour cette page |
| `next_cursor` | int \| null | Passer comme `?cursor=` dans la requête suivante ; `null` = dernière page |
| `has_more` | bool | `true` si d'autres pages existent |

---

## Comparaison avec la pagination par offset

Les built-ins `PaginationQueryParser` / `PaginationResponse` de NENE2 utilisent `LIMIT ? OFFSET ?`. Les utiliser quand :
- L'accès aléatoire à une page est requis (`?page=5`).
- Le nombre total d'éléments est affiché à l'utilisateur.
- L'ensemble de données est petit et croît rarement pendant la traversée.

Utiliser la pagination par curseur quand :
- Les données du flux croissent continuellement (chat, flux d'activité, logs).
- Une traversée stable sous charge d'insertion est requise.
- L'ensemble de données est suffisamment grand pour que `OFFSET N` devienne lent.

---

## Guides associés

- [`pagination.md`](pagination.md) — pagination par offset avec `PaginationQueryParser` et `PaginationResponse`
- [`activity-feed.md`](activity-feed.md) — pattern de flux en temps réel
- [`add-pagination.md`](add-pagination.md) — ajouter la pagination à un endpoint existant
