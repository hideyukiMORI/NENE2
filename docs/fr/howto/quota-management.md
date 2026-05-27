# How-to : API de gestion des quotas

> **Référence FT** : FT236 (`NENE2-FT/quotalog`) — API de gestion des quotas
> **ATK** : FT236 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Démontre une API de gestion des quotas où chaque paire utilisateur/ressource possède une politique de taux configurable (horaire ou journalière), l'utilisation est suivie dans une table séparée clé par le début de la fenêtre, et un endpoint `consume` applique la limite avec `429 Too Many Requests` en cas de dépassement. `check` (lecture seule) et `consume` (mutation) sont des opérations séparées.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `PUT` | `/quotas/{userId}/{resource}` | Créer ou mettre à jour une politique de quota |
| `GET` | `/quotas/{userId}` | Lister toutes les politiques de quota d'un utilisateur |
| `GET` | `/quotas/{userId}/{resource}` | Vérifier le statut de quota actuel (lecture seule) |
| `POST` | `/quotas/{userId}/{resource}/consume` | Consommer une unité (retourne 429 si dépassé) |
| `POST` | `/quotas/{userId}/{resource}/reset` | Réinitialiser l'utilisation à zéro pour la fenêtre actuelle |

---

## QuotaWindow : calculer le début de la fenêtre

`QuotaWindow` est un backed enum avec une méthode `windowStart()` qui arrondit le timestamp actuel à la limite de la fenêtre :

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` arrondit à l'heure courante ; `setTime(0, 0, 0)` arrondit à minuit UTC. Le résultat est stocké comme clé `window_start` dans la table d'utilisation — toutes les requêtes dans la même fenêtre partagent la même valeur `window_start`.

---

## Conception à deux tables : politiques et utilisation

```sql
-- Politique de quota : maximum autorisé par fenêtre
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- Suivi de l'utilisation : nombre réel par fenêtre
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

Séparer les politiques de l'utilisation signifie :
- Les politiques persistent entre les fenêtres — pas besoin de les recréer à chaque période.
- Les lignes d'utilisation sont automatiquement partitionnées par `window_start`. Les anciennes fenêtres s'accumulent dans la table ; un job en arrière-plan peut les élaguer.
- `UNIQUE(user_id, resource)` sur les politiques prévient les configurations dupliquées.
- `UNIQUE(user_id, resource, window_start)` sur l'utilisation assure un compteur par fenêtre.

---

## check vs consume

`check` est en lecture seule — il calcule le reste sans aucune mutation :

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` vérifie la limite en premier, et n'incrémente que si autorisé :

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // Quota dépassé — retourne le statut avec allowed=false, n'incrémente PAS
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

Le contrôleur mappe `allowed=false` vers `429 Too Many Requests` :

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` est sémantiquement correct pour l'épuisement du quota. Inclure un en-tête `Retry-After` en production pointant vers l'heure de réinitialisation de la fenêtre.

---

## Incrément d'utilisation : SELECT-then-INSERT/UPDATE

L'incrément d'utilisation est un upsert au niveau applicatif :

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` est un incrément atomique au niveau DB — pas de lecture-modification-écriture dans le code applicatif. La contrainte `UNIQUE` sur `(user_id, resource, window_start)` prévient une condition de course entre deux insertions concurrentes de première utilisation.

---

## Upsert de politique via `PUT`

`PUT /quotas/{userId}/{resource}` est idempotent — il crée ou met à jour :

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

La vérification stricte `is_int()` rejette les floats et les chaînes JSON. `limitCount < 1` requiert au moins 1 — les valeurs zéro et négatives sont rejetées.

---

## ATK — Test d'attaque mentalité cracker (FT236)

### ATK-01 — Pas d'authentification

**Attaque** : Créer une politique de quota ou consommer au nom de n'importe quel utilisateur sans credentials.

```bash
curl -s -X PUT http://localhost:8080/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**Observé** : `200 OK` — pas de token requis. N'importe qui peut définir ou épuiser le quota de n'importe quel utilisateur.

**Verdict** : **EXPOSED** (par conception pour la démo FT236). Ajouter une authentification ; sécuriser la gestion des politiques derrière un rôle admin, et `consume` derrière le token de l'utilisateur propriétaire.

---

### ATK-02 — Injection SQL via le paramètre de chemin `{resource}`

**Attaque** : Intégrer des métacaractères SQL dans le nom de ressource.

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**Observé** : La chaîne resource est passée directement comme valeur paramétrée `?` dans toutes les requêtes — pas d'interpolation de chaîne. Le SQL injecté est stocké/comparé comme chaîne littérale, pas exécuté.

**Verdict** : **BLOCKED** — les requêtes paramétrées préviennent l'injection via les paramètres de chemin.

---

### ATK-03 — `limit_count` négatif ou zéro

**Attaque** : Définir une limite de 0 ou -1 pour désactiver l'accès d'un autre utilisateur.

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**Observé** : La vérification `$limitCount < 1` se déclenche → `422 Unprocessable Entity` avec une erreur structurée pour `limit_count`.

**Verdict** : **BLOCKED** — `limit_count` minimum de 1 appliqué au niveau applicatif.

---

### ATK-04 — Valeur `window` invalide

**Attaque** : Envoyer une chaîne de fenêtre non supportée.

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**Observé** : `QuotaWindow::tryFrom('weekly')` retourne `null` → `422` avec erreur structurée pour `window`.

**Verdict** : **BLOCKED** — le backed enum `tryFrom()` rejette les valeurs de fenêtre inconnues.

---

### ATK-05 — Consommer sans politique

**Attaque** : Appeler `POST .../consume` pour un utilisateur/ressource sans politique configurée.

```bash
curl -s -X POST http://localhost:8080/quotas/user-ghost/api-calls/consume
```

**Observé** : `findPolicy()` retourne `null` → `404 Not Found` avec une réponse Problem Details.

**Verdict** : **BLOCKED** — pas de politique → pas de consommation. L'appelant doit configurer une politique avant de consommer.

---

### ATK-06 — `limit_count` float

**Attaque** : Envoyer un float au lieu d'un entier.

```json
{"window": "daily", "limit_count": 9.9}
```

**Observé** : `is_int(9.9)` = `false` en PHP — la valeur décodée comme float depuis JSON (`float` type) échoue la vérification. `$limitCount` vaut par défaut `-1` → la garde `< 1` se déclenche → `422`.

**Verdict** : **BLOCKED** — la vérification de type stricte `is_int()` rejette les floats JSON.

---

### ATK-07 — `limit_count` extrêmement large

**Attaque** : Définir un limit_count à `PHP_INT_MAX` ou `9999999999`.

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**Observé** : `is_int()` passe (PHP représente ceci comme un `int`) ; la vérification `< 1` passe. La valeur est stockée et utilisée dans les comparaisons sans problème. Aucune limite supérieure n'existe.

**Verdict** : **EXPOSED** — aucun maximum `limit_count` appliqué. Une limite très grande est effectivement équivalente à "pas de limite". Ajouter :
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — Condition de course sur consume concurrent à la limite

**Attaque** : Envoyer deux requêtes `POST .../consume` simultanées quand `usage == limit - 1`.

**Observé** : Les deux requêtes lisent `usage = limit - 1` avant qu'un incrément ne s'exécute. Les deux voient `usage < limitCount` → les deux appellent `incrementUsage()`. Les deux réussissent — l'utilisation se termine à `limit + 1`, les deux réponses retournent `allowed: true`.

**Verdict** : **EXPOSED** — le pattern check-then-increment n'est pas atomique. Corriger avec une transaction :
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- vérifier < limit
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
Ou utiliser `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage` sur PostgreSQL.

---

### ATK-09 — Nom `{resource}` inconnu ou arbitraire

**Attaque** : Utiliser un nom de ressource jamais prévu.

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**Observé** : La traversée de chemin (`../`) est décodée par URL avant le routage ; le routeur les voit comme des chemins multi-segments et ne correspond pas à la route `{resource}`. Les caractères spéciaux sont stockés comme chaînes littérales via des requêtes paramétrées (voir ATK-02).

**Verdict** : **BLOCKED** en pratique — le routeur rejette la traversée de chemin, SQL est sûr. Envisager d'ajouter une allowlist de noms de ressources ou une vérification de format si les noms de ressources doivent être restreints à des valeurs connues.

---

### ATK-10 — Réinitialiser le quota d'un autre utilisateur

**Attaque** : Réinitialiser le compteur de quota d'un utilisateur différent pour contourner leur limitation.

```bash
curl -s -X POST http://localhost:8080/quotas/target-user/api-calls/reset
```

**Observé** : `200 OK` — pas de vérification de propriété. N'importe quel appelant peut réinitialiser l'utilisation du quota de n'importe quel utilisateur, re-activant immédiatement leur accès.

**Verdict** : **EXPOSED** — même cause racine que ATK-01. Sécuriser `reset` derrière un rôle admin.

---

### ATK-11 — Longueur illimitée de `{userId}` et `{resource}`

**Attaque** : Envoyer des valeurs de segments de chemin extrêmement longues.

```
PUT /quotas/<10000 chars>/<5000 chars>
```

**Observé** : Les chaînes longues sont acceptées et stockées dans des colonnes `TEXT` sans limite. Les performances d'index sur des clés très longues se dégradent.

**Verdict** : **EXPOSED** — ajouter une garde de longueur :
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — Manipulation de `window_start` via dérive d'horloge

**Attaque** : Si l'appelant peut influencer `$now`, il peut déplacer le début de la fenêtre pour étendre ou redémarrer artificiellement une fenêtre.

**Observé** : `$now` est calculé à l'intérieur du contrôleur via `new \DateTimeImmutable()` — il n'est pas fourni par l'utilisateur. L'appelant ne peut pas influencer le calcul de la fenêtre.

**Verdict** : **BLOCKED** — l'horloge serveur est la seule source de temps. Pour les systèmes distribués avec plusieurs nœuds, s'assurer que tous les nœuds utilisent UTC et sont synchronisés NTP.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Pas d'authentification | EXPOSED |
| ATK-02 | Injection SQL via paramètre de chemin resource | BLOCKED |
| ATK-03 | limit_count négatif/zéro | BLOCKED |
| ATK-04 | Valeur window invalide | BLOCKED |
| ATK-05 | Consommer sans politique | BLOCKED |
| ATK-06 | limit_count float | BLOCKED |
| ATK-07 | limit_count extrêmement large | EXPOSED |
| ATK-08 | Condition de course consume concurrent | EXPOSED |
| ATK-09 | Nom de ressource arbitraire | BLOCKED |
| ATK-10 | Réinitialiser le quota d'un autre utilisateur | EXPOSED |
| ATK-11 | Longueur illimitée userId/resource | EXPOSED |
| ATK-12 | Manipulation du début de fenêtre | BLOCKED |

**Vulnérabilités réelles à corriger avant la production** :
1. **ATK-01 / ATK-10** — Ajouter authentification et autorisation
2. **ATK-08** — Envelopper consume dans une transaction (check-then-increment atomique)
3. **ATK-07** — Ajouter une limite supérieure sur `limit_count`
4. **ATK-11** — Ajouter des limites de longueur sur les valeurs des paramètres de chemin

---

## Howtos connexes

- [`rate-limiting.md`](rate-limiting.md) — limitation de débit au niveau middleware
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — compteur de fenêtre glissante
- [`api-usage-metering.md`](api-usage-metering.md) — suivi d'utilisation par clé API
- [`credit-ledger.md`](credit-ledger.md) — modèle crédit/débit pour les systèmes de type quota
