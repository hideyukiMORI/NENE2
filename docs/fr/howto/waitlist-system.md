# How-to : Système de liste d'attente

> **Référence FT** : FT287 (`NENE2-FT/waitlistlog`) — Système de liste d'attente : contrainte UNIQUE(user_id) pour une entrée par utilisateur, machine d'états waiting→approved/declined, garde `isTerminal()`, `/waitlist/me` enregistré avant `/{id}` pour éviter la capture de route, authentification `X-Admin-Key`, suivi de position dans la file, 39 tests / 98 assertions PASS.

Ce guide montre comment construire un système de liste d'attente où les utilisateurs rejoignent une file et les administrateurs approuvent ou refusent les entrées.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- une entrée par utilisateur
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- note optionnelle fournie par l'utilisateur (max 500 chars)
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` applique une entrée par utilisateur au niveau DB — aucune vérification applicative n'est nécessaire pour les conditions de concurrence.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/waitlist` | `X-User-Id` | Rejoindre la liste d'attente |
| `GET` | `/waitlist/me` | `X-User-Id` | Obtenir son statut + position |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Quitter la liste d'attente |
| `GET` | `/waitlist` | `X-Admin-Key` | Admin : lister toutes les entrées |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Admin : approuver une entrée |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Admin : refuser une entrée |

## Ordre d'enregistrement des routes

`/waitlist/me` doit être enregistré **avant** `/waitlist/{id}` pour éviter que le paramètre de chemin ne capture la chaîne littérale `"me"` :

```php
// CORRECT : chemin statique avant chemin dynamique
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// INCORRECT : {id} capturerait "me"
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // jamais atteint
```

## Cycle de vie du statut

```
waiting ──────→ approved (terminal)
       └──────→ declined (terminal)
```

Une fois approuvée ou refusée, une entrée ne peut pas passer à un autre état. La méthode `isTerminal()` assure cette garde :

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## Rejoindre avec 409 sur doublon

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

Le repository retourne `null` quand `user_id` existe déjà (capturé depuis `DatabaseConstraintException`). La réponse est 409 Conflict.

## Suivi de position

```php
$position = $this->repository->positionOf($entry);

// positionOf() compte les entrées avec status='waiting' et id <= $entry->id
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

La position est le rang 1-based dans la file `waiting`. Les entrées approuvées/refusées ne comptent pas. Cela donne aux utilisateurs une place significative dans la file.

## Transition admin avec match

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` est exhaustif — le cas `default` capture toute valeur de retour inattendue du repository.

## Partir (uniquement en attente)

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

Une fois approuvé ou refusé, un utilisateur ne peut plus partir — sa décision est enregistrée. Cela empêche de contourner le système (approuver puis partir pour éviter le suivi).

## Authentification admin

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // fail-closed : pas de clé configurée → pas d'accès admin
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` prévient les attaques temporelles. Une clé admin vide retourne toujours false (fail-closed).

## Validation de la note

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

Les notes sont optionnelles (null si absentes/vides), max 500 caractères, tronquées (non rejetées) si trop longues.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de contrainte `UNIQUE(user_id)` | Des inscriptions concurrentes créent des entrées dupliquées ; condition de concurrence |
| Enregistrer `/{id}` avant `/me` | `/waitlist/me` devient inaccessible — capturé par `{id}` qui correspond à `"me"` |
| Autoriser la transition depuis l'état terminal | Entrée approuvée refusée après l'octroi d'accès ; machine d'états brisée |
| Autoriser le départ depuis l'état terminal | L'utilisateur approuvé part ; l'octroi d'accès devient orphelin |
| Retourner la position en comptant toutes les entrées par `id ASC` | Compte les utilisateurs approuvés/refusés ; le numéro de position est trompeur |
| Stocker la clé admin en DB | La rotation de clé nécessite une mise à jour DB ; utiliser une variable d'environnement |
| Utiliser `==` au lieu de `hash_equals()` pour la clé admin | Une attaque temporelle révèle la clé caractère par caractère |
| Pas de fail-closed pour l'admin | Une clé vide dans l'env autorise l'accès admin non authentifié |
| Rejeter la note si trop longue | UX : tronquer est plus convivial que rejeter pour les métadonnées souples comme les notes |
