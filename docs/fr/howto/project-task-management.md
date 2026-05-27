# How-to : Gestion de projets et de tâches avec ressources imbriquées

> **Référence FT** : FT241 (`NENE2-FT/projtrack`) — API de gestion de projets et de tâches

Démontre une API de ressources imbriquées à deux niveaux où les tâches appartiennent à des projets,
avec validation d'existence du parent, mises à jour sélectives via PATCH avec `array_key_exists()`,
allowlist de statut via contrainte CHECK, priorité sous forme d'entier, et `204 No Content`
pour les réponses DELETE.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/projects` | Lister les projets (paginé) |
| `POST` | `/projects` | Créer un projet |
| `GET` | `/projects/{id}` | Obtenir un projet unique |
| `DELETE` | `/projects/{id}` | Supprimer un projet (cascade vers les tâches) |
| `GET` | `/projects/{projectId}/tasks` | Lister les tâches d'un projet (paginé, filtrable) |
| `POST` | `/projects/{projectId}/tasks` | Créer une tâche dans un projet |
| `GET` | `/projects/{projectId}/tasks/{taskId}` | Obtenir une tâche unique |
| `PATCH` | `/projects/{projectId}/tasks/{taskId}` | Mettre à jour sélectivement une tâche (champs omis conservés) |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}` | Supprimer une tâche (`204 No Content`) |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'done')),
    priority    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`status` est contraint au niveau DB avec `CHECK(status IN (...))` — un filet de sécurité
contre les valeurs invalides qui passeraient. `ON DELETE CASCADE` signifie que supprimer un projet
supprime automatiquement toutes ses tâches. `priority` vaut par défaut `0` ; des valeurs plus élevées se trient en premier.

---

## Ressources imbriquées : validation d'existence du parent

Chaque opération sur une tâche valide que le projet parent existe **avant** de toucher la
tâche. Si l'ID du projet est inconnu, `ProjectNotFoundException` est levée immédiatement :

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // S'assurer que le projet existe (lève ProjectNotFoundException → 404)
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` est enregistrée comme gestionnaire d'exception qui correspond à
`404 Not Found`. Cela signifie que `/projects/99/tasks` retourne `404` quand le projet 99 n'existe
pas — le même statut qu'une tâche manquante. Les appelants ne peuvent pas distinguer "projet
manquant" de "tâche manquante" sans lire le champ `detail` du Problem Details.

Le repository de tâches applique également le scopage par projet au niveau SQL :

```php
public function findByProjectAndId(int $projectId, int $taskId): Task
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND project_id = ?',
        [$taskId, $projectId],
    );
    if ($row === null) {
        throw new TaskNotFoundException($projectId, $taskId);
    }
    return $this->hydrate($row);
}
```

`WHERE id = ? AND project_id = ?` prévient l'accès inter-projets — la tâche 5 sous le projet
1 ne peut pas être récupérée via `/projects/2/tasks/5`, même si la tâche 5 existe.

---

## PATCH : mise à jour sélective de champ avec `array_key_exists()`

`PATCH /projects/{projectId}/tasks/{taskId}` accepte tout sous-ensemble de `title`, `status`
et `priority`. Les champs absents du body de la requête sont conservés.

`isset()` ne peut pas distinguer `"clé absente"` de `"clé présente avec null"`. Pour la
sémantique PATCH, `array_key_exists()` est le bon outil :

```php
$title    = null;
$status   = null;
$priority = null;

if (array_key_exists('title', $body)) {
    if (!is_string($body['title']) || trim($body['title']) === '') {
        $errors[] = new ValidationError('title', 'title must be a non-empty string.', 'invalid_value');
    } else {
        $title = trim($body['title']);
    }
}

if (array_key_exists('status', $body)) {
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
        $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
    } else {
        $status = $body['status'];
    }
}

if (array_key_exists('priority', $body)) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`$title`, `$status` et `$priority` restent `null` quand la clé est absente. Le
repository interprète `null` comme "ne pas changer" :

```php
public function update(int $projectId, int $taskId, ?string $title, ?string $status, ?int $priority, string $now): Task
{
    $existing    = $this->findByProjectAndId($projectId, $taskId);
    $newTitle    = $title    ?? $existing->title;
    $newStatus   = $status   ?? $existing->status;
    $newPriority = $priority ?? $existing->priority;

    $this->executor->execute(
        'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
        [$newTitle, $newStatus, $newPriority, $now, $taskId, $projectId],
    );

    return $this->findByProjectAndId($projectId, $taskId);
}
```

L'opérateur null-coalescing `??` fusionne les valeurs fournies avec l'enregistrement existant. Un seul
`UPDATE` s'exécute toujours (pas besoin d'une liste de colonnes dynamique) — la valeur existante se
remplace simplement quand un champ est absent.

---

## `is_int()` pour priority : rejeter les floats et les chaînes du JSON

JSON `1` est décodé comme PHP `int`, mais `1.0` comme `float` et `"1"` comme `string`.
`is_int()` accepte uniquement la forme entière :

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` accepterait `"1"` et `1.0` — utiliser `is_int()` pour une validation stricte entier uniquement.
Note : `priority` est optionnel à la création (vaut par défaut `0`) ; pour PATCH, la
même vérification s'applique à l'intérieur du bloc `array_key_exists('priority', $body)`.

---

## Validation de l'allowlist de statut

Le statut est validé contre une allowlist explicite avant d'atteindre la DB :

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` — comparaison stricte — s'assure que la valeur est une chaîne égale à l'un
des états autorisés. La contrainte `CHECK` de la DB fournit une deuxième couche de défense,
mais la vérification au niveau applicatif donne un `422` structuré avec un message d'erreur significatif
plutôt qu'une erreur DB brute.

---

## Filtre de statut pour la liste des tâches

`GET /projects/{projectId}/tasks?status=open` filtre les tâches par statut. La chaîne de requête
est lue avec `QueryStringParser::string()` :

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` retourne `null` quand le paramètre est absent — pas de filtre
appliqué. Une valeur invalide retourne `422 Unprocessable Entity` plutôt que de retourner silencieusement une liste vide.

Le repository construit la clause WHERE dynamiquement :

```php
public function findByProject(int $projectId, ?string $status = null, int $limit = 20, int $offset = 0): array
{
    $where  = ['project_id = ?'];
    $params = [$projectId];

    if ($status !== null) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT * FROM tasks WHERE ' . implode(' AND ', $where)
        . ' ORDER BY priority DESC, created_at ASC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    return array_map($this->hydrate(...), $this->executor->fetchAll($sql, $params));
}
```

Les tâches sont triées par `priority DESC` (priorité plus élevée en premier), puis `created_at ASC`
(tâches plus anciennes en premier à même priorité).

---

## `204 No Content` pour DELETE

Les réponses DELETE ne portent aucun body. `JsonResponseFactory::createEmpty(204)` produit une
réponse `204 No Content` :

```php
private function deleteTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId);
    $this->tasks->delete($projectId, $taskId);

    return $this->json->createEmpty(204);
}
```

Le repository de tâches valide l'existence avant de supprimer :

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // lève TaskNotFoundException si manquant
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

Si la tâche n'existe pas (ou appartient à un autre projet), `TaskNotFoundException`
est levée → `404 Not Found` avant tout DELETE.

---

## Fonctionnalités intégrées NENE2 utilisées

| Fonctionnalité | Usage |
|---|---|
| `PaginationQueryParser::parse()` | Lit `?limit=` et `?offset=` avec des valeurs par défaut sûres |
| `PaginationResponse` | Produit l'enveloppe `{ items, total, limit, offset }` |
| `ValidationException` / `ValidationError` | `422` structuré avec tableau `errors` |
| `QueryStringParser::string()` | Lit un paramètre de chaîne de requête nommé, retourne `null` si absent |
| `JsonRequestBodyParser::parse()` | Décode le body JSON |
| `JsonResponseFactory::create()` | Encode la réponse JSON |
| `JsonResponseFactory::createEmpty()` | Produit une réponse sans body (ex. `204`) |
| `Router::PARAMETERS_ATTRIBUTE` | Récupère les paramètres de chemin depuis la requête |

---

## Howtos connexes

- [`note-management-ownership.md`](note-management-ownership.md) — Prévention IDOR avec `WHERE id = ? AND owner_id = ?`
- [`contact-management.md`](contact-management.md) — Associations plusieurs-à-plusieurs, filtrage de recherche
- [`document-versioning.md`](document-versioning.md) — Versionnage append-only avec flag `is_current`
- [`scheduled-reminders.md`](scheduled-reminders.md) — Pattern de validation d'en-tête V::userId()
