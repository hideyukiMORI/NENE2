# How-to : API d'évaluation et d'avis

> **Référence FT** : FT333 (`NENE2-FT/ratinglog`) — Système d'évaluation par élément et par utilisateur avec validation du score (1–5), sémantique upsert, résumé avec distribution détaillée et évaluation de vulnérabilité, 16 tests / 40+ assertions PASS.

Ce guide montre comment construire un système d'évaluation où les utilisateurs soumettent des scores numériques avec des avis textuels optionnels, et l'API calcule des résumés agrégés en temps réel.

## Schéma

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` impose une évaluation par évaluateur par élément. `item_id` et `rater_id` sont des identifiants de chaîne opaques — aucune contrainte de clé étrangère requise.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `PUT` | `/items/{itemId}/ratings/{raterId}` | Créer ou mettre à jour une évaluation (upsert) |
| `GET` | `/items/{itemId}/ratings` | Lister toutes les évaluations d'un élément |
| `GET` | `/items/{itemId}/ratings/summary` | Résumé agrégé avec distribution |
| `GET` | `/items/{itemId}/ratings/{raterId}` | Obtenir l'évaluation d'un évaluateur |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | Supprimer une évaluation |

## Créer / Mettre à jour une évaluation (Upsert)

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// Mettre à jour une évaluation existante
PUT /items/product-1/ratings/alice
{"score": 3, "review": "J'ai changé d'avis."}
→ 200  {"score": 3}
```

`PUT` avec `UNIQUE(item_id, rater_id)` agit comme un upsert naturel (`INSERT OR REPLACE`). Le même endpoint gère création et mise à jour sans `PATCH` séparé.

### Validation

```php
// Score manquant
PUT /items/product-1/ratings/alice  {"review": "Bien"}
→ 422

// Hors plage
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

Le score doit être un entier dans [1, 5]. `review` est optionnel (vaut par défaut `""`).

## Lister les évaluations

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

Les évaluations sont scopées à l'élément — les évaluations de `product-2` n'apparaissent jamais dans la liste de `product-1`.

## Résumé avec distribution

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// Pas encore d'évaluations
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` retourne toujours les cinq clés même quand les comptes sont zéro — les clients peuvent afficher des barres d'étoiles sans vérifications null.

## Obtenir une évaluation individuelle

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## Supprimer une évaluation

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

Après suppression, le résumé se recalcule immédiatement à la prochaine requête.

```php
// Avant : alice(5) + bob(1), average=3.0
DELETE /items/product-1/ratings/bob

// Après : alice(5) uniquement
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## Évaluation de vulnérabilité

### V-01 — Usurpation d'évaluation (IDOR sur raterId) ⚠️ EXPOSED

**Risque** : N'importe quel client peut soumettre ou supprimer une évaluation en utilisant n'importe quel `raterId` de chemin.
**Résultat** : EXPOSED — `raterId` dans l'URL n'est pas validé contre un acteur authentifié. Un attaquant peut publier un avis 1 étoile en tant que `raterId: "concurrent"` ou supprimer l'avis d'un autre utilisateur. Atténuation : authentifier l'évaluateur (session, JWT ou en-tête `X-User-Id`) et rejeter les requêtes où l'identité authentifiée ne correspond pas au `raterId` du chemin.

---

### V-02 — Contournement de plage de score 🛡️ SAFE

**Risque** : L'attaquant soumet `score: 0` ou `score: 6` pour produire des données invalides ou biaiser les moyennes.
**Résultat** : SAFE — Le score est validé à `[1, 5]` avant tout écriture DB. Les valeurs hors plage retournent 422. Le `CHECK (score BETWEEN 1 AND 5)` au niveau DB fournit une garde secondaire.

---

### V-03 — Empoisonnement de moyenne via de fausses évaluations en masse ⚠️ EXPOSED

**Risque** : L'attaquant enregistre des milliers d'ID utilisateurs et soumet des évaluations 1 étoile pour faire chuter la moyenne d'un produit.
**Résultat** : EXPOSED — Pas de limitation de débit ni de vérification de compte appliquées à l'endpoint d'évaluation. Atténuation : exiger l'âge du compte / la vérification email avant d'évaluer ; appliquer des limites de débit par IP et par utilisateur ; détecter les anomalies statistiques (burst soudain de scores bas).

---

### V-04 — XSS via le texte de l'avis ✅ SAFE

**Risque** : L'attaquant stocke `<script>alert(1)</script>` dans `review` pour exécuter JavaScript sur les clients qui affichent l'avis en HTML.
**Résultat** : SAFE — L'API retourne `application/json`. L'encodage JSON échappe les caractères spéciaux HTML (`<`, `>`, `&`). Tant que les clients analysent et affichent la valeur JSON comme texte (pas `innerHTML`), le XSS stocké est prévenu. L'encodage HTML côté serveur comme couche supplémentaire est recommandé.

---

### V-05 — Injection SQL via itemId / raterId 🛡️ SAFE

**Risque** : L'attaquant envoie `item_id = "x' OR '1'='1"` ou `rater_id = "'; DROP TABLE ratings--"` pour manipuler la requête.
**Résultat** : SAFE — Toutes les requêtes utilisent des instructions paramétrées (placeholders `?`). Les segments de chemin sont passés comme valeurs bind, jamais interpolés dans les chaînes SQL.

---

### V-06 — Texte d'avis illimité (abus de stockage) ⚠️ EXPOSED

**Risque** : L'attaquant soumet une chaîne d'avis de 100 Mo pour épuiser les ressources base de données / mémoire.
**Résultat** : EXPOSED — Aucune vérification `max_length` n'est appliquée sur `review`. Atténuation : ajouter une constante `MAX_REVIEW_LENGTH` (ex. 2000 caractères) et retourner 422 si dépassé. Le middleware de taille de requête fournit une garde secondaire.

---

### V-07 — Troncature d'entier dans la moyenne du résumé 🛡️ SAFE

**Risque** : Faire la moyenne de 3 évaluations (5+3+4=12, 12/3=4.0) pourrait perdre de la précision sur certains moteurs DB.
**Résultat** : SAFE — `AVG()` dans SQLite retourne un float. PHP caste le résultat en `float` avant l'encodage. La troncature de style `(int)(5+3)/2` n'est pas utilisée.

---

### V-08 — Clés manquantes dans la distribution (crash client) 🛡️ SAFE

**Risque** : Si `distribution` omet les clés pour les scores avec zéro évaluations, les clients qui accèdent à `distribution[1]` plantent avec `undefined`.
**Résultat** : SAFE — L'API retourne toujours les cinq clés (`1`–`5`) initialisées à `0`. Les clients n'ont pas besoin de vérifications null défensives.

---

### V-09 — Fuite de données inter-éléments 🛡️ SAFE

**Risque** : `GET /items/product-1/ratings` retourne des évaluations de `product-2`.
**Résultat** : SAFE — Toutes les requêtes incluent `WHERE item_id = ?`. Le test d'isolation vérifie explicitement que l'évaluation de `product-2` n'apparaît pas dans la liste de `product-1`.

---

### V-10 — Score float pour contourner la validation d'entier 🛡️ SAFE

**Risque** : L'attaquant envoie `score: 4.9` (arrondi à 5) ou `score: 5.1` (arrondi à 5 ou 6) pour contourner la vérification de plage.
**Résultat** : SAFE — Le score est validé comme entier strict. Un float JSON échoue la validation de type et retourne 422 avant toute vérification de plage.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|----------|
| V-01 | Usurpation d'évaluation (IDOR sur raterId) | ⚠️ EXPOSED |
| V-02 | Contournement de plage de score | 🛡️ SAFE |
| V-03 | Empoisonnement de moyenne via de fausses évaluations en masse | ⚠️ EXPOSED |
| V-04 | XSS via le texte de l'avis | ✅ SAFE |
| V-05 | Injection SQL via itemId / raterId | 🛡️ SAFE |
| V-06 | Texte d'avis illimité (abus de stockage) | ⚠️ EXPOSED |
| V-07 | Troncature d'entier dans la moyenne du résumé | 🛡️ SAFE |
| V-08 | Clés manquantes dans la distribution | 🛡️ SAFE |
| V-09 | Fuite de données inter-éléments | 🛡️ SAFE |
| V-10 | Score float pour contourner la validation d'entier | 🛡️ SAFE |

**7 SAFE, 3 EXPOSED** — Critique : authentifier `raterId` ; ajouter un plafond de longueur pour `review` ; appliquer une limitation de débit contre les fausses évaluations en masse.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Faire confiance à `raterId` du chemin sans authentification | N'importe quel client peut évaluer ou supprimer en tant que n'importe quel utilisateur |
| Pas de `max_length` sur le texte de l'avis | Bombe de stockage — une seule requête écrit des gigaoctets dans la DB |
| Retourner `null` pour les clés de distribution avec un compte zéro | Le code client qui accède à `distribution[2]` plante |
| Recalculer la moyenne en PHP avec `array_sum` | Arithmétique float avec perte sur de grands ensembles ; laisser la DB faire `AVG()` |
| Pas de limite de débit par utilisateur | Les faux comptes en masse empoisonnent les moyennes de produits |
| Utiliser `SELECT * FROM ratings` sans `WHERE item_id` | Fuite de données inter-éléments |
