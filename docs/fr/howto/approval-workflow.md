# How-to : API de workflow d'approbation

> **Référence FT** : FT68 (`NENE2-FT/approvallog`) — API de workflow d'approbation

Démontre un workflow d'approbation multi-étapes où une demande passe par des états définis (Brouillon → Soumis → En révision → Approuvé/Rejeté). Les transitions invalides retournent 409 Conflict. La machine d'état est encodée directement dans l'enum backed `ApprovalStatus` via une méthode `allowedTransitions()`.

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
| `draft` | Créé mais pas encore soumis |
| `submitted` | En attente d'assignation de révision |
| `under_review` | Réviseur assigné et en cours de révision |
| `approved` | Approbation finale accordée |
| `rejected` | Rejeté avec une raison obligatoire |

Une demande rejetée peut être retravaillée (retournée à `draft`) pour révision et nouvelle soumission.
Une demande approuvée n'a plus de transitions.

---

## Règles de transition encodées dans l'enum

Les règles de transition d'état résident dans l'enum — pas dans le repository ou le contrôleur :

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

`canTransitionTo()` est la seule source de vérité pour savoir si une transition est valide.
Ajouter une nouvelle transition autorisée signifie modifier uniquement cette méthode.

---

## Routes

| Méthode | Chemin                          | Description                            |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests`                   | Créer une demande brouillon                 |
| `GET`  | `/requests`                   | Lister toutes les demandes (filtre `?status=`)  |
| `GET`  | `/requests/{id}`              | Obtenir une demande                   |
| `POST` | `/requests/{id}/submit`       | Draft → Submitted                      |
| `POST` | `/requests/{id}/review`       | Submitted → UnderReview (assigne un réviseur) |
| `POST` | `/requests/{id}/approve`      | UnderReview → Approved                 |
| `POST` | `/requests/{id}/reject`       | UnderReview → Rejected (raison requise) |
| `POST` | `/requests/{id}/rework`       | Rejected → Draft (efface réviseur/note) |

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
En production, distinguez entre 404 (non trouvé) et 409 (trouvé mais transition invalide)
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

La transition `reject` requiert à la fois `reviewer` et `note` :

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
le prochain réviseur commence à zéro :

```php
// Repository : retravail (Rejected → Draft)
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

Le timestamp `submitted_at` est préservé — il enregistre quand la demande a été soumise pour la première fois,
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

Les colonnes nullables (`reviewer`, `review_note`, `submitted_at`, `reviewed_at`) sont remises à
`NULL` lors du retravail, maintenant le schéma propre sans ajouter une colonne `rework_count`.

> **Amélioration** : ajoutez un `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))`
> comme garde-fou au niveau DB correspondant aux valeurs de l'enum.

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

Pour ajouter un état `cancelled` accessible depuis tout état non terminal :

1. Ajoutez `case Cancelled = 'cancelled';` à `ApprovalStatus`.
2. Mettez à jour `allowedTransitions()` pour `Draft`, `Submitted` et `UnderReview` pour
   inclure `self::Cancelled`.
3. Ajoutez la route `POST /requests/{id}/cancel` et son gestionnaire.
4. Écrivez le UPDATE DB dans le repository.
5. Mettez à jour la contrainte `CHECK` du schéma (si ajoutée).

L'enum est la seule source de vérité — aucun autre fichier n'a besoin d'être modifié pour ajouter la garde de transition.

---

## Howtos associés

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — cycle de vie draft → publish (machine d'état plus simple)
- [`media-watchlist.md`](media-watchlist.md) — validation d'enum backed avec `tryFrom()`
- [`add-custom-route.md`](add-custom-route.md) — pattern d'endpoint d'action POST
- [`multi-step-workflow.md`](multi-step-workflow.md) — patterns de workflow multi-étapes génériques
