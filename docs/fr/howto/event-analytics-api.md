# How-to : API d'analytique d'événements

> **Référence FT** : FT243 (`NENE2-FT/statslog`) — API d'analytique d'événements
> **VULN** : FT243 — évaluation des vulnérabilités (V-01 à V-10)

Démontre une API d'ingestion et d'agrégation d'événements où les événements d'analytique bruts sont enregistrés avec un blob JSON `properties`, interrogés avec `json_extract()` de SQLite, et agrégés en statistiques par jour / par type / utilisateurs uniques. Inclut une évaluation complète des vulnérabilités de la conception non authentifiée.

---

## Routes

| Méthode | Chemin                   | Description                                              |
|---------|--------------------------|----------------------------------------------------------|
| `POST`  | `/events`                | Enregistrer un événement analytique                      |
| `GET`   | `/events`                | Lister les événements (paginé)                           |
| `GET`   | `/events/by-property`    | Filtrer les événements par clé+valeur de propriété JSON  |
| `GET`   | `/events/{id}`           | Obtenir un seul événement                                |
| `GET`   | `/stats/per-day`         | Nombre d'événements groupé par jour                      |
| `GET`   | `/stats/per-type`        | Nombre d'événements groupé par type                      |
| `GET`   | `/stats/unique-users`    | Nombre d'utilisateurs uniques groupé par jour            |

> **Routes statiques avant paramétrisées** : `/events/by-property` est enregistré avant `/events/{id}` pour que le routeur dispatche le chemin littéral correctement.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` est stocké comme une chaîne JSON (`TEXT`). Le `json_extract()` de SQLite permet d'interroger le blob à la lecture sans schéma séparé. Trois index couvrent les patterns d'accès les plus courants : par type, par plage de temps, et par utilisateur.

---

## Création d'événement : blob JSON de propriétés

`POST /events` accepte un objet `properties` flexible aux côtés des `event_type` et `user_id` requis :

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` doit être un objet JSON (vérification `is_array()`) — les valeurs scalaires reviennent à `'{}'`.
- `occurred_at` est fourni par l'appelant ou se défaut sur maintenant — pas d'imposition côté serveur qu'il tombe dans une plage valide.
- `JSON_THROW_ON_ERROR` garantit que le JSON intermédiaire malformé lève immédiatement plutôt que de produire `false`.

Désérialisation à la lecture :
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Recherche de propriété JSON avec `json_extract()`

`GET /events/by-property?key=page&value=/home` filtre les événements par clé/valeur de propriété :

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` extrait le champ `page` du blob JSON. Le chemin `'$.' . $propertyKey` est construit par concaténation, **pas** paramétrisé car le chemin lui-même — le `json_extract()` de SQLite accepte uniquement une chaîne de chemin littérale, pas un paramètre lié pour l'expression de chemin. La clé vient d'une chaîne de requête mais n'est pas validée davantage (voir V-05).

`= ?` compare la valeur extraite avec le `$propertyValue` fourni comme binding paramétrisé — l'injection SQL via la valeur est bloquée. La concaténation de chemin est la frontière à auditer.

---

## Requêtes d'agrégation

### Nombre d'événements par jour

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` tronque l'horodatage à une date. `GROUP BY` sur la même expression regroupe tous les événements du même jour. `$from` et `$to` sont tous les deux paramétrisés — pas de concaténation de chaîne dans le SQL.

### Nombre d'événements par type

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` montre les types d'événements les plus fréquents en premier.

### Utilisateurs uniques par jour

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` compte chaque `user_id` une seule fois par jour.

### Valeurs par défaut de plage de dates

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

Des valeurs par défaut larges (`2000-01-01` à `2100-01-01`) garantissent que les statistiques sans plage de dates incluent tous les événements. En production, plafonner la plage par défaut à une fenêtre raisonnable (ex. 30 derniers jours) pour éviter les scans complets de table sur de grands ensembles de données.

---

## VULN — Évaluation des vulnérabilités (FT243)

### V-01 — Pas d'authentification : n'importe qui peut enregistrer des événements

**Risque** : N'importe quel appelant peut soumettre des événements avec des `event_type` et `user_id` arbitraires. Il n'y a pas de clé API, session, ou vérification de token.

**Impact** : Un attaquant peut polluer l'ensemble de données d'analytique avec des millions de faux événements, biaiser les statistiques, et usurper n'importe quel ID utilisateur.

**Verdict** : **EXPOSÉ** — ajouter une authentification par clé API ou JWT pour l'endpoint d'écriture. Les statistiques en lecture seule peuvent rester publiques, mais l'ingestion doit être authentifiée.

---

### V-02 — Pas d'autorisation sur les statistiques : statistiques lisibles par tous

**Risque** : `GET /stats/per-day`, `/stats/per-type`, `/stats/unique-users` retournent des données agrégées sans aucune authentification.

**Impact** : Des concurrents ou des crawlers peuvent surveiller les tendances d'utilisation des produits, les utilisateurs actifs quotidiens, et l'adoption des fonctionnalités.

**Verdict** : **EXPOSÉ** — restreindre les endpoints de statistiques aux rôles authentifiés (admin, observateur analytique). Si les statistiques sont intentionnellement publiques, documenter cela comme décision de conception.

---

### V-03 — `user_id` fourni par l'utilisateur : pas de vérification d'identité

**Risque** : `user_id` est pris directement du corps de la requête sans aucune preuve que l'appelant possède cette identité.

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**Impact** : Un attaquant peut fabriquer de l'activité pour n'importe quel ID utilisateur, manipulant les statistiques par utilisateur et les compteurs d'utilisateurs uniques.

**Verdict** : **EXPOSÉ** — pour les contextes authentifiés, dériver `user_id` de l'identité vérifiée dans le token/session, jamais du corps de la requête.

---

### V-04 — `occurred_at` fourni par l'utilisateur : antidatage et post-datage d'événements

**Risque** : Le champ `occurred_at` est accepté de l'appelant sans validation de plage.

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**Impact** : Les attaquants peuvent insérer des événements dans n'importe quel créneau historique (antidater) ou loin dans le futur, distordant les statistiques temporelles.

**Verdict** : **EXPOSÉ** — valider que `occurred_at` tombe dans une fenêtre acceptable (ex. dernières 24 heures à +5 minutes) et rejeter les horodatages hors plage.

---

### V-05 — Concaténation de chemin `json_extract()` : injection de chemin JSON

**Risque** : La clé de propriété est concaténée directement dans l'expression de chemin JSON : `'$.' . $propertyKey`. Il n'y a pas de validation que `$propertyKey` est un identifiant sûr.

**Attaque** :
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
Devient : `json_extract(properties, '$.x"] OR 1=1 --')` — SQLite interprète l'argument de chemin comme une chaîne littérale passée à `json_extract`, pas comme SQL. Le chemin n'est pas exécuté comme SQL — il est géré par les fonctions JSON de SQLite comme une chaîne. Les chemins invalides retournent `NULL`, donc la requête ne retourne aucune ligne plutôt que toutes.

**Observé** : `json_extract()` traite tout le deuxième argument comme une expression de chemin. Les chemins malformés (`$.x"] OR 1=1 --`) retournent `NULL` pour chaque ligne — pas d'injection SQL. Cependant, le comportement dépend de l'implémentation JSON de SQLite — une approche de défense en profondeur validerait `$propertyKey` avec `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`.

**Verdict** : **PARTIELLEMENT BLOQUÉ** — le `json_extract()` de SQLite isole l'argument de chemin. Ajouter une validation explicite de clé (`[a-zA-Z_][a-zA-Z0-9_]*`) pour la défense en profondeur.

---

### V-06 — event_type non borné : pas de liste blanche

**Risque** : `event_type` accepte n'importe quelle chaîne non vide. Des chaînes très longues ou des types à haute cardinalité gonflent le résultat `countPerType`.

```json
{"event_type": "aaaa....(10000 chars)", "user_id": "x"}
```

**Impact** : Cardinalité non bornée dans `GROUP BY event_type` peut causer une pression mémoire. Gonflement du stockage par des chaînes très longues.

**Verdict** : **EXPOSÉ** — ajouter une vérification de longueur maximale (ex. 100 caractères) et optionnellement une liste blanche de types d'événements ou une limite de longueur.

---

### V-07 — Injection SQL via les paramètres de date `from`/`to`

**Attaque** : Passer des métacaractères SQL dans la plage de dates.

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**Observé** : `$from` et `$to` sont tous les deux liés comme valeurs paramétrisées (placeholders `?`). Le moteur SQL les traite comme des chaînes littérales, pas des fragments SQL.

**Verdict** : **BLOQUÉ** — les requêtes paramétrisées empêchent l'injection SQL via les paramètres de plage de dates.

---

### V-08 — Taille des propriétés : pas de limite sur le blob JSON

**Risque** : `properties` est stocké comme `TEXT` sans validation de taille. Un attaquant peut soumettre des objets JSON de plusieurs mégaoctets.

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1Mo)"}}
```

**Impact** : Chaque grand événement consomme un stockage significatif. L'insertion en masse de grands événements peut épuiser l'espace disque.

**Verdict** : **EXPOSÉ** — ajouter une vérification de taille sur la valeur brute `properties` (ex. `strlen($raw) > 65535 → 422`). S'appuyer sur le middleware de taille de requête comme limite externe.

---

### V-09 — Flood d'événements : pas de limitation de débit sur POST /events

**Risque** : Il n'y a pas de limitation de débit sur l'endpoint d'ingestion.

**Impact** : Un seul client peut soumettre des millions d'événements par seconde, submergeant la base de données et le stockage.

**Verdict** : **EXPOSÉ** — appliquer `ThrottleMiddleware` ou une limitation de débit par IP / par clé API sur l'endpoint d'écriture.

---

### V-10 — Exposition des statistiques : `COUNT(DISTINCT user_id)` fuit le compteur d'utilisateurs

**Risque** : `GET /stats/unique-users` retourne le compte des IDs utilisateur distincts par jour.

**Impact** : Sans authentification, cela fuit les compteurs d'utilisateurs actifs quotidiens — une métrique métier sensible.

**Verdict** : **EXPOSÉ** (même racine que V-02). Restreindre ou authentifier les endpoints de statistiques.

---

## Résumé VULN

| # | Vulnérabilité | Verdict |
|---|---------------|---------|
| V-01 | Pas d'authentification sur l'endpoint d'écriture | EXPOSÉ |
| V-02 | Endpoints de statistiques lisibles par tous | EXPOSÉ |
| V-03 | `user_id` non vérifié (usurpation d'identité) | EXPOSÉ |
| V-04 | `occurred_at` fourni par l'utilisateur (antidatage/post-datage) | EXPOSÉ |
| V-05 | Concaténation de chemin `json_extract()` | PARTIELLEMENT BLOQUÉ |
| V-06 | `event_type` sans liste blanche / limite de longueur | EXPOSÉ |
| V-07 | Injection SQL via les paramètres de plage de dates | BLOQUÉ |
| V-08 | Pas de limite de taille sur le blob JSON `properties` | EXPOSÉ |
| V-09 | Pas de limitation de débit sur POST /events | EXPOSÉ |
| V-10 | Le compteur d'utilisateurs uniques fuit les métriques DAU | EXPOSÉ |

**Corrections critiques avant la production** :
1. **V-01 / V-02 / V-10** — Ajouter l'authentification (clé API ou JWT) aux endpoints d'écriture et de statistiques
2. **V-03** — Dériver `user_id` de l'identité vérifiée, pas du corps de la requête
3. **V-04** — Valider que `occurred_at` tombe dans une fenêtre de temps acceptable
4. **V-05** — Ajouter la validation `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`
5. **V-06** — Ajouter une vérification de longueur maximale `event_type` (ex. 100 chars)
6. **V-08** — Ajouter une limite de taille `properties` (ex. 64 Ko)
7. **V-09** — Appliquer la limitation de débit sur POST /events

---

## Guides associés

- [`event-sourcing.md`](event-sourcing.md) — pattern de journal d'événements immuable
- [`api-usage-metering.md`](api-usage-metering.md) — API mesuré avec imposition de quota
- [`quota-management.md`](quota-management.md) — quota par ressource avec QuotaWindow
- [`cursor-pagination.md`](cursor-pagination.md) — pagination efficace pour les flux d'événements à fort volume
