# How-to : API de workflow d'approbation

> **Référence FT** : FT68 (`NENE2-FT/approvallog`) — API de workflow d'approbation

Démontre un workflow d'approbation multi-étapes où une demande progresse à travers des états définis
(Draft → Submitted → UnderReview → Approved/Rejected). Les transitions invalides
retournent 409 Conflict. La machine d'états est encodée directement dans l'enum backed
`ApprovalStatus` en utilisant une méthode `allowedTransitions()`.

---

## États du workflow

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| État | Description |
|-------|-------------|
| `draft` | Créée mais pas encore soumise |
| `submitted` | En attente d'attribution de révision |
| `under_review` | Réviseur assigné et en cours de révision |
| `approved` | Approbation finale accordée |
| `rejected` | Rejetée avec une raison obligatoire |

Une demande rejetée peut être retravaillée (retournée à `draft`) pour révision et resoumission.
Une demande approuvée n'a plus de transitions.

---

## Règles de transition encodées dans l'enum

Les règles de transition d'état vivent dans l'enum — pas dans le repository ni dans le contrôleur :

```php
enum ApprovalStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted],
            self::Submitted   => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved    => [],
            self::Rejected    => [self::Draft],   // chemin de retravail
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` est la source unique de vérité pour savoir si une transition est valide.
Ajouter une nouvelle transition autorisée signifie mettre à jour uniquement cette méthode.

---

## Routes

| Méthode | Chemin | Description |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests` | Créer une demande en draft |
| `GET` | `/requests` | Lister toutes les demandes (filtre `?status=`) |
| `GET` | `/requests/{id}` | Obtenir une demande unique |
| `POST` | `/requests/{id}/submit` | Draft → Submitted |
| `POST` | `/requests/{id}/review` | Submitted → UnderReview (assigne le réviseur) |
| `POST` | `/requests/{id}/approve` | UnderReview → Approved |
| `POST` | `/requests/{id}/reject` | UnderReview → Rejected (raison requise) |
| `POST` | `/requests/{id}/rework` | Rejected → Draft (efface réviseur/note) |

---

## Protection des transitions dans le repository

Le repository vérifie `canTransitionTo()` avant d'exécuter la requête UPDATE :

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // l'appelant mappe null → 409 Conflict
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

Retourner `null` pour "non trouvé" et "transition invalide" est une simplification délibérée.
En production, distinguer entre 404 (non trouvé) et 409 (trouvé mais transition invalide)
en retournant un résultat typé ou en levant des exceptions de domaine.

Le contrôleur mappe `null → 409 Conflict` :

```php
private function submit(ServerRequestInterface $request): ResponseInterface
{
    $id  = (int) ($params['id'] ?? 0);
    $req = $this->repo->submit($id, $now);

    if ($req === null) {
        return $this->problems->create(
            $request,
            'conflict',
            'Request not found or cannot be submitted from its current status.',
            409,
            '',
        );
    }

    return $this->json->create($req->toArray());
}
```

---

## Le rejet nécessite une raison

La transition `reject` nécessite à la fois `reviewer` et `note` :

```php
private function reject(ServerRequestInterface $request): ResponseInterface
{
    $reviewer = isset($body['reviewer']) && is_string($body['reviewer']) ? trim($body['reviewer']) : '';
    $note     = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

    if ($reviewer === '' || $note === '') {
        $errors = [];
        if ($reviewer === '') {
            $errors[] = ['field' => 'reviewer', 'code' => 'required', 'message' => 'reviewer is required.'];
        }
        if ($note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required', 'message' => 'note (rejection reason) is required.'];
        }

        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, compact('errors'));
    }
    // ...
}
```

Rejeter sans raison est rejeté (422). Approuver sans note est autorisé — le champ `note`
est optionnel pour les approbations.

---

## Retravail : effacement de l'état de révision

Quand une demande rejetée est retravaillée, le réviseur et la note de révision sont effacés pour que
le prochain réviseur parte d'une page blanche :

```php
// Repository : rework (Rejected → Draft)
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

L'horodatage `submitted_at` est préservé — il enregistre quand la demande a été soumise pour la première fois,
pas le cycle actuel.

---

## Schéma

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- NULL jusqu'au début de la révision
    review_note  TEXT,             -- NULL jusqu'à la révision
    submitted_at TEXT,             -- NULL jusqu'à la soumission
    reviewed_at  TEXT,             -- NULL jusqu'à l'approbation/rejet
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Les colonnes nullable (`reviewer`, `review_note`, `submitted_at`, `reviewed_at`) sont remises à
`NULL` au retravail, gardant le schéma propre sans ajouter une colonne `rework_count`.

> **Amélioration** : ajouter un `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))`
> comme garde-fou au niveau DB correspondant aux valeurs d'enum.

---

## Filtre de statut sur l'endpoint de liste

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $params    = $request->getQueryParams();
    $statusRaw = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
    $status    = $statusRaw !== null ? ApprovalStatus::tryFrom($statusRaw) : null;

    if ($statusRaw !== null && $status === null) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'status', 'code' => 'invalid_value', 'message' => 'Invalid status value.']],
        ]);
    }

    $requests = $this->repo->listByStatus($status);
    // ...
}
```

`ApprovalStatus::tryFrom()` retourne `null` pour les chaînes de statut inconnues → 422. Quand
`$statusRaw === null` (pas de filtre), toutes les demandes sont retournées.

---

## Ajouter une nouvelle transition

Pour ajouter un état `cancelled` atteignable depuis n'importe quel état non terminal :

1. Ajouter `case Cancelled = 'cancelled';` à `ApprovalStatus`.
2. Mettre à jour `allowedTransitions()` pour `Draft`, `Submitted` et `UnderReview` pour
   inclure `self::Cancelled`.
3. Ajouter la route `POST /requests/{id}/cancel` et son gestionnaire.
4. Écrire l'UPDATE DB dans le repository.
5. Mettre à jour la contrainte `CHECK` du schéma (si ajoutée).

L'enum est la source unique de vérité — aucun autre fichier n'a besoin de changer pour ajouter
le garde de transition.

---

## Howtos associés

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — cycle de vie draft → publication (machine d'états plus simple)
- [`media-watchlist.md`](media-watchlist.md) — validation d'enum backed avec `tryFrom()`
- [`add-custom-route.md`](add-custom-route.md) — pattern d'endpoint d'action POST
- [`multi-step-workflow.md`](multi-step-workflow.md) — patterns génériques de workflow multi-étapes
