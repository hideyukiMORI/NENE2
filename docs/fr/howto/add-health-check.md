# Ajouter un health check

Ce guide montre comment étendre l'endpoint `GET /health` avec des contrôles de dépendances
en utilisant `HealthCheckInterface`.

**Prérequis** : Vous avez une application NENE2 fonctionnelle. Sinon, commencez par le [Tutoriel](../tutorial/first-api.md).

---

## Fonctionnement des health checks

`GET /health` retourne toujours un payload de base :

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

Lorsque vous enregistrez des implémentations de `HealthCheckInterface`, l'endpoint ajoute une map `checks` :

- Tous les checks réussissent → `200 OK`, `"status": "ok"`, chaque check affiche `"ok"`
- Un check échoue → `503 Service Unavailable`, `"status": "degraded"`, le check en échec affiche `"error"`

---

## Démarrage rapide

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'cache'; }
    public function check(): bool { return $this->ping(); }
    private function ping(): bool { return true; }
}

$psr17 = new Psr17Factory();
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

Réponse saine (200 OK) :
```json
{ "service": "NENE2", "status": "ok", "timestamp": "...", "checks": { "cache": "ok" } }
```

Réponse dégradée (503 Service Unavailable) :
```json
{ "service": "NENE2", "status": "degraded", "timestamp": "...", "checks": { "cache": "error" } }
```

---

## Utiliser l'implémentation de référence DatabaseHealthCheck

`src/Example/Health/DatabaseHealthCheck` est un check prêt à l'emploi pour la connectivité PDO.
Il fait partie de `src/Example/` — copiez-le et adaptez-le à votre propre projet.

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

> **Remarque** : `DatabaseHealthCheck` se trouve dans `src/Example/` — c'est une implémentation de référence,
> pas une surface d'API stable. Copiez-la dans votre application et adaptez-la à vos besoins.

---

## Plusieurs health checks

Passez autant de checks que nécessaire. Tout échec dégrade le statut global.

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

---

## Gérer les exceptions dans les checks

Si `check()` lève une exception, le framework la traite comme `false` — le statut devient `"degraded"`,
le check affiche `"error"`. Vous n'avez pas besoin de capturer les exceptions dans `check()`.

---

## Étape suivante

Consultez [Endpoints HTTP](../reference/http-endpoints.md) pour le schéma complet de réponse `/health`,
ou [Ajouter la limitation de débit](./add-rate-limiting.md) pour la protection des requêtes.
