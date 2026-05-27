# How-to : API de suppression douce, corbeille et restauration

> **Référence FT** : FT340 (`NENE2-FT/softlog`) — API de notes avec suppression douce (deleted_at), vue corbeille, restauration, suppression physique permanente, purge en masse, tri épinglé en premier, et évaluation d'attaque cracker-mindset ATK, 26 tests / 60+ assertions PASS.

Ce guide montre comment implémenter un cycle de vie de suppression en deux étapes : les éléments sont d'abord supprimés doucement (déplacés vers la corbeille) et peuvent être restaurés, puis effacés définitivement via une suppression physique explicite ou une purge en masse.

## Schéma

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = actif ; ISO 8601 quand supprimé doucement
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = actif ; `deleted_at IS NOT NULL` = supprimé doucement (dans la corbeille).

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/notes` | Créer une note |
| `GET` | `/notes` | Lister les notes actives (épinglées en premier) |
| `GET` | `/notes/{id}` | Obtenir une note active |
| `PUT` | `/notes/{id}` | Mettre à jour une note active |
| `DELETE` | `/notes/{id}` | Suppression douce (→ corbeille) |
| `GET` | `/notes/trash` | Lister les notes dans la corbeille |
| `POST` | `/notes/{id}/restore` | Restaurer depuis la corbeille |
| `DELETE` | `/notes/{id}/permanent` | Suppression physique (permanente) |
| `POST` | `/notes/trash/purge` | Purger toute la corbeille |

## Créer une note

```php
POST /notes
{"title": "My Note", "body": "Content", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Content",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "No title"}  → 422  // title requis
```

## Lister les notes actives (épinglées en premier)

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Pinned", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

Les notes supprimées doucement ne sont jamais retournées dans la liste active.

## Obtenir une note

```php
GET /notes/1
→ 200  {"id": 1, "title": "My Note", ...}

// Supprimée doucement ou inconnue → même 404
GET /notes/9999    → 404
GET /notes/1 (après DELETE /notes/1)  → 404
```

## Mettre à jour une note

```php
PUT /notes/1
{"title": "Updated", "body": "New body", "is_pinned": true}
→ 200  {"title": "Updated", "is_pinned": true, ...}

// Une note supprimée doucement ne peut pas être mise à jour
PUT /notes/1  (après DELETE /notes/1)  → 404
```

## Suppression douce

```php
DELETE /notes/1
→ 204  (pas de body)

// La note disparaît de GET /notes et GET /notes/1
// Mais apparaît dans GET /notes/trash

DELETE /notes/9999  → 404  // non trouvé
```

## Vue corbeille

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Gone", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// Les notes actives NE sont PAS dans la corbeille
```

`deleted_at` est non nul pour tous les éléments de la corbeille.

## Restaurer

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restore Me", "deleted_at": null, ...}

// La note restaurée réapparaît dans GET /notes
// POST /notes/9999/restore  → 404
```

## Suppression physique (permanente)

```php
DELETE /notes/1/permanent
→ 204  (pas de body ; la note est supprimée de la DB)

// Disparue de la corbeille aussi
// DELETE /notes/9999/permanent  → 404
```

## Purger la corbeille

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// Corbeille vide
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` émet `DELETE FROM notes WHERE deleted_at IS NOT NULL` et retourne le nombre de lignes.

---

## Évaluation ATK — Test d'attaque cracker-mindset

### ATK-01 — Suppression physique sans suppression douce préalable 🚫 BLOCKED

**Attaque** : L'attaquant appelle `DELETE /notes/1/permanent` sur une note active (pas encore supprimée doucement).
**Résultat** : BLOCKED — `DELETE /notes/{id}/permanent` vérifie `deleted_at IS NOT NULL` avant de procéder. Les notes actives retournent 404 à l'endpoint de suppression permanente ; seuls les éléments dans la corbeille peuvent être supprimés physiquement.

---

### ATK-02 — Accès à une note supprimée doucement via GET direct ✅ SAFE

**Attaque** : L'attaquant connaît l'ID 5 de la note supprimée doucement et appelle `GET /notes/5` en espérant lire du contenu protégé.
**Résultat** : SAFE — `GET /notes/{id}` interroge `WHERE id = ? AND deleted_at IS NULL`. Les notes supprimées doucement retournent 404 de manière identique aux notes inconnues — pas d'indice d'existence.

---

### ATK-03 — Purge de la corbeille sans auth (destruction de masse) ⚠️ EXPOSED

**Attaque** : Tout client appelle `POST /notes/trash/purge` pour détruire définitivement toutes les notes dans la corbeille appartenant à tous les utilisateurs.
**Résultat** : EXPOSED — Il n'y a pas de vérification d'authentification sur `POST /notes/trash/purge`. Sans scopage par utilisateur, un client non authentifié peut supprimer irréversiblement toutes les données dans la corbeille pour tous les utilisateurs. Mitigation : exiger l'authentification ; scoper la purge à la corbeille de l'utilisateur authentifié ; exiger le rôle admin pour la purge globale.

---

### ATK-04 — Double suppression douce pour corrompre deleted_at ✅ SAFE

**Attaque** : L'attaquant envoie `DELETE /notes/1` deux fois, espérant que le second appel réinitialise `deleted_at` à un timestamp ultérieur.
**Résultat** : SAFE — La première suppression définit `deleted_at`. La seconde trouve `deleted_at IS NULL = false`, donc la recherche retourne 0 lignes → 404. Le timestamp n'est pas modifié.

---

### ATK-05 — Restaurer une note active (état corrompu) 🚫 BLOCKED

**Attaque** : L'attaquant appelle `POST /notes/1/restore` sur une note active (non supprimée) pour forcer `deleted_at = null` inconditionnellement.
**Résultat** : BLOCKED — `restore` interroge `WHERE id = ? AND deleted_at IS NOT NULL`. Les notes actives ne correspondent pas → 404. Idempotent : restaurer une note déjà active est un no-op 404.

---

### ATK-06 — Injection SQL via le titre à la création ✅ SAFE

**Attaque** : L'attaquant soumet `{"title": "'; DROP TABLE notes; --"}` pour corrompre la base de données.
**Résultat** : SAFE — Toutes les écritures utilisent des requêtes paramétrées. Le titre est stocké comme chaîne littérale.

---

### ATK-07 — Débordement d'ID de note pour contourner la validation 🚫 BLOCKED

**Attaque** : L'attaquant envoie `GET /notes/99999999999999999999` (20 chiffres) pour déborder l'entier PHP et atteindre des IDs non prévus.
**Résultat** : BLOCKED — Les IDs de note sont validés avec `ctype_digit` + `strlen <= 18` avant conversion. Les valeurs de débordement → 422.

---

### ATK-08 — Mettre à jour une note supprimée (écrire sur un fantôme) 🚫 BLOCKED

**Attaque** : L'attaquant détient une référence de session périmée vers une note supprimée et soumet un PUT pour la modifier.
**Résultat** : BLOCKED — `PUT /notes/{id}` interroge `WHERE id = ? AND deleted_at IS NULL`. Les notes supprimées doucement échouent cette vérification → 404. La mise à jour est rejetée.

---

### ATK-09 — Course : restaurer puis purger immédiatement 🚫 BLOCKED

**Attaque** : L'attaquant met en concurrence `POST /notes/1/restore` et `POST /notes/trash/purge` pour détruire une note en cours de restauration.
**Résultat** : BLOCKED — Chaque opération est une transaction DB atomique unique. La purge émet `DELETE WHERE deleted_at IS NOT NULL` ; la restauration définit `deleted_at = NULL`. L'une gagne et la note se retrouve dans un état cohérent.

---

### ATK-10 — Suppression douce concurrente laisse un orphelin ✅ SAFE

**Attaque** : Deux requêtes appellent simultanément `DELETE /notes/1`. Les deux vérifient `deleted_at IS NULL`, voient toutes deux null, et tentent toutes deux de définir `deleted_at`.
**Résultat** : SAFE — La première mise à jour réussit. La seconde trouve `deleted_at IS NOT NULL` (ou 0 lignes mises à jour) → 404. SQLite sérialise les écritures ; le second appel est idempotent au niveau DB.

---

### ATK-11 — Titre trop long (abus de stockage) ⚠️ EXPOSED

**Attaque** : L'attaquant soumet une chaîne de titre de 10 Mo pour épuiser le stockage de la base de données.
**Résultat** : EXPOSED — Aucune longueur maximale n'est imposée sur `title` ou `body`. Mitigation : ajouter `MAX_TITLE_LENGTH` (ex. 500 chars) et `MAX_BODY_LENGTH` (ex. 100 000 chars), retournant 422 si dépassé. Le middleware de taille de requête fournit une garde secondaire.

---

### ATK-12 — Débordement d'épingles (inondation de notes épinglées) ⚠️ EXPOSED

**Attaque** : L'attaquant crée des milliers de notes épinglées pour pousser toutes les vraies notes hors du sommet de la liste active.
**Résultat** : EXPOSED — Pas de limite sur le nombre de notes épinglées. Toute note peut être créée avec `is_pinned: true`. Mitigation : limiter le nombre maximal de notes épinglées par utilisateur (ex. 10) ; retourner 422 si dépassé.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Suppression physique sans suppression douce | 🚫 BLOCKED |
| ATK-02 | Accès à une note supprimée doucement via GET | ✅ SAFE |
| ATK-03 | Purge de la corbeille sans auth | ⚠️ EXPOSED |
| ATK-04 | Double suppression douce | ✅ SAFE |
| ATK-05 | Restaurer une note active | 🚫 BLOCKED |
| ATK-06 | Injection SQL via le titre | ✅ SAFE |
| ATK-07 | Débordement de l'ID de note | 🚫 BLOCKED |
| ATK-08 | Mettre à jour une note supprimée doucement | 🚫 BLOCKED |
| ATK-09 | Course : restaurer + purger | 🚫 BLOCKED |
| ATK-10 | Suppression douce concurrente | ✅ SAFE |
| ATK-11 | Titre trop long | ⚠️ EXPOSED |
| ATK-12 | Inondation d'épingles | ⚠️ EXPOSED |

**7 BLOCKED, 2 SAFE, 3 EXPOSED** — Critique : authentifier la purge et la scoper aux données de l'acteur ; ajouter des limites de longueur pour le titre/body ; limiter le nombre de notes épinglées par utilisateur.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Suppression physique au premier DELETE | Pas de chemin de récupération ; la suppression accidentelle est permanente |
| Pas de filtre `deleted_at IS NULL` dans les requêtes liste/get | Les éléments supprimés doucement réapparaissent comme encore actifs |
| Autoriser `PUT` sur des notes supprimées doucement | Écritures fantômes — utilisateurs éditant des données qu'ils pensaient supprimées |
| Pas d'auth sur `POST /trash/purge` | Tout client détruit irréversiblement toutes les données dans la corbeille |
| Retourner 403 pour GET d'une note supprimée doucement | Révèle que la note existe ; 404 prévient l'énumération d'existence |
| Pas de vérification de nombre de lignes après la suppression douce | 200 silencieux quand la note n'est pas trouvée ; toujours vérifier les lignes affectées |
