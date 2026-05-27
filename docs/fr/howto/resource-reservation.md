# How-to : API de réservation de ressources / créneaux horaires

Ce guide montre comment construire un système de réservation de créneaux horaires avec prévention du chevauchement en utilisant NENE2.
Pattern démontré par le field trial **reservationlog** (FT216).

## Fonctionnalités

- Créer des ressources nommées (salles de réunion, équipements, etc.) — admin uniquement
- Réserver des créneaux horaires avec détection automatique du chevauchement
- Lister les réservations par ressource (admin) ou par utilisateur (soi-même)
- Annuler des réservations avec vérification de propriété
- Les réponses publiques excluent `user_id` (prévention IDOR)
- La vue admin inclut `user_id` pour l'audit

## Schéma

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- Index pour les requêtes de chevauchement rapides
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/resources` | Admin | Créer une ressource |
| `GET` | `/resources/{id}/bookings` | Admin | Lister toutes les réservations d'une ressource |
| `POST` | `/resources/{id}/book` | Utilisateur | Réserver un créneau |
| `GET` | `/bookings` | Utilisateur | Lister ses propres réservations |
| `DELETE` | `/bookings/{id}` | Utilisateur | Annuler sa propre réservation |

## Détection du chevauchement

Deux plages horaires `[A.start, A.end)` et `[B.start, B.end)` se chevauchent si et seulement si :

```
A.start < B.end AND A.end > B.start
```

Cela gère correctement tous les cas de chevauchement (contient, chevauche, identique) tout en permettant les créneaux adjacents (A.end = B.start est OK — sémantique d'intervalle semi-ouvert).

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## Value Objects

Utilisation de value objects readonly pour la clarté du domaine :

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** Vue publique : exclut user_id (prévention IDOR) */
    public function toPublicArray(): array { ... }

    /** Vue admin : inclut user_id pour l'audit */
    public function toAdminArray(): array { ... }
}
```

## Prévention IDOR

Les réservations exposent des vues publiques et admin avec des champs différents :

```php
// Utilisateur : GET /bookings — vue publique (pas de user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// Admin : GET /resources/{id}/bookings — vue admin (inclut user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

L'annulation retourne 403 (pas 404) quand un utilisateur essaie d'annuler la réservation de quelqu'un d'autre, puisque l'ID de réservation est déjà visible (existence non cachée) :

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## Patterns de sécurité

- **Admin fail-closed** : `if ($this->adminKey === '') return false;` avant `hash_equals()`
- **`ctype_digit()`** : Validation d'entier résistante aux ReDoS pour les IDs de chemin
- **Validation ISO 8601** : Pattern regex + comparaison lexicographique (fonctionne en UTC)
- **Garde de longueur de note** : `mb_strlen($note) > 500` retourne 422
- **Suppression en cascade** : `ON DELETE CASCADE` assure que les réservations sont supprimées avec la ressource

## Évaluation VULN + ATK (FT216)

Ce FT passe les évaluations VULN-A à VULN-L et ATK-01 à ATK-12 complètes :

- **VULN-B** : Pas de mass assignment — les champs resource/booking sont explicitement liés
- **VULN-C** : L'annulation retourne 403 pour le mauvais propriétaire ; les recherches resource/booking utilisent des IDs typés
- **VULN-D** : Admin fail-closed — la clé admin vide retourne toujours false
- **VULN-F** : Le regex ISO 8601 prévient l'injection de datetime
- **VULN-G** : `ctype_digit()` garde tous les paramètres de chemin entiers
- **ATK-01** : Injection SQL bloquée via requêtes paramétrées
- **ATK-02/03** : Débordement d'entier dans les IDs bloqué par la garde `strlen > 18`
- **ATK-06** : Contournement d'authentification bloqué par la vérification admin fail-closed
- **ATK-09** : La logique de chevauchement prévient correctement la double réservation
