# How-to : API de réservation de ressources et de créneaux

> **Référence FT** : FT335 (`NENE2-FT/reservationlog`) — Réservation de créneaux horaires de ressources avec prévention du chevauchement par intervalle semi-ouvert, user_id exclu des réponses publiques, protection IDOR à l'annulation (403), accès à deux niveaux admin/utilisateur, 30 tests / 70+ assertions PASS.

Ce guide montre comment construire un système de réservation de salle/ressource : créer des ressources réservables (admin), réserver des créneaux horaires (utilisateurs), prévenir les chevauchements atomiquement et protéger la confidentialité des utilisateurs dans les réponses publiques.

## Schéma

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- optionnel
    created_at  TEXT    NOT NULL
);
```

Les dates sont stockées comme chaînes ISO 8601 UTC (`2026-06-01T09:00:00Z`). La comparaison lexicographique est correcte pour les chaînes ISO UTC.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/resources` | Admin | Créer une ressource réservable |
| `POST` | `/resources/{id}/book` | Utilisateur | Réserver un créneau |
| `DELETE` | `/bookings/{id}` | Utilisateur (propriétaire) | Annuler sa propre réservation |
| `GET` | `/bookings` | Utilisateur | Lister ses propres réservations |
| `GET` | `/resources/{id}/bookings` | Admin | Lister toutes les réservations d'une ressource |

## Créer une ressource (Admin)

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Salle de réunion 1"}
→ 201  {"resource": {"id": 1, "name": "Salle de réunion 1", "created_at": "..."}}

// Pas de clé admin
POST /resources  {"name": "Salle"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // nom requis

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // nom trop long (max 200 chars)
```

## Réserver un créneau

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id n'est PAS retourné — prévention IDOR
  }
}

// Note optionnelle
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Réunion d'équipe"}
→ 201  {"booking": {..., "note": "Réunion d'équipe"}}
```

### Erreurs de validation

```php
// ends_at avant starts_at
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at (durée nulle)
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at manquant
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// X-User-Id manquant
POST /resources/1/book  (pas d'en-tête)
→ 400

// X-User-Id zéro ou débordement
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// Ressource inconnue
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## Prévention du chevauchement — Intervalles semi-ouverts

Les créneaux sont **semi-ouverts** : `[starts_at, ends_at)`. La fin d'une réservation et le début de la suivante sont égaux mais pas chevauchants.

```php
// Réserver 09:00–10:00
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// Chevauchant — commence à l'intérieur du créneau existant
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅ adjacent, autorisé

// Chevauchant — à l'intérieur
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌ chevauchement

// Créneau identique
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// Non chevauchant sur la même ressource
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// Même créneau sur une RESSOURCE DIFFÉRENTE — toujours autorisé
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### Requête SQL de chevauchement

```sql
-- Détecter le conflit : NOT (nouveau.ends_at <= existant.starts_at OR nouveau.starts_at >= existant.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existant.starts_at < nouveau.ends_at
  AND ends_at   > ?   -- existant.ends_at > nouveau.starts_at
```

Si count > 0, retourner 409 Conflict.

## Annuler une réservation (Protection IDOR)

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// Mauvais utilisateur → 403, PAS 404
DELETE /bookings/1
X-User-Id: 102
→ 403  // l'utilisateur 102 ne possède pas la réservation 1

// Non trouvé → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**Retourner 403 (pas 404) pour l'annulation par le mauvais utilisateur** — retourner 404 permettrait aux utilisateurs de sonder les IDs de réservation d'autres utilisateurs. La réservation existe ; le demandeur n'en est pas le propriétaire.

```php
// Après annulation, le créneau est libre
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### Validation des IDs

```php
DELETE /bookings/0                    → 422  // zéro est invalide
DELETE /bookings/99999999999999999999 → 422  // débordement
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id zéro invalide
```

## Lister ses propres réservations (Utilisateur)

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id n'est PAS inclus
  ]
}

// Les réservations des autres utilisateurs ne sont pas retournées
// L'utilisateur 101 ne voit que ses propres réservations même si l'utilisateur 102 en a
```

## Lister les réservations d'une ressource (Admin)

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id visible
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// Trié par starts_at ASC

GET /resources/1/bookings  (pas de clé admin)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

L'admin reçoit `user_id` dans la réponse ; les endpoints utilisateurs publics ne retournent jamais `user_id`.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Vérification de chevauchement : `nouveau.starts_at < existant.ends_at AND nouveau.ends_at > existant.starts_at` (intervalles fermés) | Les créneaux adjacents (fin de A = début de B) sont rejetés comme chevauchants |
| Retourner `user_id` dans les réponses de réservation publiques | Expose qui possède chaque réservation, permettant l'énumération des utilisateurs |
| Retourner 404 pour l'annulation par le mauvais utilisateur | L'attaquant confirme que la réservation existe ; utiliser 403 pour reconnaître l'incompatibilité de propriété |
| Accepter `starts_at >= ends_at` | Les réservations à durée nulle ou négative corrompent les calculs de disponibilité |
| Pas de scopage resource_id dans la requête de chevauchement | La réservation de l'utilisateur A sur la Ressource 1 bloque la Ressource 2 (faux conflit) |
| Faire confiance à `user_id` du body de la requête | L'attaquant fait des réservations au nom de n'importe quel utilisateur ; toujours lire l'identité depuis l'en-tête `X-User-Id` |
