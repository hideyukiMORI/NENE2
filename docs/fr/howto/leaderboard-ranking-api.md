# How-to : API de classement (leaderboard)

> **Référence FT** : FT332 (`NENE2-FT/ranklog`) — Leaderboard avec suivi du meilleur score personnel par utilisateur, classement descendant, consultation de son propre rang, suppression de score, et évaluation d'attaque cracker-mindset ATK, 19 tests / 50+ assertions PASS.

Ce guide montre comment construire un système de classement multi-leaderboard qui stocke seulement le meilleur score personnel par utilisateur, retourne les positions de rang et permet la suppression de score en libre-service.

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- un meilleur score par utilisateur par board
);
```

`UNIQUE(leaderboard_id, user_id)` applique une entrée par utilisateur — les nouvelles soumissions écrasent seulement quand le score est plus élevé.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/leaderboards` | Créer un leaderboard |
| `POST` | `/leaderboards/{id}/scores` | Soumettre un score |
| `GET`  | `/leaderboards/{id}/rankings` | Obtenir le classement complet (descendant) |
| `GET`  | `/leaderboards/{id}/rankings/me` | Obtenir son propre rang |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | Supprimer son propre score |

## Créer un leaderboard

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // name requis
```

## Soumettre un score — Meilleur score personnel seulement

```php
// Première soumission
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// Meilleur score
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// Score moins bon — la valeur stockée N'EST PAS mise à jour
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

Seul le meilleur score personnel est stocké. Une soumission inférieure est reconnue mais ignorée.

```php
// Les scores négatifs sont valides (pénalités, golf-scoring, etc.)
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// Erreurs
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // utilisateur inconnu

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // leaderboard inconnu

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // champ score manquant
```

## Obtenir le classement

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// Limiter au top N
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // top 2 seulement
```

Les classements sont triés par score décroissant. `rank` est indexé à partir de 1.

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## Obtenir son propre rang

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// Pas encore sur ce leaderboard
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// En-tête acteur manquant
GET /leaderboards/1/rankings/me
→ 400
```

L'en-tête `X-User-Id` identifie l'utilisateur demandant. En-tête manquant ou invalide → 400.

## Supprimer un score

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  (pas de corps)

// Déjà supprimé / jamais soumis
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

Après suppression, `GET /rankings/me` pour cet utilisateur retourne 404.

---

## ATK Assessment — Test d'attaque mentalité cracker

### ATK-01 — Soumettre un score pour un autre utilisateur (IDOR corps) ⚠️ EXPOSED

**Attaque** : L'attaquant envoie `{"user_id": 2, "score": 999999}` pour pousser un autre utilisateur en tête du classement.
**Résultat** : EXPOSED — L'endpoint utilise `user_id` du corps de requête sans vérifier que l'acteur correspond. Une vérification d'autorisation (`X-User-Id == body.user_id`) prévient cela. Pour les leaderboards compétitifs, dériver `user_id` depuis `X-User-Id` et ignorer entièrement le champ corps.

---

### ATK-02 — Supprimer le score d'un autre utilisateur (IDOR sur DELETE) ✅ SAFE

**Attaque** : L'attaquant envoie `DELETE /leaderboards/1/scores/2` avec `X-User-Id: 1` pour effacer le score d'un autre utilisateur.
**Résultat** : SAFE — `DELETE /scores/{userId}` scope la recherche à l'acteur authentifié. Le `userId` de chemin est comparé à `X-User-Id` ; une non-correspondance retourne 404. Seuls les rôles admin devraient pouvoir supprimer des scores d'utilisateurs arbitraires.

---

### ATK-03 — Débordement d'entier de score 🚫 BLOCKED

**Attaque** : L'attaquant soumet `{"score": 9999999999999999999999}` pour déborder l'entier stocké.
**Résultat** : BLOCKED — Le parser JSON de PHP plafonne les grands nombres à `PHP_INT_MAX` (~9,2×10^18). La validation du type entier rejette les chaînes. Le stockage SQL `INTEGER` est 64 bits ; le débordement est infaisable en pratique.

---

### ATK-04 — Injection de score float 🚫 BLOCKED

**Attaque** : L'attaquant envoie `{"score": 999.9}` espérant qu'un float trie au-dessus des scores entiers.
**Résultat** : BLOCKED — Le score est validé comme entier strict. `999.9` est rejeté avec 422 Unprocessable Entity avant d'atteindre la DB.

---

### ATK-05 — Injection SQL via score 🚫 BLOCKED

**Attaque** : L'attaquant envoie `{"score": "100; DROP TABLE scores--"}` pour corrompre la base de données.
**Résultat** : BLOCKED — Le score doit d'abord passer la validation entière. Les requêtes paramétrées (placeholders `?`) préviennent l'injection au niveau DB même si une chaîne passait la validation.

---

### ATK-06 — Score négatif pour couler un autre utilisateur 🚫 BLOCKED

**Attaque** : L'attaquant soumet un score très négatif pour un autre utilisateur pour le pousser en bas.
**Résultat** : BLOCKED — La logique de meilleur score personnel remplace un score stocké seulement quand le nouveau score est **plus élevé**. Soumettre -999999 pour un utilisateur avec score 500 retourne `new_best: false` et le score stocké est inchangé. Combiné avec la mitigation ATK-01, l'injection de score est entièrement prévenue.

---

### ATK-07 — Injection de limite sur les classements 🚫 BLOCKED

**Attaque** : L'attaquant envoie `GET /rankings?limit=999999` pour extraire le leaderboard entier en une requête.
**Résultat** : BLOCKED — `limit` est validé avec `ctype_digit` et plafonné à `MAX_LIMIT` (ex: 100). Les requêtes dépassant le plafond → 422.

---

### ATK-08 — X-User-Id manquant sur les endpoints authentifiés 🚫 BLOCKED

**Attaque** : L'attaquant omet `X-User-Id` sur `GET /rankings/me` ou `DELETE` pour contourner la validation d'acteur.
**Résultat** : BLOCKED — Les deux endpoints retournent 400 quand `X-User-Id` est absent ou vide.

---

### ATK-09 — Injection de valeur d'en-tête X-User-Id non entière 🚫 BLOCKED

**Attaque** : L'attaquant envoie `X-User-Id: 1 OR 1=1` pour injecter du SQL via l'en-tête.
**Résultat** : BLOCKED — `X-User-Id` est validé avec `ctype_digit` ; tout caractère non-chiffre → 400. La valeur n'atteint jamais le SQL sans passer la validation entière.

---

### ATK-10 — Score vers un leaderboard inexistant 🚫 BLOCKED

**Attaque** : L'attaquant fabrique `leaderboard_id = 9999` espérant contourner les contrôles niveau leaderboard.
**Résultat** : BLOCKED — L'existence du leaderboard est vérifiée avant l'insertion de score. Leaderboard inconnu → 404.

---

### ATK-11 — Rejouer un score plus bas après suppression 🚫 BLOCKED

**Attaque** : L'attaquant supprime son score, puis resoumet une valeur gonflée pour réinitialiser la garde du meilleur score personnel.
**Résultat** : BLOCKED — Après suppression la ligne est supprimée ; la soumission suivante est une nouvelle entrée (`new_best: true`). C'est le comportement attendu. Si l'immuabilité historique est requise, utiliser le soft-delete (`deleted_at`) et conserver le précédent meilleur pour bloquer la re-soumission.

---

### ATK-12 — Soumissions de score concurrentes (condition de course) 🚫 BLOCKED

**Attaque** : Deux requêtes soumettent simultanément un score pour le même utilisateur avant que l'une ne se commit.
**Résultat** : BLOCKED — `UNIQUE(leaderboard_id, user_id)` et un `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` atomique assurent qu'un seul gagnant au niveau DB. SQLite sérialise les écritures ; MySQL/PostgreSQL utilisent le verrouillage au niveau ligne.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Soumettre un score pour un autre utilisateur (IDOR corps) | ⚠️ EXPOSED |
| ATK-02 | Supprimer le score d'un autre utilisateur | ✅ SAFE |
| ATK-03 | Débordement d'entier de score | 🚫 BLOCKED |
| ATK-04 | Injection de score float | 🚫 BLOCKED |
| ATK-05 | Injection SQL via score | 🚫 BLOCKED |
| ATK-06 | Score négatif pour couler un autre utilisateur | 🚫 BLOCKED |
| ATK-07 | Injection de limite sur les classements | 🚫 BLOCKED |
| ATK-08 | En-tête acteur manquant | 🚫 BLOCKED |
| ATK-09 | Injection de valeur X-User-Id non entière | 🚫 BLOCKED |
| ATK-10 | Score vers un leaderboard inexistant | 🚫 BLOCKED |
| ATK-11 | Rejouer un score après suppression | 🚫 BLOCKED |
| ATK-12 | Course de mise à jour de score concurrente | 🚫 BLOCKED |

**10 BLOCKED, 1 SAFE, 1 EXPOSED** — La soumission de score doit vérifier que l'acteur correspond à `user_id`. Dériver l'identité utilisateur depuis `X-User-Id` ; ne jamais accepter `user_id` du corps de requête.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Faire confiance à `user_id` du corps de requête sans vérification d'acteur | N'importe quel utilisateur peut soumettre des scores au nom d'autres |
| Stocker toutes les soumissions au lieu du meilleur score personnel seulement | La DB croît sans limite ; le classement devient ambigu |
| Autoriser les scores float | La comparaison float en SQL produit un ordre de tri inattendu |
| Pas de contrainte `UNIQUE(leaderboard_id, user_id)` | Les lignes dupliquées gonflent le rang apparent d'un utilisateur |
| Retourner 200 avec liste vide pour un leaderboard inconnu | Masque une mauvaise configuration ; 404 pour les ressources inconnues |
| Pas de plafond sur `/rankings?limit=` | Scan de table entière sur les grands leaderboards cause un DoS |
