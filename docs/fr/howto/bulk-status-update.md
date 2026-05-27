# How-to : API de mise à jour de statut en lot

> **Référence FT** : FT85 (`NENE2-FT/bulkupdatelog`) — API de mise à jour de statut en lot
> **VULN** : FT231 — évaluation sécurité / vulnérabilités (V-01 à V-10)

Présente deux patterns pour la mutation de statut en lot : mises à jour par élément (chaque élément reçoit son propre statut cible) et mise à jour homogène en lot (tous les éléments reçoivent le même statut). Les deux prennent en charge le succès partiel — la réponse rapporte quels IDs ont réussi et lesquels ont échoué.

---

## Routes

| Méthode  | Chemin             | Description                                                   |
|----------|--------------------|---------------------------------------------------------------|
| `POST`   | `/tasks`           | Créer une tâche                                               |
| `GET`    | `/tasks`           | Lister toutes les tâches                                      |
| `PATCH`  | `/tasks/status`    | Mise à jour de statut par élément (statuts cibles mixtes)    |
| `PATCH`  | `/tasks/done`      | Marquer un ensemble d'IDs comme terminés (statut cible unique)|

---

## Mise à jour par élément (`PATCH /tasks/status`)

Chaque élément de mise à jour spécifie son propre statut cible :

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

Le repository traite chaque élément individuellement, accumulant succès et échecs :

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### Structure de la réponse

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

Le statut HTTP est toujours `200 OK` — même quand tous les éléments échouent. L'appelant doit inspecter `failed` pour détecter les erreurs par élément.

---

## Mise à jour homogène (`PATCH /tasks/done`)

Tous les IDs passent au même statut cible en un seul `UPDATE ... WHERE id IN (?)` :

```php
// Corps : {"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

Les valeurs non entières sont silencieusement filtrées via `array_filter(..., is_int(...))`. Après filtrage, si le résultat est vide, une 422 est retournée.

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // Retourner les IDs qui existent et ont maintenant le statut cible
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` génère le bon nombre de placeholders `?` — sûr, paramétrisé.

---

## Liste blanche de statut (enum backed)

`TaskStatus` est un enum string backed avec quatre cas :

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` retourne `null` pour les valeurs de statut inconnues, que le gestionnaire en lot mappe à un échec par élément. Le schéma ajoute `CHECK(status IN (...))` comme filet de sécurité au niveau DB.

---

## Schéma

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## VULN — Évaluation de sécurité (FT231)

### V-01 — Pas d'authentification sur aucun endpoint

**Attaque** : Annuler toutes les tâches en lot sans identifiants.

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**Observé** : `200 OK` — aucun token requis.

**Verdict** : **EXPOSÉ** (par conception pour la démo FT85). Ajouter l'authentification et l'autorisation en production. Restreindre les mutations en lot au propriétaire de la tâche ou à un rôle admin.

---

### V-02 — DoS de mise à jour massive (tableau énorme)

**Attaque** : Envoyer un tableau `updates` avec des milliers d'éléments pour épuiser le CPU ou la mémoire.

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**Observé** : Traité en boucle — chaque élément exécute un `UPDATE` individuel. Pour 100 000 éléments, cela exécute 100 000 instructions SQL individuelles dans une boucle serrée sans limite de taille de lot.

**Verdict** : **EXPOSÉ** — ajouter une limite maximale de taille de lot :
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — Injection SQL via la clause `IN`

**Attaque** : Tenter d'injecter du SQL dans le tableau `ids` utilisé dans `IN (?)`.

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**Observé** : La chaîne `"1; DROP TABLE tasks; --"` est rejetée par le filtre `is_int()` dans `array_filter()`. Seuls les entiers atteignent la clause `IN`. Le pattern `implode` + `array_fill` génère le bon nombre de placeholders `?` — pas de concaténation de données utilisateur.

**Verdict** : **BLOQUÉ** — filtre `is_int()` + clause `IN` paramétrisée empêche l'injection.

---

### V-04 — IDs non entiers dans les mises à jour par élément

**Attaque** : Envoyer des valeurs `id` non entières dans le tableau `updates`.

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**Observé** : Les deux éléments sont ajoutés à `$failed` avec `'error' => 'id must be an integer'`. `is_int()` rejette les chaînes et `null`.

**Verdict** : **BLOQUÉ** — vérification de type `is_int()` stricte par élément.

---

### V-05 — Valeur de statut invalide

**Attaque** : Envoyer une chaîne de statut inconnue dans le tableau `updates`.

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**Observé** : Élément ajouté à `$failed` avec `'error' => 'invalid status value'`.
`TaskStatus::tryFrom("hacked")` retourne `null`.

**Verdict** : **BLOQUÉ** — `tryFrom()` de l'enum backed rejette les valeurs inconnues.

---

### V-06 — Tableau vide

**Attaque** : Envoyer un tableau `updates` ou `ids` vide.

```json
{"updates": []}
{"ids": []}
```

**Observé** : Les deux retournent `422 Unprocessable Entity` avec un message d'erreur.

**Verdict** : **BLOQUÉ** — vérification de tableau vide avant traitement.

---

### V-07 — IDs en double dans le même lot

**Attaque** : Inclure le même `id` plusieurs fois dans une seule requête.

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**Observé** : Les deux mises à jour réussissent. Le second UPDATE écrase le premier — la tâche finit en `cancelled`. Aucune déduplication n'a lieu.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — la sémantique last-write-wins est cohérente pour la gestion de tâches simple. Si les conflits doivent être rejetés, dédupliquer les `ids` avant traitement et retourner une erreur sur les doublons.

---

### V-08 — IDs négatifs et zéro

**Attaque** : Envoyer des IDs `0` ou `-1`.

```json
{"ids": [0, -1]}
```

**Observé** : `is_int(0)` = true, `is_int(-1)` = true — les deux passent le filtre.
L'UPDATE s'exécute avec `WHERE id IN (0, -1)`, qui ne correspond à aucune ligne. Réponse :
`{"requested": 2, "updated": 0, "ids": []}`.

**Verdict** : **BLOQUÉ** en pratique (aucune ligne affectée). Aucune erreur n'est retournée pour les IDs inexistants — c'est cohérent avec le pattern de succès partiel. Ajouter une garde d'entier positif si les IDs négatifs doivent être rejetés avec 422.

---

### V-09 — La mise à jour en lot ignore silencieusement les tâches inexistantes

**Attaque** : Inclure des IDs qui n'existent pas dans la base de données.

```json
{"ids": [99999, 100000]}
```

**Observé** : `{"requested": 2, "updated": 0, "ids": []}` — pas d'erreur, pas d'indication que les tâches n'existent pas.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — modèle de succès partiel. Documenter ce comportement dans la spécification API. Si les appelants ont besoin de distinguer "tâche inexistante" de "tâche déjà dans le statut cible", la réponse peut inclure une liste `not_found`.

---

### V-10 — Mises à jour en lot concurrentes sur les mêmes IDs

**Attaque** : Envoyer deux requêtes `PATCH /tasks/done` simultanées pour le même ensemble d'IDs.

**Observé** : Les deux instructions UPDATE s'exécutent sur la DB. Le verrouillage au niveau ligne de SQLite signifie qu'un UPDATE se termine en premier, puis le second UPDATE s'exécute sur des lignes déjà en `done`. Les deux réponses retournent les IDs `updated` (puisque les lignes existent toujours avec `status = done`).

**Verdict** : **BLOQUÉ** — écritures idempotentes. Les deux requêtes produisent le même résultat (tous les IDs définis à `done`). Pour les mises à jour de `status` où le statut cible diffère selon l'appelant, les écritures concurrentes utilisent last-write-wins.

---

## Résumé VULN

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| V-01 | Pas d'authentification | EXPOSÉ (par conception) |
| V-02 | DoS de mise à jour massive (tableau énorme) | EXPOSÉ |
| V-03 | Injection SQL via la clause `IN` | BLOQUÉ |
| V-04 | IDs non entiers | BLOQUÉ |
| V-05 | Valeur de statut invalide | BLOQUÉ |
| V-06 | Tableau vide | BLOQUÉ |
| V-07 | IDs en double dans le lot | ACCEPTÉ PAR CONCEPTION |
| V-08 | IDs négatifs/zéro | BLOQUÉ |
| V-09 | Tâches inexistantes ignorées silencieusement | ACCEPTÉ PAR CONCEPTION |
| V-10 | Mises à jour en lot concurrentes | BLOQUÉ |

**Vulnérabilités réelles à corriger avant la production** :
1. **V-01** — Ajouter l'authentification et l'autorisation
2. **V-02** — Ajouter une limite maximale de taille de lot (ex. 500 éléments)

---

## Guides associés

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) — création en lot avec erreurs par élément
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — patterns de succès partiel
- [`approval-workflow.md`](approval-workflow.md) — transitions de statut avec garde d'enum
