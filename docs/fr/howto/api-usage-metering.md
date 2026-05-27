# How-to : Métrologie d'utilisation d'API et gestion des quotas

> **Référence FT** : FT321 (`NENE2-FT/meterlog`) — Gestion des quotas journaliers par utilisateur, enregistrement d'utilisation protégé par clé machine, répartition par endpoint, protection IDOR, garantie remaining-jamais-négatif, 24 tests / 92 assertions PASS.

Ce guide montre comment construire un système de métrologie d'utilisation qui trace les appels API par utilisateur par jour et applique des quotas journaliers configurables.

## Schéma

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## Constantes

```php
const DEFAULT_DAILY_LIMIT = 1000;  // appliqué quand aucune ligne de quota n'existe
```

## Modèle d'authentification

```
POST /quotas               → X-Admin-Key   (configuration des quotas)
POST /usage                → X-Machine-Key (enregistrement d'utilisation côté serveur)
POST /usage/check          → X-Machine-Key (vérification pré-vol de quota)
GET  /usage/{id}/breakdown → X-User-Id (propre) OU X-Admin-Key (n'importe quel)
```

## Gestion des quotas (Admin)

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// Upsert — mise à jour d'un quota existant
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// Pas de clé admin  → 401
// Mauvaise clé      → 401
// daily_limit <= 0  → 422
```

## Statut du quota

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// Utilisateur sans ligne de quota → DEFAULT_DAILY_LIMIT appliqué
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **ne devient jamais négatif**.

## Enregistrement de l'utilisation

Appelé côté serveur après chaque requête API réussie :

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// Pas de clé machine → 401
// user_id <= 0       → 422
// endpoint vide      → 422
```

## Vérification de quota pré-vol

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // dans le quota
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // épuisé
```

## Répartition de l'utilisation

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR bloqué
GET /usage/1/breakdown  X-User-Id: 2        → 403
// Admin peut accéder à n'importe quel utilisateur
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// Date invalide
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## Évaluation des vulnérabilités

### V-01 — Administration de quota sans clé ✅ SAFE

**Risque** : Un appelant non authentifié définit le quota à 0 ou INT_MAX pour n'importe quel utilisateur.
**Résultat** : SAFE — `POST /quotas` nécessite `X-Admin-Key`. Clé manquante ou incorrecte retourne 401.

---

### V-02 — Contournement de clé admin par casse/variante ✅ SAFE

**Risque** : L'attaquant essaie `ADMIN-SECRET`, `admin_secret`, `""` pour contourner la vérification de clé.
**Résultat** : SAFE — Correspondance exacte `hash_equals()`. Toutes les variantes retournent 401.

---

### V-03 — daily_limit non-positif ✅ SAFE

**Risque** : `daily_limit=0` ou `-1` bloque définitivement l'utilisateur.
**Résultat** : SAFE — 422 pour `daily_limit <= 0`.

---

### V-04 — Enregistrement d'utilisation sans clé machine ✅ SAFE

**Risque** : Un appelant externe enregistre une fausse utilisation pour épuiser le quota.
**Résultat** : SAFE — `POST /usage` nécessite `X-Machine-Key`. 401 en cas de clé manquante/incorrecte.

---

### V-05 — Injection SQL dans le champ endpoint ✅ SAFE

**Risque** : `"'; DROP TABLE usage_events; --"` corrompt la DB.
**Résultat** : SAFE — Requêtes paramétrées. L'injection est stockée comme chaîne littérale. La table survit.

---

### V-06 — user_id non-positif dans l'utilisation ✅ SAFE

**Risque** : `user_id=0/-1` insère une ligne pour un utilisateur inexistant.
**Résultat** : SAFE — 422 pour `user_id <= 0`.

---

### V-07 — IDOR sur la répartition ✅ SAFE

**Risque** : L'utilisateur lit les patterns d'utilisation des endpoints d'un autre utilisateur.
**Résultat** : SAFE — `X-User-Id` comparé au `{id}` du chemin. Incompatibilité → 403. L'admin contourne.

---

### V-08 — Date invalide dans la répartition ✅ SAFE

**Risque** : La traversée de chemin ou une date impossible dans le paramètre `date=` cause un crash ou une erreur SQL.
**Résultat** : SAFE — `/^\d{4}-\d{2}-\d{2}$/` + validation `checkdate()`. Invalide → 422.

---

### V-09 — Le quota restant devient négatif ✅ SAFE

**Risque** : `remaining` négatif affiché aux clients quand l'utilisation dépasse le quota réduit.
**Résultat** : SAFE — `remaining = max(0, $daily_limit - $used)`.

---

### V-10 — Chaîne d'endpoint vide ✅ SAFE

**Risque** : Un endpoint vide crée des lignes de répartition inutilisables.
**Résultat** : SAFE — 422 pour `endpoint === ''`.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|---------|
| V-01 | Administration de quota sans clé | ✅ SAFE |
| V-02 | Contournement par casse/variante de clé | ✅ SAFE |
| V-03 | daily_limit non-positif | ✅ SAFE |
| V-04 | Utilisation sans clé machine | ✅ SAFE |
| V-05 | Injection SQL dans l'endpoint | ✅ SAFE |
| V-06 | user_id non-positif | ✅ SAFE |
| V-07 | IDOR sur la répartition | ✅ SAFE |
| V-08 | Format de date invalide | ✅ SAFE |
| V-09 | Quota restant négatif | ✅ SAFE |
| V-10 | Chaîne d'endpoint vide | ✅ SAFE |

**10 SAFE, 0 EXPOSÉS** — Aucun résultat critique.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Laisser `remaining` devenir négatif | Nombres négatifs déroutants ; la logique de portail se casse |
| Pas de clé machine sur l'enregistrement d'utilisation | N'importe quel client gonfle/dégonfle le quota d'un autre utilisateur |
| Pas de vérification IDOR sur la répartition | Les patterns d'utilisation des endpoints fuient vers des utilisateurs non autorisés |
| Enregistrer l'utilisation avant la vérification du quota | Les appels rejetés consomment quand même du quota |
| Autoriser `daily_limit=0` | L'utilisateur est définitivement bloqué dès le départ |
