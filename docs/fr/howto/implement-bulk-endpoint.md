# How-to : Implémenter un endpoint de création en masse

Un endpoint de masse accepte plusieurs ressources en une seule requête — réduisant les allers-retours pour les imports par lot, les soumissions de scores et les workflows similaires. Ce guide couvre le pattern complet : parsing, validation par élément avec noms de champs indexés, limitation de taille et la route.

---

## 1. Schéma

Le corps de la requête enveloppe les éléments dans une clé de tableau nommée pour que l'enveloppe puisse porter des métadonnées :

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

La réponse retourne le nombre créé et les éléments créés :

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. Route

Enregistrer la route de masse **avant** la route de ressource unique paramétrée pour éviter l'ombrage (voir [add-custom-route.md](add-custom-route.md)) :

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // statique en premier
$router->post('/scores/{id}', $this->show(...));        // paramétrée après
```

---

## 3. Gestionnaire

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. Valider l'enveloppe
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must be a non-empty array.', 'required'),
        ]);
    }

    /** @var array<mixed> $entriesRaw */
    $entriesRaw = $body['scores'];

    if (count($entriesRaw) === 0) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must contain at least one entry.', 'required'),
        ]);
    }

    // 2. Appliquer la limite de taille avant d'itérer
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. Valider chaque entrée, en préfixant les noms de champs avec l'index
    $allErrors = [];
    $entries   = [];

    foreach ($entriesRaw as $i => $entry) {
        if (!is_array($entry)) {
            $allErrors[] = new ValidationError("scores[{$i}]", 'Each entry must be an object.', 'invalid_type');
            continue;
        }

        /** @var array<string, mixed> $entry */
        $entryErrors = $this->validateEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = $entry;
        }
    }

    // 4. Faire échouer toute la requête si une entrée est invalide
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. Persister toutes les entrées et retourner
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. Validation par élément avec noms de champs indexés

Utiliser un helper privé qui accepte un argument `string $prefix`. Le préfixe est `"scores[{$i}]."` :

```php
/**
 * @param array<string, mixed> $body
 * @return list<ValidationError>
 */
private function validateEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }

    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }

    return $errors;
}
```

**Pourquoi `$prefix` ?** `ValidationError` accepte n'importe quelle chaîne comme nom de champ. Passer `"scores[0]."` comme préfixe produit des champs d'erreur comme `"scores[0].player"` — indiquant clairement quelle entrée et quel champ a échoué. Un seul argument de préfixe suffit ; aucun changement de framework n'est nécessaire.

Le corps de réponse 422 résultant :

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. Contrat du repository

Accepter une liste d'entrées pré-validées et retourner les entités créées :

```php
/**
 * @param list<array{player: string, game: string, score: int, played_at: string}> $entries
 * @return list<Score>
 */
public function bulkCreate(array $entries, string $now): array
{
    $results = [];
    foreach ($entries as $entry) {
        $results[] = $this->create($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
    }
    return $results;
}
```

> **Atomicité** : La boucle ci-dessus insère une ligne à la fois. Envelopper dans
> `DatabaseTransactionManagerInterface::transactional()` si vous avez besoin d'un comportement
> tout-ou-rien — voir [use-transactions.md](use-transactions.md).

---

## 6. How-tos associés

- [`add-pagination.md`](add-pagination.md) — pattern d'endpoint de liste
- [`use-transactions.md`](use-transactions.md) — envelopper les insertions en masse dans une transaction
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 404/409 spécifiques au domaine
