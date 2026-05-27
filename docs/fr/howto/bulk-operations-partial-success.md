# How-to : Opérations en lot avec sémantique de succès partiel

> **Référence FT** : FT258 (`NENE2-FT/bulklog`) — Création en lot / suppression en lot avec sémantique de succès partiel et HTTP 207 Multi-Status

Montre comment gérer les opérations d'API en lot où certains éléments peuvent réussir et d'autres échouer. Chaque élément est traité indépendamment — un échec de validation pour l'élément N n'interrompt pas les éléments N+1 et suivants. La réponse porte deux tableaux : `created` (réussis) et `errors` (échoués avec raisons). HTTP 207 Multi-Status est retourné quand il y a un mélange ; 201 Created quand tous réussissent.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/items` | Créer un élément |
| `GET` | `/items/{id}` | Obtenir un élément spécifique |
| `POST` | `/items/bulk` | Créer des éléments en lot (succès partiel) |
| `DELETE` | `/items/bulk` | Supprimer des éléments en lot par ID (succès partiel) |

> **Ordre des routes** : `/items/bulk` doit être enregistrée avant `/items/{id}` pour que le segment littéral `bulk` ne soit pas capturé comme paramètre de chemin.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` empêche les SKU en doublon au niveau DB. `price INTEGER` stocke le prix dans la plus petite unité monétaire (centimes) pour éviter les erreurs d'arrondi en virgule flottante.

---

## DTO BulkResult

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` contient les enregistrements créés avec succès. `errors` contient les descripteurs d'erreur par élément. `hasErrors()` est un prédicat simple que le contrôleur utilise pour choisir le code de statut HTTP.

---

## Création en lot : validation par élément

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // ignorer l'insertion, continuer vers l'élément suivant
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**Décisions clés** :
- `continue` sur échec de validation : les éléments échoués n'interrompent pas la boucle.
- `$index` est inclus dans l'entrée d'erreur : les clients savent quelle position dans leur tableau d'entrée a échoué.
- L'unicité du SKU est vérifiée en PHP (`skuExists()`) avant l'INSERT, pas capturée depuis les exceptions DB. Cela donne un message d'erreur plus propre au niveau applicatif plutôt qu'une violation de contrainte brute.
- Tous les INSERT réussis partagent le même horodatage `$now` : le lot est traité comme un seul point dans le temps.

---

## Suppression en lot : suivi des introuvables

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

Les IDs introuvables sont suivis mais n'interrompent pas l'opération. La réponse permet à l'appelant d'auditer quels IDs ont été effectivement supprimés et lesquels étaient déjà absents. Retourner 200 (pas 207) est raisonnable ici car toutes les suppressions demandées ont soit réussi, soit étaient déjà absentes — il n'y a pas d'état "erreur".

---

## Contrôleur : HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 207 quand mix succès + erreur

    return $this->json->create($result->toArray(), $status);
}
```

**Choix du statut HTTP** :

| Résultat | Statut | Signification |
|---|---|---|
| Tous créés | `201 Created` | Succès complet |
| Certains créés, certains échoués | `207 Multi-Status` | Succès partiel — le client doit inspecter le corps |
| Tous échoués | `207 Multi-Status` | Échec complet — le tableau `created` est vide |
| Pas de tableau `items` | `422 Unprocessable Entity` | Requête malformée |

`207` signale au client : _ne pas supposer le succès — inspecter le corps_. Un client qui voit `201` peut supposer que tous les éléments ont été traités ; un client qui voit `207` doit vérifier `errors`.

**Pourquoi pas 422 pour échec partiel ?** `422` signifie que toute la requête est rejetée. Les endpoints en lot avec succès partiel traitent bien certaines entrées avec succès, donc `422` serait trompeur.

---

## Contrôleur de suppression en lot

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // toujours 200
}
```

`array_filter($body['ids'], 'is_int')` supprime silencieusement les valeurs non entières du tableau IDs. C'est un choix de conception : les IDs malformés sont ignorés plutôt que de causer un 422. Une approche alternative est de rejeter toute la requête si un ID est non entier.

---

## Exemple de requête et réponse

### Création en lot — succès partiel

**Requête** `POST /items/bulk` :
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**Réponse** `207 Multi-Status` :
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` se réfère à la position dans le tableau `items` d'entrée (base 0). Le client peut corréler chaque erreur à l'entrée originale sans scanner le payload.

### Suppression en lot — succès partiel

**Requête** `DELETE /items/bulk` :
```json
{"ids": [1, 999, 2]}
```

**Réponse** `200 OK` :
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## Compromis de conception

| Approche | Comportement | Quand utiliser |
|---|---|---|
| Tout ou rien | Annuler tout si l'un échoue | Financier, inventaire — cohérence requise |
| Succès partiel (ce pattern) | Traiter chaque élément indépendamment | Import/export, ingestion de données |
| File de jobs fire-and-forget | Traitement async, résultats différés | Grands lots, jobs en arrière-plan |

Le succès partiel est approprié quand les éléments sont indépendants les uns des autres. Si le succès de l'élément A dépend du succès de l'élément B (ex. transfert de stock entre éléments), utiliser une transaction tout-ou-rien à la place.

---

## Guides associés

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — écriture multi-opérations atomique tout-ou-rien
- [`job-queue-with-retry.md`](job-queue-with-retry.md) — traitement en lot async via file de jobs
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — liste blanche DTO explicite pour chaque élément
