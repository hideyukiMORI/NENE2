# How-to : API de fil d'actualité / timeline

> **Référence FT** : FT277 (`NENE2-FT/feedlog`) — Fil d'actualité : événements avec allowlist de types (9 types), payload JSON par événement, fil scopé par utilisateur avec IDOR → 404, limitation de pagination (max 100), admin fail-closed, 24 tests / 37 assertions PASS.
>
> Également validé dans FT219 (`NENE2-FT/feedlog` précurseur) — évaluation VULN sur le même pattern.

Ce guide montre comment construire un système de fil d'actualité avec des événements typés, un scope utilisateur et une pagination en utilisant NENE2.

## Fonctionnalités

- Publier des événements d'activité typés (types strictement en allowlist)
- Stockage de payload JSON (métadonnées arbitraires par type d'événement)
- Fil scopé par utilisateur avec protection IDOR (retourne 404 pour les accès non autorisés)
- Filtrage par type d'événement via paramètre de requête
- Pagination par horodatage décroissant (les plus récents en premier)
- L'admin peut publier des événements au nom des utilisateurs

## Schéma

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/events` | Utilisateur | Publier un événement d'activité |
| `GET` | `/users/{userId}/feed` | Utilisateur (lui-même ou admin) | Obtenir le fil avec filtre de type optionnel |

## Allowlist de types d'événements (VULN-B)

L'allowlist stricte des types d'événements empêche l'injection d'événements arbitraires et l'assignation massive :

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## Stockage de payload

Les payloads sont stockés sous forme de chaînes JSON et décodés à la récupération :

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## Protection IDOR (VULN-C)

L'accès au fil retourne 404 (pas 403) lorsqu'un utilisateur non autorisé tente de consulter le fil d'un autre utilisateur :

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Pagination avec filtrage par type

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

Les types inconnus dans le paramètre `?type=` sont silencieusement ignorés (null = aucun filtre appliqué).

## Résultats de l'évaluation VULN (FT219)

- **VULN-B** : `in_array(..., strict: true)` empêche tout type d'événement non listé
- **VULN-C** : L'IDOR retourne 404 pour cacher l'existence du fil aux appelants non autorisés
- **VULN-D** : Admin fail-closed — une clé admin vide retourne toujours false
- **VULN-F** : `is_array($payload)` garantit que le payload est toujours un objet JSON, pas un scalaire
- **VULN-G** : `ctype_digit()` protège le paramètre de chemin `userId`
- **VULN-I** : `clampInt()` borne `limit` (1–100) et `offset` (0–MAX_INT)

## Patterns de sécurité

- **`ctype_digit()`** : Validation d'entier sûre contre les ReDoS pour les paramètres de chemin
- **`is_array()`** : Le payload doit être un objet JSON (array en PHP) — pas une chaîne, un nombre, null
- **Requêtes paramétrées** : Tout le SQL utilise des paramètres `:named` — pas de concaténation de chaîne
- **`in_array(..., true)`** : Comparaison stricte empêche le contournement par coercition de type

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Accepter une chaîne de type d'événement libre | Les types non contrôlés polluent le fil ; difficile de construire des requêtes spécifiques par type |
| Stocker le payload en TEXT sans validation JSON | `is_array($payload)` assure un objet JSON ; les scalaires/tableaux cassent les consommateurs en aval |
| Faire confiance au `limit` brut de la chaîne de requête | Pas de borne supérieure → scan de table complète sur les grands ensembles de données |
| Utiliser `in_array($type, TYPES)` sans `true` | Comparaison lâche ; `0 == 'post_created'` dans certaines versions de PHP |
| Retourner 403 pour l'accès au fil d'un mauvais utilisateur | Révèle l'existence de l'utilisateur ; utiliser 404 pour masquer l'énumération d'utilisateurs |
| Indexer uniquement sur `user_id` | L'absence de `id DESC` dans l'index composite cause un ORDER BY lent sur les grands fils |
