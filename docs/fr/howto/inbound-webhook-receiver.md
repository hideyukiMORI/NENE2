# Comment ajouter un récepteur de webhooks entrants

Recevoir des webhooks de plusieurs services externes, valider les signatures HMAC par source et stocker les événements avec idempotence.

## Schéma

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/sources` | Enregistrer une source de webhook |
| `POST` | `/sources/{id}/receive` | Recevoir un webhook |
| `GET` | `/sources/{id}/events` | Lister les événements reçus |
| `GET` | `/events/{id}` | Obtenir un événement spécifique |

## Validation de signature HMAC-SHA256

Chaque source a son propre secret HMAC. Ne jamais l'exposer dans les réponses.

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // sûr temporellement
}
```

Ordre d'appel : **valider la signature d'abord**, puis vérification d'idempotence, puis stocker :

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... vérification d'idempotence ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## Idempotence (event_id par source)

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

La contrainte `UNIQUE(source_id, event_id)` est le garde-fou au niveau DB. La vérification PHP ci-dessus évite le chemin d'exception lors du premier doublon.

## Ne jamais exposer le secret

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // supprimer avant de retourner
return $this->json->create($source, 201);
```

## Vérification de source inactive

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## Notes MySQL

La contrainte `UNIQUE KEY uq_source_event (source_id, event_id)` fonctionne de la même façon en MySQL. Utiliser `VARCHAR(191)` pour les colonnes texte indexées pour rester dans la limite de longueur de clé InnoDB.

### Exécuter les tests d'intégration MySQL

Démarrer le conteneur MySQL FT partagé (port 3308, volume persistant) :

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Puis exécuter les tests d'intégration avec les variables d'environnement :

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Sans `MYSQL_HOST`, les tests MySQL sont automatiquement ignorés (`markTestSkipped`).

## Notes de sécurité

- `hash_equals()` prévient les attaques temporelles lors de la comparaison de signatures.
- Le corps JSON brut est stocké tel quel ; ne pas parser avant la vérification de signature.
- Le même `event_id` de deux sources différentes crée des enregistrements séparés — la contrainte UNIQUE est `(source_id, event_id)`, pas juste `event_id`.
