# How-to : API de drapeaux de fonctionnalités

> **Référence FT** : FT313 (`NENE2-FT/flaglog`) — Gestion des drapeaux de fonctionnalités : drapeaux par environnement, déploiement progressif avec rollout_percent, remplacements par utilisateur, endpoint d'évaluation avec résolution des remplacements, validation des clés en snake_case, 18 tests / 29 assertions PASS.

Ce guide montre comment construire un système de drapeaux de fonctionnalités supportant la configuration par environnement, les déploiements progressifs par pourcentage, et les remplacements par utilisateur.

## Schéma

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` doit correspondre à `^[a-z][a-z0-9_]*$` (snake_case). `rollout_percent` est de 0 à 100.

## Endpoints

| Méthode    | Chemin                                   | Description                                          |
|------------|------------------------------------------|------------------------------------------------------|
| `PUT`      | `/flags/{key}`                           | Créer ou mettre à jour un drapeau                    |
| `GET`      | `/flags`                                 | Lister tous les drapeaux (optionnel `?environment=`) |
| `GET`      | `/flags/{key}/evaluate`                  | Évaluer un drapeau pour un utilisateur (`?user_id=`) |
| `PUT`      | `/flags/{key}/overrides/{userId}`        | Définir un remplacement par utilisateur              |
| `DELETE`   | `/flags/{key}/overrides/{userId}`        | Supprimer un remplacement par utilisateur            |

## Upsert de drapeau — PUT /flags/{key}

```php
// Corps de la requête
{
    "enabled": true,
    "rollout_percent": 50,   // optionnel, défaut 100
    "environment": "staging" // optionnel, défaut "production"
}

// Réponse 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

Le même endpoint crée ou met à jour (UPSERT par `key + environment`). Envoyer `PUT` deux fois avec des valeurs différentes met à jour le drapeau.

## Validation des clés

```php
// Clés valides (snake_case : a-z, 0-9, underscore, commence par une lettre)
dark_mode, beta_ui, new_feature_v2

// Invalide — retourne 422
Dark-Mode   // majuscules + trait d'union
123flag     // commence par un chiffre
my flag     // espace
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## Validation du pourcentage de déploiement

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## Drapeaux par environnement

```php
// Même clé, environnements différents
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// Lister par environnement
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## Évaluation — Déploiement + Remplacement

```
GET /flags/{key}/evaluate?user_id={userId}
```

Ordre de résolution :
1. **Le remplacement gagne** : si une ligne `flag_overrides` existe pour `(key, environment, user_id)` → utiliser la valeur du remplacement
2. **Drapeau désactivé** : si `enabled = false` → retourner `false` indépendamment du déploiement
3. **Vérification du déploiement** : hacher `user_id` de manière déterministe → comparer à `rollout_percent`

```php
// 1. Vérifier le remplacement
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. Drapeau désactivé
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. Pourcentage de déploiement
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

Réponse :
```json
{"enabled": true, "override": null}   // décision de déploiement
{"enabled": true, "override": true}   // remplacement activé
{"enabled": false, "override": false} // remplacement désactivé
```

## Remplacements par utilisateur

```php
// Activer pour un utilisateur spécifique (même si le drapeau est désactivé / déploiement 0%)
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// Désactiver pour un utilisateur spécifique (même si le drapeau est activé / déploiement 100%)
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// Supprimer le remplacement — revient à la logique de drapeau global + déploiement
DELETE /flags/my_flag/overrides/alice
```

Le remplacement nécessite le champ `enabled` (booléen). Champ manquant → 422.
Remplacement sur un drapeau inexistant → 404.
Supprimer un remplacement inexistant → 404.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Autoriser un format de clé arbitraire (ex. traits d'union, majuscules) | Clés incohérentes entre équipes ; difficile à grep/référencer dans le code |
| Pourcentage de déploiement > 100 | Erreur logique ; un déploiement à 110% signifie toujours activé même quand prévu comme progressif |
| Pas de séparation d'environnement | Les drapeaux staging se répercutent en production ; les déploiements canary se cassent |
| Évaluer sans vérification de `user_id` | `crc32(null)` ou chaîne vide donne un bucketing déterministe mais incorrect |
| Retourner 200 pour l'évaluation d'un drapeau manquant | L'appelant suppose que le drapeau existe ; traite silencieusement comme désactivé plutôt que de déclencher une alerte |
| État global des drapeaux en mémoire/cache sans TTL | Drapeaux périmés après changement du pourcentage de déploiement ; les changements ne se propagent pas |
