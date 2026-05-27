# How-to : Système de réservation de ressources

## Vue d'ensemble

Ce guide couvre la construction d'une API de réservation de ressources avec NENE2. Les fonctionnalités incluent l'application de capacité, la prévention des doubles réservations, l'isolation IDOR par utilisateur et l'annulation admin.

**Implémentation de référence** : `../NENE2-FT/bookinglog/`

---

## Conception du schéma

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

Contraintes clés :
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — une réservation par utilisateur par créneau.
- Flag de suppression douce `cancelled` — préserver l'historique tout en permettant la re-réservation.
- Capacité vérifiée au moment de la requête (compter les réservations actives vs resource.capacity).

---

## Table des routes

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `GET` | `/resources` | Aucune | Lister toutes les ressources |
| `POST` | `/resources` | Admin | Créer une ressource |
| `POST` | `/bookings` | Utilisateur | Réserver un créneau |
| `GET` | `/bookings` | Utilisateur | Lister ses propres réservations |
| `GET` | `/bookings/{id}` | Utilisateur | Obtenir une réservation |
| `DELETE` | `/bookings/{id}` | Utilisateur/Admin | Annuler une réservation |

---

## Prévention des doubles réservations

D'abord, vérifier si l'utilisateur a déjà ce créneau (niveau applicatif) :

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

Ensuite vérifier la capacité :

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## Isolation IDOR

Les utilisateurs ne peuvent lire/annuler que leurs propres réservations. Retourner 404 (pas 403) pour éviter de révéler l'existence :

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## Annulation admin sans X-User-Id

L'admin peut annuler n'importe quelle réservation sans fournir son propre ID utilisateur :

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## Règles de validation

| Champ | Règle |
|-------|-------|
| `resource_id` | `is_int()` + positif |
| `slot_date` | regex `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + positif |
| `name` | chaîne non vide |

---

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Ressource créée | 201 |
| Réservation confirmée | 201 |
| Réservation trouvée / liste | 200 |
| Pas de X-User-Id | 400 |
| Type de champ invalide | 422 |
| Format de date invalide | 422 |
| slot_hour hors 0–23 | 422 |
| Ressource non trouvée | 404 |
| Réservation non trouvée | 404 |
| Pas de clé admin | 403 |
| Annuler sa propre réservation | 200 |
| Annuler la réservation d'un autre | 403 |
| Double réservation | 409 |
| Capacité pleine | 409 |

---

## Patterns VULN couverts

| VULN | Pattern | Défense |
|------|---------|---------|
| A | IDOR : l'utilisateur voit la réservation d'un autre | `WHERE user_id = :uid` + 404 |
| B | resource_id négatif | vérification `is_int() + > 0` |
| C | slot_hour zéro (minuit) | la plage 0-23 autorise 0 |
| D | Injection SQL dans slot_date | Validation regex + requête paramétrée |
| E | Jonglage de type resource_id en chaîne | vérification stricte `is_int()` |
| F | Double réservation | Vérification d'existence avant INSERT |
| G | Débordement de capacité | Vérification COUNT vs capacity |
| H | Pas de X-User-Id | 400 avec message |
| I | Annuler la réservation d'un autre utilisateur | vérification de propriété `user_id` → 403 |
| J | La liste fuit les données d'un autre utilisateur | `WHERE user_id = :uid` |
| K | L'admin annule n'importe quelle réservation | contournement de propriété `isAdmin` |
| L | slot_hour = 24 (hors plage) | `$hour > 23` → 422 |
