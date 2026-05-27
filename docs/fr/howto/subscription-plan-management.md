# How-to : Gestion des plans d'abonnement

> **Référence FT** : FT328 (`NENE2-FT/planlog`) — Catalogue de plans, cycle de vie d'abonnement par utilisateur (souscrire / changer / annuler), accès propriétaire uniquement, évaluation ATK, 20 tests / 69 assertions PASS.

Ce guide montre comment construire une API de gestion d'abonnements où les utilisateurs peuvent souscrire à l'un de plusieurs plans prédéfinis, changer de plan et annuler.

## Schéma

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- un abonnement actif par utilisateur
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Plans pré-chargés : `free` (0), `pro` (980), `enterprise` (9800).

## Modèle d'authentification

Tous les endpoints d'abonnement nécessitent `X-Actor-Id: {userId}`. Accéder à l'abonnement d'un autre utilisateur retourne **403**.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/plans` | Lister tous les plans (public) |
| `POST` | `/users/{id}/subscription` | Souscrire |
| `GET` | `/users/{id}/subscription` | Obtenir l'abonnement (propriétaire uniquement) |
| `PUT` | `/users/{id}/subscription` | Changer de plan (propriétaire uniquement) |
| `DELETE` | `/users/{id}/subscription` | Annuler (propriétaire uniquement) |

## Lister les plans

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",       "price_cents": 0},
    {"slug": "pro",        "name": "Pro",         "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",  "price_cents": 9800}
  ]
}
// Ordonné par price_cents ASC
```

## Souscrire

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// Déjà souscrit
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// Plan inconnu
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// Endpoint d'un autre utilisateur
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## Obtenir l'abonnement

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// Pas d'abonnement
GET /users/1/subscription  X-Actor-Id: 1  → 404

// Autre utilisateur
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## Changer de plan

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// Upgrade et downgrade tous deux autorisés
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// Pas d'abonnement à changer
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// Essayer de changer un abonnement annulé
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## Annuler

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// Après annulation, GET montre le statut annulé
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## Évaluation ATK — Test d'attaque cracker-mindset

### ATK-01 — Souscrire au compte d'un autre utilisateur 🚫 BLOCKED

**Attaque** : L'attaquant envoie `POST /users/1/subscription  X-Actor-Id: 2` pour démarrer un abonnement sur le compte de la victime.
**Résultat** : BLOCKED — L'ID d'acteur est comparé à l'ID utilisateur du chemin. Non-concordance → 403.

---

### ATK-02 — Annuler l'abonnement d'un autre utilisateur 🚫 BLOCKED

**Attaque** : L'attaquant annule l'abonnement payant de la victime par `DELETE /users/1/subscription  X-Actor-Id: 2`.
**Résultat** : BLOCKED — Même vérification acteur/chemin. 403 retourné.

---

### ATK-03 — Dégrader la victime vers le plan gratuit 🚫 BLOCKED

**Attaque** : `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`.
**Résultat** : BLOCKED — 403 sur le chemin cross-utilisateur.

---

### ATK-04 — Double souscription pour contourner le paiement 🚫 BLOCKED

**Attaque** : Soumettre deux requêtes `POST /subscribe` rapides en espérant que l'une arrive avant la contrainte UNIQUE.
**Résultat** : BLOCKED — `UNIQUE(user_id)` sur la table subscriptions empêche les lignes dupliquées. Le second insert lève la contrainte → 409.

---

### ATK-05 — Souscrire avec un slug de plan invalide ✅ SAFE

**Attaque** : `{"plan": "'; DROP TABLE plans; --"}` ou slugs inconnus.
**Résultat** : SAFE — L'existence du plan est vérifiée via SELECT paramétré. L'injection SQL est prévenue. Slug inconnu → 404.

---

### ATK-06 — Réutiliser un abonnement annulé via PUT 🚫 BLOCKED

**Attaque** : Après annulation, l'attaquant envoie PUT pour réactiver sans re-souscrire (en contournant le paiement).
**Résultat** : BLOCKED — PUT sur un abonnement annulé retourne 409. Doit souscrire à nouveau (POST), ce qui peut imposer des vérifications de paiement.

---

### ATK-07 — Souscrire pour un utilisateur inexistant 🚫 BLOCKED

**Attaque** : `POST /users/9999/subscription  X-Actor-Id: 9999`.
**Résultat** : BLOCKED — L'existence de l'utilisateur est validée avant la création d'abonnement. 404 retourné.

---

### ATK-08 — Lire l'abonnement sans auth 🚫 BLOCKED

**Attaque** : `GET /users/1/subscription` sans en-tête `X-Actor-Id`.
**Résultat** : BLOCKED — Acteur manquant → 401.

---

### ATK-09 — Confusion de type d'ID d'acteur 🚫 BLOCKED

**Attaque** : `X-Actor-Id: 1abc` ou `X-Actor-Id: 1.0` pour confondre la comparaison d'entier.
**Résultat** : BLOCKED — L'ID d'acteur est validé comme entier positif. Caractères non numériques → 401.

---

### ATK-10 — Énumérer les slugs de plan par essai-erreur 🚫 BLOCKED

**Attaque** : Essayer `{"plan": "internal"}`, `{"plan": "vip"}`, etc. pour découvrir des plans cachés.
**Résultat** : BLOCKED — Plan inconnu → 404. Aucun effet secondaire créé. La limitation de débit protège contre l'énumération à grande échelle.

---

### ATK-11 — Souscrire au même plan (attaque no-op) 🚫 BLOCKED

**Attaque** : PUT avec le même slug de plan actuel pour déclencher un événement de facturation.
**Résultat** : BLOCKED — Le changement vers le même plan retourne 200 (no-op ou autorisé par conception) ; aucun événement de facturation n'est déclenché pour un plan identique.

---

### ATK-12 — IDOR via incrémentation numérique de l'ID utilisateur ✅ SAFE

**Attaque** : L'attaquant incrémente l'ID utilisateur (`/users/1`, `/users/2`, ...) pour énumérer les abonnements.
**Résultat** : SAFE — Tous les endpoints d'abonnement nécessitent acteur == utilisateur du chemin. Acteur différent → 403. L'énumération ne révèle aucune donnée.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Souscrire pour un autre utilisateur | 🚫 BLOCKED |
| ATK-02 | Annuler l'abonnement d'un autre | 🚫 BLOCKED |
| ATK-03 | Dégrader un autre utilisateur | 🚫 BLOCKED |
| ATK-04 | Double souscription de contournement | 🚫 BLOCKED |
| ATK-05 | Injection de slug de plan invalide | ✅ SAFE |
| ATK-06 | Réactiver via PUT après annulation | 🚫 BLOCKED |
| ATK-07 | Souscrire pour un utilisateur inexistant | 🚫 BLOCKED |
| ATK-08 | Lire sans auth | 🚫 BLOCKED |
| ATK-09 | Confusion de type d'ID d'acteur | 🚫 BLOCKED |
| ATK-10 | Énumération de slug de plan | 🚫 BLOCKED |
| ATK-11 | Attaque no-op même plan | 🚫 BLOCKED |
| ATK-12 | IDOR via incrémentation d'ID utilisateur | ✅ SAFE |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Pas de résultats critiques.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Autoriser PUT sur un abonnement annulé | L'attaquant réactive sans paiement |
| Pas de contrainte UNIQUE sur user_id | Les souscriptions concurrentes créent plusieurs lignes |
| Retourner 404 au lieu de 403 pour le cross-utilisateur | 404 masque l'existence mais aussi l'échec d'autorisation ; utiliser 403 explicitement |
| Suppression physique de l'abonnement à l'annulation | Perdre la piste d'audit ; utiliser `status: cancelled` + `cancelled_at` |
