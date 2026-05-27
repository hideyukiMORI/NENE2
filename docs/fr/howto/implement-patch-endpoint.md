# How-to : Implémenter un endpoint PATCH

PATCH est pour les **mises à jour partielles** : seuls les champs que le client envoie doivent changer.
Cela nécessite de distinguer trois états pour chaque champ :

| État | Signification |
|------|---------------|
| Clé absente du corps | Ne pas toucher ce champ |
| Clé présente, valeur non nulle | Mettre à jour avec la nouvelle valeur |
| Clé présente, valeur `null` | Effacer le champ (mettre à null) |

`isset()` ne peut pas distinguer "absent" de "null explicite" — les deux retournent `false`.
Utiliser `array_key_exists()` à la place.

---

## 1. Parser le corps et extraire seulement les champs présents

```php
$body   = JsonRequestBodyParser::parse($request);   // array<string, mixed>
$fields = [];

if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

Passer `$fields` à la méthode `update()` du repository. Si `$fields` est vide, l'appel est quand même valide — répondre avec l'état actuel de la ressource.

---

## 2. Enregistrement de la route

```php
$router->patch(
    '/entries/{id}',
    static function (ServerRequestInterface $request) use ($entries, $json): ResponseInterface {
        /** @var array<string, string> $params */
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id     = (int) ($params['id'] ?? 0);

        $body   = JsonRequestBodyParser::parse($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = $body['title'];
        }
        if (array_key_exists('is_read', $body)) {
            $fields['is_read'] = (bool) $body['is_read'];
        }

        $entry = $entries->update($id, $fields) ?? throw new EntryNotFoundException($id);

        return $json->create(self::payload($entry));
    },
);
```

---

## 3. Envoyer un corps PATCH vide

Pour envoyer un PATCH sans champs (une opération no-op qui retourne l'état actuel), vous devez envoyer un **objet** JSON, pas un tableau.

```php
// INCORRECT : json_encode([]) === "[]"  → 400 Bad Request (tableau JSON)
$request->withBody($stream->write(json_encode([])));

// CORRECT : json_encode((object)[]) === "{}"  → 200 OK (objet JSON)
$request->withBody($stream->write(json_encode((object)[])));
```

Dans les helpers de test, passer `new \stdClass()` comme corps :

```php
// Dans les tests PHPUnit
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

C'est parce que `JsonRequestBodyParser` rejette les tableaux JSON (voir le message `JsonBodyParseException` pour les détails). Un tableau PHP vide `[]` s'encode en tableau JSON `[]`, pas en objet JSON `{}`.

---

## 4. Valider les champs PATCH

Valider seulement les champs qui sont **présents**. Ignorer la validation pour les champs absents — ils ne seront pas touchés. Utiliser des paramètres nullable dans la signature du repository pour rendre l'intention explicite :

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// Extraire seulement les champs présents (array_key_exists, pas isset)
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// Valider seulement les champs qui ont été envoyés
if ($amount !== null) {
    if (!is_int($amount) || $amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer.', 'out_of_range');
    }
}

if ($date !== null) {
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
    }
}

if ($errors !== []) {
    throw new ValidationException($errors);
}

// Appeler le repository avec des args nullable — le repository utilise la valeur existante quand null
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

Dans le repository, utiliser `??` pour revenir à la valeur existante :

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // lève NotFoundException si manquant
    $newAmount   = $amount   ?? $existing->amount;
    $newCategory = $category ?? $existing->category;
    $newDate     = $date     ?? $existing->date;

    $this->executor->execute(
        'UPDATE entities SET amount = ?, category = ?, date = ?, updated_at = ? WHERE id = ?',
        [$newAmount, $newCategory, $newDate, $now, $id],
    );

    return new Entity($id, $newDate, $newAmount, $newCategory, $existing->createdAt, $now);
}
```

> **Pourquoi `array_key_exists` et pas `isset` ?** `isset($body['field'])` retourne `false` à la fois pour une clé manquante et une clé présente avec la valeur `null`. Pour PATCH, cette distinction est importante : "non envoyé" signifie "garder la valeur existante", tandis que `null` peut signifier "effacer ce champ". Toujours utiliser `array_key_exists` pour la détection des champs PATCH.

---

## 5. Contrat du repository

Le `update()` du repository devrait accepter seulement les champs passés et retourner l'entité mise à jour (ou `null` si non trouvée) :

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // no-op : retourner l'état actuel
    }

    $setClauses = implode(', ', array_map(fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $params     = [...array_values($fields), $id];

    $affected = $this->executor->execute(
        "UPDATE entries SET {$setClauses} WHERE id = ?",
        $params,
    );

    return $affected > 0 ? $this->findById($id) : null;
}
```

---

## 6. How-tos associés

- [`add-pagination.md`](add-pagination.md) — GET avec `PaginationQueryParser`
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — gestionnaire 404 pour les ressources manquantes
