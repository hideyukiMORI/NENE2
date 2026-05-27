# Gestion de liste d'attente

Guide d'implémentation d'une liste d'attente basée sur la position.
Couvre le calcul dynamique de position, la machine d'états, la prévention IDOR et les endpoints d'administration.

## Vue d'ensemble

- Les utilisateurs rejoignent la liste d'attente (avec une note optionnelle)
- **Calcul de position dynamique** : la position n'est pas stockée en DB, elle est calculée à la demande avec `COUNT(*)`
- Machine d'états : `waiting` → `approved` / `declined` (à sens unique, non annulable)
- Seuls les utilisateurs en attente peuvent partir (`approved`/`declined` ne peuvent plus partir)
- Les administrateurs peuvent lister, approuver et refuser tous les entrées (`X-Admin-Key`)
- Ne pas inclure `user_id` dans les réponses côté utilisateur (prévention IDOR)

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/waitlist` | `X-User-Id` | Rejoindre la liste d'attente |
| `GET` | `/waitlist/me` | `X-User-Id` | Obtenir son entrée et sa position |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Quitter la liste d'attente |
| `GET` | `/waitlist` | `X-Admin-Key` | Liste de toutes les entrées (admin) |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Approuver une entrée |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Refuser une entrée |

## Conception de la base de données

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'waiting',
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

La contrainte `UNIQUE` sur `user_id` garantit un seul enregistrement par utilisateur.
Pas de colonne de position (calcul dynamique).

## Calcul dynamique de position

Calculer la position d'une entrée en attente par ordre relatif d'`id` :

```sql
SELECT COUNT(*) FROM waitlist_entries
WHERE status = 'waiting' AND id <= :id
```

Avantages :
- Pas besoin d'UPDATE de toutes les entrées à chaque départ, approbation ou refus
- Pas de conflits d'écriture
- Retourne `null` pour les entrées dont `status` n'est pas `waiting`

```php
public function positionOf(WaitlistEntry $entry): ?int
{
    if ($entry->status !== WaitlistStatus::Waiting) {
        return null;
    }

    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM waitlist_entries
         WHERE status = 'waiting' AND id <= :id",
    );
    $stmt->execute(['id' => $entry->id]);

    return (int) $stmt->fetchColumn();
}
```

## Machine d'états

```
waiting ──→ approved
        └─→ declined
```

- `waiting` ne peut transitionner qu'en `approved` ou `declined`
- Une fois en état terminal, aucun changement possible (déterminé par `isTerminal()`)
- Seuls les utilisateurs en attente peuvent partir avec `DELETE /waitlist/me`

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

## Prévention IDOR

L'endpoint utilisateur (`/waitlist/me`) récupère uniquement **sa propre entrée** via l'en-tête `X-User-Id`.
Il n'y a pas de possibilité de passer l'`user_id` d'un autre utilisateur dans le chemin, et la réponse ne contient pas non plus `user_id`.

```php
/** Réponse côté utilisateur (sans user_id) */
public function toPublicArray(): array
{
    return [
        'id'         => $this->id,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
    ];
}

/** Réponse côté admin (avec user_id) */
public function toAdminArray(): array
{
    return [
        'id'         => $this->id,
        'user_id'    => $this->userId,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
    ];
}
```

## Authentification admin

Comparer l'en-tête `X-Admin-Key` en temps constant avec `hash_equals()`.
Un adminKey vide retourne toujours `false` (fail-closed) :

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // fail-closed
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

## Ordre des routes

Enregistrer `GET /waitlist/me` avant `GET /waitlist`.
S'il est enregistré après, `me` risque d'être capturé comme `{id}` :

```php
$this->router->post('/waitlist',            $this->handleJoin(...));
$this->router->get('/waitlist/me',          $this->handleMe(...));      // avant /waitlist
$this->router->delete('/waitlist/me',       $this->handleLeave(...));
$this->router->get('/waitlist',             $this->handleAdminList(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->post('/waitlist/{id}/decline', $this->handleDecline(...));
```

## Validation X-User-Id

Prévenir les débordements d'entiers, les zéros, les nombres négatifs et les valeurs non numériques :

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');

    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

## Points de sécurité

| Menace | Contre-mesure |
|--------|---------------|
| IDOR | `/waitlist/me` pour soi uniquement, ne pas inclure `user_id` dans la réponse |
| Écoute de la clé admin | Comparaison en temps constant avec `hash_equals()` |
| Débordement d'entier | Garde `strlen > 18` |
| Double inscription | Contrainte `UNIQUE(user_id)` → 409 |
| Transition d'état illicite | `isTerminal()` interdit les changements après l'état terminal |
| Injection SQL | Instructions préparées PDO |

## Exemples de réponses

```json
// POST /waitlist (201)
{
    "entry": { "id": 1, "status": "waiting", "note": "Demande VIP", "created_at": "..." },
    "position": 1
}

// GET /waitlist/me — statut approved (200)
{
    "entry": { "id": 1, "status": "approved", "note": "Demande VIP", "created_at": "..." },
    "position": null
}

// GET /waitlist (admin, 200)
{
    "data": [
        { "id": 1, "user_id": 101, "status": "approved", "note": "Demande VIP", ... },
        { "id": 2, "user_id": 102, "status": "waiting",  "note": null,          ... }
    ],
    "total": 2
}
```

## Guides liés

- [Gestion des annonces système](system-announcement-management.md) — pattern d'authentification par clé admin (même usage de `hash_equals()`)
- [Gestion du consentement de confidentialité](privacy-consent-management.md) — UPSERT et opérations idempotentes
- [Suppression douce](soft-delete.md) — pattern de flag de suppression (le départ est une suppression physique)
- [Prévention de double réservation](prevent-double-booking.md) — prévention des conflits par contrainte UNIQUE
