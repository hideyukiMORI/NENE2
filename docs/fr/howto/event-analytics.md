# How-to : API d'analytique d'événements

> **Référence FT** : FT51 (`NENE2-FT/statslog`) — API d'analytique d'événements avec filtrage de propriétés JSON et requêtes d'agrégation

Démontre une API de suivi d'événements qui stocke des événements analytiques avec des propriétés JSON arbitraires et expose des endpoints d'agrégation pour les compteurs par jour, les ventilations par type, et les métriques d'utilisateurs uniques. Patterns clés : filtrage de propriétés `json_extract()`, regroupement de dates par `strftime()`, routes statiques avant routes paramétrisées, et IDs utilisateur de type chaîne.

---

## Routes

| Méthode | Chemin                      | Description                                         |
|---------|-----------------------------|-----------------------------------------------------|
| `POST`  | `/events`                   | Enregistrer un événement                            |
| `GET`   | `/events`                   | Lister les événements (paginé)                      |
| `GET`   | `/events/by-property`       | Filtrer par clé/valeur de propriété JSON            |
| `GET`   | `/events/{id}`              | Obtenir un seul événement                           |
| `GET`   | `/stats/per-day`            | Nombre d'événements par jour calendaire (`?from=&to=`) |
| `GET`   | `/stats/per-type`           | Nombre d'événements par type (`?from=&to=`)         |
| `GET`   | `/stats/unique-users`       | Nombre d'utilisateurs uniques par jour (`?from=&to=`) |

---

## Enregistrement d'événements

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // requis, chaîne non vide
    'user_id'     => 'usr_abc123',          // requis, chaîne (UUID ou ID opaque)
    'session_id'  => 'sess_xyz789',         // optionnel
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // objet optionnel
    'occurred_at' => '2026-05-27T09:00:00Z', // optionnel, ISO 8601 (défaut au temps serveur)
];
```

`properties` est stocké comme une chaîne JSON. En sortie, il est décodé en retour en objet :

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

Quand `occurred_at` est omis, le serveur le remplit avec l'heure UTC actuelle :

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## Ordre des routes : statiques avant paramétrisées

Le routeur correspond les routes dans l'ordre d'enregistrement. Un chemin statique comme `/events/by-property` doit être enregistré **avant** le paramétrisé `/events/{id}`, sinon le segment `by-property` serait capturé comme `{id}` :

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ Route statique en premier — sinon "by-property" est avalé par {id}
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**Règle** : toujours enregistrer les segments de chemin concrets avant les segments wildcard au même niveau de profondeur.

---

## Filtrage de propriétés JSON avec `json_extract()`

SQLite (≥ 3.38) et MySQL supportent `json_extract()` pour interroger dans les colonnes JSON stockées. La clé est passée comme expression JSONPath paramétrisée :

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

Le préfixe JSONPath `$.` est ajouté en PHP, donc `key = "path"` devient `json_extract(properties, '$.path')`. Comme les deux arguments sont paramétrisés, il n'y a pas de risque d'injection SQL même si `$propertyKey` contient des caractères spéciaux.

> **Limite de profondeur** : `$.path` accède au niveau supérieur. Pour l'accès imbriqué (`$.browser.name`), l'appelant passe `browser.name` comme clé. Les chemins profonds peuvent être surprenants — documenter les formes de clé supportées dans votre spec OpenAPI.

---

## Agrégation de dates avec `strftime()`

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` tronque une chaîne datetime ISO 8601 à sa composante date. Cela fonctionne dans SQLite quand `occurred_at` est stocké en UTC (ex. `2026-05-27T09:00:00Z`). Les heures stockées avec des offsets non-UTC seront regroupées par leur chaîne brute, pas converties en heure locale — normaliser en UTC à l'écriture si la sémantique de limite de journée est importante.

---

## Comptage des utilisateurs uniques par jour

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` retourne le nombre de valeurs `user_id` distinctes qui apparaissent dans chaque tranche. C'est une approximation des Utilisateurs Actifs Quotidiens (UAQ) quand `user_id` est un identifiant externe stable (UUID, ID d'appareil haché, etc.).

---

## user_id de type chaîne

`user_id` est stocké comme `TEXT NOT NULL`, pas comme clé étrangère entière. Cette conception accommode :

- UUID (`usr_01HQ...`)
- Identifiants de chaîne opaques d'un fournisseur d'identité
- Tokens de session anonymes avant la création de compte

Comme le champ est du texte libre, la couche analytique ne se couple pas au modèle de données utilisateur. Il n'y a pas de clé étrangère `REFERENCES users(id)` — les événements peuvent être enregistrés avant ou après la création d'un compte utilisateur.

---

## Repli de plage de dates par défaut

Les endpoints d'agrégation acceptent les paramètres de requête `?from=` et `?to=`. Quand omis, les valeurs par défaut couvrent une très large plage :

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

C'est pratique pour l'usage de démonstration mais pourrait être coûteux sur un grand ensemble de données en production. En production, exiger des plages de dates explicites et plafonner la durée maximale.

---

## Schéma et index

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

Trois index couvrent les trois formes principales de requête :
- `idx_events_occurred` — agrégations de plage de dates (`WHERE occurred_at >= ? AND < ?`)
- `idx_events_type` — filtre de type (`WHERE event_type = ?`)
- `idx_events_user` — recherche d'historique utilisateur (`WHERE user_id = ?`)

Les requêtes `json_extract()` sur `properties` ne sont pas supportées par les index dans SQLite sans colonne générée. Pour le filtrage de propriétés à fort volume, envisager d'ajouter une colonne générée :

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## Encodage des propriétés en PHP

Le champ `properties` accepte n'importe quel objet JSON de l'appelant et le stocke comme chaîne :

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` rejette les scalaires et tableaux JSON (qui se décoderaient en tableau PHP mais ne sont pas un objet). Stocker avec `JSON_THROW_ON_ERROR` garantit que les échecs d'encodage remontent comme exceptions plutôt que `false` silencieux.

À la sérialisation, les propriétés sont décodées en tableau PHP et intégrées comme objet imbriqué dans la réponse :

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Guides associés

- [`admin-report-aggregation.md`](admin-report-aggregation.md) — patterns d'agrégation SQL pour les rapports admin
- [`shift-management.md`](shift-management.md) — plafonnement de plage de dates, requêtes d'agrégation
- [`pagination.md`](pagination.md) — `PaginationQueryParser` et `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — validation round-trip ISO 8601 pour `occurred_at`
