# How-to : API de workflow à machine à états

> **Référence FT** : FT349 (`NENE2-FT/workflowlog`) — Instances de workflow à machine à états avec carte de transitions codée en dur, `allowed_next` dans les réponses, journal d'historique des transitions, enforcement de l'état terminal, filtre d'état sur la liste, 13 tests PASS.

Ce guide montre comment construire un moteur de workflow avec une machine à états : définir les transitions d'état autorisées, créer des instances de workflow, les faire progresser à travers les états avec attribution d'acteur, et journaliser l'historique complet des transitions.

## Schéma

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- ex. "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## Définition du workflow — "order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled (terminal)
        │                ├──► rejected   (terminal)
        └──► cancelled   └──► cancelled  (terminal)
        (terminal)
```

| État depuis | États suivants autorisés |
|-------------|--------------------------|
| `draft` | `submitted`, `cancelled` |
| `submitted` | `approved`, `cancelled`, `rejected` |
| `approved` | `fulfilled` |
| `fulfilled` | _(terminal — aucun)_ |
| `cancelled` | _(terminal — aucun)_ |
| `rejected` | _(terminal — aucun)_ |

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/workflows/{workflow}/instances` | Créer une instance de workflow |
| `GET` | `/workflows/{workflow}/instances` | Lister les instances |
| `GET` | `/workflows/{workflow}/instances/{id}` | Obtenir l'instance avec l'historique |
| `POST` | `/workflows/{workflow}/instances/{id}/transition` | Conduire une transition d'état |

## Créer une instance

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← prochains états valides
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` est calculé depuis la carte de transitions — reflète toujours l'état actuel.

### Workflow inconnu → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // workflow non défini
```

## Lister les instances

```php
// Toutes les instances du workflow "order"
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// Filtrer par état actuel
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // uniquement les instances draft
```

## Obtenir une instance (avec historique)

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` est toujours ordonné chronologiquement (ASC par `occurred_at`). L'endpoint de liste omet `history` pour les performances.

## Conduire des transitions

```php
// Transition valide
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### Chemin complet réussi

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}    → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}  → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"} → fulfilled

// fulfilled est terminal
→ {"current_state": "fulfilled", "allowed_next": [], ...}
```

### Transition invalide → 409

```php
// draft → approved (doit passer par submitted d'abord)
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transition from 'draft' to 'approved' is not allowed"
}
```

### État terminal → 409

```php
// cancelled est terminal — aucune transition autorisée
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" n'a pas de transitions autorisées
```

## Implémentation

### WorkflowDefinition — Carte de transitions

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // terminal
            'cancelled' => [],     // terminal
            'rejected'  => [],     // terminal
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Unknown workflow: {$workflow}"),
        };
    }
}
```

### Handler de transition

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 invalide ou terminal
    }

    // Atomique : mettre à jour l'instance + insérer le journal de transition
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` est toujours calculé depuis la carte de transitions, jamais stocké — il reste cohérent avec `current_state`.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker `allowed_next` en DB | Données périmées si la carte de transitions change ; toujours calculer depuis l'état actuel |
| Permettre un `to_state` libre sans vérification de liste blanche | L'attaquant peut définir l'état à n'importe quelle valeur, contournant la logique du workflow |
| Omettre la journalisation des transitions | Pas de piste d'audit ; impossible de reconstruire l'historique du workflow ou déboguer les instances bloquées |
| Retourner les états terminaux dans `allowed_next` | Induit les appelants en erreur ; les états terminaux ont toujours `allowed_next` vide |
| Retourner 404 pour une transition invalide | 404 masque la distinction entre "instance non trouvée" et "transition non autorisée" ; utiliser 409 pour ce dernier |
| Pas de champ `workflow` dans la table instances | Impossible de distinguer les instances de différents types de workflow ; pas de requête cross-workflow possible |
