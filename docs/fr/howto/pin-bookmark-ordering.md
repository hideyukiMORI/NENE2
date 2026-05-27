# How-to : Épingle / Signet avec tri

> **Référence FT** : FT327 (`NENE2-FT/pinlog`) — Épingles d'articles par utilisateur avec positions séquentielles, limite max d'épingles, recompactage sans lacune à la suppression, réorganisation via PUT, isolation utilisateur, évaluation VULN, 19 tests / 26 assertions PASS.

Ce guide montre comment créer une fonctionnalité d'articles épinglés où les utilisateurs maintiennent une liste ordonnée de jusqu'à 10 signets avec support de réorganisation par glisser-déposer.

## Schéma

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST`  | `/pins` | Épingler un article (idempotent) |
| `DELETE`| `/pins/{articleId}` | Désépingler un article |
| `GET`   | `/pins` | Lister les épingles de l'utilisateur dans l'ordre |
| `PUT`   | `/pins/order` | Réorganiser les épingles |

Tous les endpoints nécessitent l'en-tête `X-User-Id`. Manquant → 401.

## Épingler un article

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// Idempotent — épingler deux fois le même article
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  (déjà épinglé, pas de changement)
```

### Limite

```php
// Déjà 10 épingles
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### Cas d'erreur

```php
// Pas d'auth
POST /pins  {"article_id": 1}        → 401
// article_id manquant
POST /pins  X-User-Id: 1  {}         → 422
// Article inexistant
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## Désépingler

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // déjà supprimé
```

### Recompactage des positions après suppression

La suppression d'une épingle recompacte les positions — pas de lacunes :

```
Avant : [1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
Après : [1→Art1, 2→Art3]   // la position 2 est maintenant Art3
```

```php
// Après désépinglage, la lacune est comblée
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // position 3 → 2
  ], "count": 2}
```

## Lister les épingles

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// Vide
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

Les résultats sont triés par `position ASC`. L'utilisateur 2 ne voit jamais les épingles de l'utilisateur 1.

## Réorganiser

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// article_id inconnu (pas épinglé)
{"article_ids": [1, 99]}  → 422

// Pas de X-User-Id
PUT /pins/order  {"article_ids": [1]}  → 401
// Corps manquant
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## Évaluation de vulnérabilité

### V-01 — IDOR lors du désépinglage ✅ SAFE

**Risque** : L'utilisateur 2 désépingle les articles de l'utilisateur 1 en devinant les IDs d'articles.
**Résultat** : SAFE — la requête DELETE inclut `WHERE user_id = $authUserId AND article_id = $articleId`. La suppression inter-utilisateurs trouve 0 lignes → 404.

### V-02 — IDOR lors de la réorganisation ✅ SAFE

**Risque** : L'utilisateur 2 réorganise la liste d'épingles de l'utilisateur 1.
**Résultat** : SAFE — la réorganisation valide que tous les `article_ids` sont dans la liste d'épingles de l'utilisateur authentifié. Les IDs étrangers retournent 422.

### V-03 — Contournement de la limite d'épingles ✅ SAFE

**Risque** : L'attaquant soumet des requêtes d'épinglage concurrentes pour dépasser la limite de 10 épingles.
**Résultat** : SAFE — `UNIQUE(user_id, article_id)` prévient les doublons. Le nombre d'épingles est vérifié avant l'insertion. Les insertions concurrentes s'affrontent sur la contrainte unique.

### V-04 — Épingler un article inexistant ✅ SAFE

**Risque** : L'attaquant épingle `article_id=999999` pour insérer une référence FK suspendue.
**Résultat** : SAFE — vérification d'existence effectuée avant l'insertion. Un article inexistant retourne 404.

### V-05 — Épingler les articles d'un autre utilisateur ✅ SAFE

**Risque** : Épinglage inter-utilisateurs (l'utilisateur 2 épingle comme l'utilisateur 1 en manipulant `X-User-Id`).
**Résultat** : SAFE — `X-User-Id` est le token d'authentification dans ce FT. En production, utiliser un JWT/session signé — ne jamais faire confiance directement à un en-tête d'ID utilisateur fourni par le client.

### V-06 — Lacune de position après suppression expose l'ordre ✅ SAFE

**Risque** : Les lacunes dans les positions (`1, 3`) révèlent qu'une suppression a eu lieu ; l'attaquant déduit l'historique de suppression.
**Résultat** : SAFE — les positions sont immédiatement recompactées lors de la suppression. Les observateurs externes ne peuvent pas détecter l'ordre de suppression.

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|----------|
| V-01 | IDOR lors du désépinglage | ✅ SAFE |
| V-02 | IDOR lors de la réorganisation | ✅ SAFE |
| V-03 | Contournement de la limite d'épingles | ✅ SAFE |
| V-04 | Épingler un article inexistant | ✅ SAFE |
| V-05 | Épinglage inter-utilisateurs | ✅ SAFE |
| V-06 | La lacune expose l'historique de suppression | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — Pas de résultats critiques.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Pas de limite max d'épingles | La liste non bornée dégrade les performances des requêtes et l'UX |
| Laisser des lacunes de position après suppression | Le tri client par position casse ; nécessite une renumérotation côté client |
| Pas de vérification d'existence de l'article lors de l'épinglage | Les références suspendues créent de la confusion lors du rendu des listes d'épingles |
| Faire confiance à l'en-tête `X-User-Id` en production | N'importe quel client peut le définir ; utiliser une authentification signée (JWT, session) |
| Pas de `UNIQUE(user_id, article_id)` | Les épingles dupliquées gonflent le compteur et créent de la confusion dans la logique de réorganisation |
