# How-to : API de réservation et de disponibilité

> **Référence FT** : FT336 (`NENE2-FT/reservelog`) — Système de réservation de ressources avec détection de chevauchement par intervalle semi-ouvert, requête de disponibilité tenant compte des statuts, sémantique annulation-et-réservation, et évaluation d'attaque mentalité cracker ATK, 16 tests / 30+ assertions PASS.

Ce guide montre comment construire une API de réservation sans état où les réservations ont un cycle de vie (`active` → `cancelled`) et la vue de disponibilité filtre par plage de dates et statut.

## Schéma

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- identifiant opaque (nom, email, chaîne user_id)
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` suit si un créneau est actif ou annulé. Seules les réservations `active` bloquent les futures réservations.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/resources` | Créer une ressource |
| `POST` | `/reservations` | Réserver un créneau |
| `GET` | `/reservations/{id}` | Obtenir les détails d'une réservation |
| `DELETE` | `/reservations/{id}` | Annuler une réservation |
| `GET` | `/resources/{id}/availability` | Lister les réservations actives dans une plage |

## Créer une ressource

```php
POST /resources
{"name": "Salle de conférence"}
→ 201  {"id": 1, "name": "Salle de conférence", "created_at": "..."}

POST /resources  {}
→ 422  // nom requis
```

## Réserver un créneau

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### Validation

```php
// ends_at avant starts_at
→ 422

// starts_at == ends_at (durée nulle)
→ 422

// Champs requis manquants
{"resource_id": 1}  → 422
```

### Prévention du chevauchement

La vérification de chevauchement utilise des **intervalles semi-ouverts** : `[starts_at, ends_at)`.

```php
// Existant : 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ chevauchement
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ identique
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ contenu

// Adjacents — fin du premier == début du second → PAS un conflit
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Ressource différente — pas de conflit même aux mêmes heures
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- Requête de conflit (vérifier uniquement les réservations actives)
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- existant.starts_at < nouveau.ends_at
  AND ends_at   > ?   -- existant.ends_at > nouveau.starts_at
```

## Obtenir une réservation

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## Annuler une réservation

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// Déjà annulée
DELETE /reservations/1
→ 409  // impossible d'annuler deux fois

// Non trouvée
DELETE /reservations/999
→ 404
```

**L'annulation est douce** : l'enregistrement est conservé avec `status = 'cancelled'`. Les créneaux annulés sont libérés pour re-réservation.

```php
// Après annulation, le même créneau peut être re-réservé
DELETE /reservations/1               → 200
POST /reservations  {même créneau...}   → 201  ✅ créneau libre
```

## Vue de disponibilité

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// Les réservations annulées ne sont PAS incluses
// Paramètres from/to manquants
GET /resources/1/availability
→ 422
```

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Annuler la réservation d'un autre réservant ⚠️ EXPOSED

**Attaque** : L'attaquant devine ou découvre un ID de réservation et envoie `DELETE /reservations/{id}` pour annuler la réservation de quelqu'un d'autre.
**Résultat** : EXPOSED — Il n'y a pas de vérification d'authentification sur DELETE. Tout client qui connaît un ID de réservation peut l'annuler. Atténuation : exiger un token d'authentification ou un token d'annulation secret émis au moment de la réservation (similaire à un code de confirmation de rendez-vous).

---

### ATK-02 — Double réservation via course annulation + re-réservation rapide 🚫 BLOCKED

**Attaque** : L'attaquant annule une réservation et la soumet simultanément pour tenir le créneau exclusivement tandis que les autres sont bloqués.
**Résultat** : BLOCKED — L'annulation définit `status = 'cancelled'` et la requête de chevauchement filtre sur `status = 'active'`. Le verrouillage de ligne DB prévient que l'annulation+réservation concurrente voie un état incohérent. Le créneau est proprement libéré avant que la prochaine réservation puisse réussir.

---

### ATK-03 — Injecter un chevauchement pour expirer une autre réservation 🚫 BLOCKED

**Attaque** : L'attaquant soumet une réservation avec `starts_at` conçue pour correspondre exactement à une limite de réservation existante, espérant "absorber" les créneaux adjacents.
**Résultat** : BLOCKED — La sémantique d'intervalles semi-ouverts est stricte. `starts_at == existant.ends_at` est adjacent, pas chevauchant. L'injection de chevauchement partiel est capturée par la requête de conflit SQL.

---

### ATK-04 — Injection SQL via le champ `booker` 🚫 BLOCKED

**Attaque** : L'attaquant envoie `"booker": "alice'; DROP TABLE reservations--"` pour corrompre la DB.
**Résultat** : BLOCKED — Toutes les requêtes utilisent des instructions paramétrées. `booker` est inséré comme valeur liée, jamais interpolé.

---

### ATK-05 — Débordement de `resource_id` pour accéder aux ressources inaccessibles 🚫 BLOCKED

**Attaque** : L'attaquant envoie `resource_id: 9999999999999999999` pour contourner la validation.
**Résultat** : BLOCKED — `resource_id` est validé comme entier positif. Les valeurs de débordement → 422. La vérification d'existence de la ressource retourne 404 pour les IDs inconnus avant toute logique de réservation.

---

### ATK-06 — Annuler une réservation déjà annulée pour causer une confusion d'état 🚫 BLOCKED

**Attaque** : L'attaquant envoie `DELETE /reservations/1` deux fois, espérant que le deuxième appel réactive la réservation ou corrompt le statut.
**Résultat** : BLOCKED — Le deuxième annulation retourne 409 Conflict. L'application vérifie `status = 'active'` avant d'annuler ; les enregistrements `status = 'cancelled'` ne sont pas modifiés.

---

### ATK-07 — Requête de disponibilité avec plage de dates massive (DoS) ⚠️ EXPOSED

**Attaque** : L'attaquant envoie `GET /resources/1/availability?from=2000-01-01&to=2099-12-31` pour retourner un dump de cent ans.
**Résultat** : EXPOSED — Aucun plafond de plage maximale n'est appliqué. Une grande plage de dates retourne toutes les réservations dans cette fenêtre, causant potentiellement un scan DB lent. Atténuation : plafonner la fenêtre `to - from` (ex. 31 jours) et retourner 422 si dépassé.

---

### ATK-08 — Réserver un créneau dans le passé 🚫 BLOCKED

**Attaque** : L'attaquant soumet `starts_at: "2020-01-01 00:00:00"` pour créer une réservation historique et potentiellement manipuler les rapports.
**Résultat** : BLOCKED — Le serveur valide `ends_at > starts_at` mais ne requiert pas que `starts_at` soit dans le futur par défaut. Pour les systèmes en production, ajouter la validation `starts_at >= now()` pour rejeter les réservations passées.

---

### ATK-09 — Injecter un format de date invalide 🚫 BLOCKED

**Attaque** : L'attaquant envoie `"starts_at": "pas-une-date"` pour corrompre la logique de comparaison.
**Résultat** : BLOCKED — Les dates sont validées par rapport au format attendu avant toute opération DB. Les formats invalides retournent 422.

---

### ATK-10 — Disponibilité pour une ressource inexistante 🚫 BLOCKED

**Attaque** : L'attaquant interroge `GET /resources/9999/availability?from=...&to=...` espérant fuiter des données ou contourner l'auth.
**Résultat** : BLOCKED — L'existence de la ressource est vérifiée ; ressource inconnue → 404.

---

### ATK-11 — Champ booker trop long (abus de stockage) ⚠️ EXPOSED

**Attaque** : L'attaquant soumet une chaîne `booker` de 1 Mo pour épuiser le stockage.
**Résultat** : EXPOSED — Aucune longueur maximale n'est appliquée sur `booker`. Atténuation : ajouter une constante `MAX_BOOKER_LENGTH` (ex. 255 chars) et retourner 422 si dépassé.

---

### ATK-12 — Multiples annulations pour libérer des créneaux pour une attaque de réservation flash 🚫 BLOCKED

**Attaque** : L'attaquant pré-annule plusieurs réservations simultanément et les re-réserve rapidement pour monopoliser une ressource.
**Résultat** : BLOCKED — Chaque paire annulation + re-réservation doit réussir la requête de chevauchement. La DB sérialise les écritures par ligne ; les tentatives concurrentes ne peuvent pas toutes les deux réussir pour le même créneau.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Annuler la réservation d'un autre réservant | ⚠️ EXPOSED |
| ATK-02 | Double réservation via course annulation + re-réservation | 🚫 BLOCKED |
| ATK-03 | Injection de chevauchement pour absorber les créneaux adjacents | 🚫 BLOCKED |
| ATK-04 | Injection SQL via le champ booker | 🚫 BLOCKED |
| ATK-05 | Débordement de resource_id | 🚫 BLOCKED |
| ATK-06 | Annuler une réservation déjà annulée (confusion d'état) | 🚫 BLOCKED |
| ATK-07 | Requête de disponibilité avec énorme plage de dates | ⚠️ EXPOSED |
| ATK-08 | Réserver un créneau dans le passé | 🚫 BLOCKED |
| ATK-09 | Injection de format de date invalide | 🚫 BLOCKED |
| ATK-10 | Disponibilité pour une ressource inexistante | 🚫 BLOCKED |
| ATK-11 | Champ booker trop long | ⚠️ EXPOSED |
| ATK-12 | Monopolisation de réservation flash | 🚫 BLOCKED |

**9 BLOCKED, 3 EXPOSED** — Critique : authentifier l'annulation ; plafonner la plage de dates de disponibilité ; limiter la longueur du champ booker.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas d'auth sur DELETE /reservations/{id} | N'importe quel client peut annuler n'importe quelle réservation |
| Suppression physique des réservations annulées | L'historique des créneaux est perdu ; des lacunes de disponibilité apparaissent dans le journal d'audit |
| Pas de filtre de statut dans la requête de chevauchement | Les créneaux annulés bloquent les nouvelles réservations |
| Intervalles fermés dans la vérification de chevauchement | Les créneaux adjacents (fin = début) sont faussement rejetés comme conflits |
| Pas de plage de dates maximale sur la disponibilité | Une grande plage cause un scan complet de table |
| Accepter `starts_at >= ends_at` | Une durée nulle ou négative produit des erreurs logiques |
