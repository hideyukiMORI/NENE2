# Ajouter la pagination

Ce guide explique comment ajouter la pagination `?limit=` / `?offset=` à un endpoint de collection à l'aide du helper `PaginationQueryParser` de `Nene2\Http`.

## Prérequis

- Un handler de collection fonctionnel (ex. `ListNotesHandler`).
- Le handler retourne une enveloppe JSON avec `items`, `limit` et `offset`.

## Étape 1 — Appeler `PaginationQueryParser::parse()`

Remplacez l'extraction manuelle des paramètres de requête par le parseur. Il valide les valeurs et lance `ValidationException` (→ 422) si elles sont hors plage.

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // défaut : limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* mapper $output->items */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` est un DTO readonly avec deux propriétés : `limit: int` et `offset: int`.

## Étape 2 — Personnaliser les limites (optionnel)

Passez `$defaultLimit` et `$maxLimit` pour remplacer les valeurs par défaut (20 et 100) :

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| Paramètre | Défaut | Signification |
|---|---|---|
| `$defaultLimit` | `20` | Utilisé quand `?limit=` est absent |
| `$maxLimit` | `100` | Valeur maximale autorisée ; retourne 422 si dépassée |

## Étape 3 — Gérer l'erreur 422

`PaginationQueryParser::parse()` lance `ValidationException` quand :

- `limit < 1` ou `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` mappe automatiquement `ValidationException` → `422 validation-failed`.
Aucune gestion d'erreur supplémentaire n'est nécessaire dans le handler.

**Exemple de réponse 422 :**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## Fonctionnement

`PaginationQueryParser::parse()` lit `getQueryParams()` de la requête PSR-7, caste les valeurs en `int`, les valide et retourne un DTO `PaginationQuery`. Les valeurs non numériques sont castées en `0` (comportement PHP de `(int)`) puis interceptées par la vérification `limit < 1`.

## Étape 4 — Utiliser `PaginationResponse` pour standardiser l'envelope

`PaginationResponse` est un DTO readonly qui construit l'envelope de liste standard :

```php
use Nene2\Http\PaginationResponse;

return $this->response->create(
    (new PaginationResponse(
        items:  array_map(fn ($item) => ['id' => $item->id, 'name' => $item->name], $output->items),
        limit:  $output->limit,
        offset: $output->offset,
    ))->toArray(),
);
```

## Étape 5 — Inclure le nombre total d'enregistrements (optionnel)

Passez `total` quand le repository supporte une requête COUNT :

```php
$total = $this->repository->countAll(); // SELECT COUNT(*) AS n FROM ...

return $this->response->create(
    (new PaginationResponse(items: /* ... */, limit: $output->limit, offset: $output->offset, total: $total))->toArray(),
);
```

Quand `total` est `null` (défaut), la clé est omise de la réponse.

> **Compromis** : `COUNT(*)` ajoute une requête par appel. Omettez `total` si l'overhead est
> inacceptable et laissez les clients détecter la dernière page avec `items.length < limit`.

## Voir aussi

- `src/Example/Note/ListNotesHandler.php` — implémentation de référence avec `PaginationResponse`
- `src/Example/Tag/ListTagsHandler.php` — deuxième exemple
- `Nene2\Http\PaginationQuery` — DTO readonly
- `Nene2\Http\PaginationQueryParser` — la classe parseur
- `Nene2\Http\PaginationResponse` — le DTO envelope de liste
