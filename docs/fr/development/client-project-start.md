# Guide de démarrage projet client

Ce guide explique comment adapter NENE2 en un petit projet API style client.

Intentionnellement pratique et manuel. L'objectif est de rendre le premier transfert de projet crédible avant d'ajouter des générateurs ou des couches de commodité framework élargies.

## Point de départ

Utilisez ce guide lorsqu'un projet a besoin de :

- une API JSON locale fonctionnelle
- une documentation OpenAPI partageable tôt
- un petit ensemble d'endpoints testés
- une intégration React starter optionnelle
- une inspection MCP locale sûre via des frontières API documentées
- une authentification machine-client basique
- un chemin de vérification de base de données basé sur Docker

NENE2 est encore une fondation `0.x`. Traitez les contrats publics comme utiles mais encore en formation.

## Sandbox de référence pour essais sur le terrain publics (optionnel)

Après le premier milestone local, il peut être utile d'inspecter une **démo publique complète** qui est restée sur le chemin d'échafaudage documenté :

- Dépôt : [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial) (basé sur NENE2 **`v0.1.1`**).
- Contenu : APIs JSON en lecture seule, OpenAPI, PHPUnit, outils MCP locaux et notes d'essai sur le terrain.

Ce n'est **pas** un dépôt de produit officiel. **Données sandbox fictives** — lisez le `README.md` et `SECURITY.md` de ce projet avant de traiter les noms ou années comme des faits.

## Démarrage depuis `composer require`

Si vous démarrez un nouveau projet depuis zéro plutôt que de forker le dépôt NENE2 :

```bash
mkdir my-project && cd my-project
composer init --name="vendor/my-project" --no-interaction
composer require hideyukimori/nene2:^0.3
```

Puis créez les fichiers minimum manuellement :

**`.env`**
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="My Project"
DB_ADAPTER=sqlite
```

**`public/index.php`** — contrôleur frontal utilisant le conteneur intégré :
```php
<?php
declare(strict_types=1);

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeContainerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = (new RuntimeContainerFactory(dirname(__DIR__)))->create();
$psr17     = $container->get(Psr17Factory::class);
$request   = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response  = $container->get(RequestHandlerInterface::class)->handle($request);
$container->get(ResponseEmitter::class)->emit($response);
```

Servez localement avec le serveur intégré de PHP :
```bash
php -S localhost:8080 -t public
```

### Ajout de routes personnalisées

Passez des routes personnalisées via `$routeRegistrars` :

```php
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
                $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                return $json->create(['id' => (int) ($params['id'] ?? 0)]);
            });
        },
    ],
))->create();
```

## Premier setup local

Commencez depuis un clone propre :

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

Confirmez l'API et les docs locaux :

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

URLs navigateur utiles :

- OpenAPI : `http://localhost:8080/openapi.php`
- Interface Swagger : `http://localhost:8080/docs/`

## Renommage de la frontière du projet

Avant d'ajouter du comportement applicatif, mettez à jour les métadonnées orientées projet :

- Description du projet dans `README.md`
- Nom et description du package dans `composer.json`
- `info.title`, `info.description` et `info.version` OpenAPI
- Les exemples par défaut qui ne décrivent plus NENE2 lui-même

## Ajout du premier endpoint applicatif

Utilisez `docs/development/endpoint-scaffold.md` pour chaque endpoint JSON livré.

1. Créer ou réutiliser une Issue GitHub ciblée.
2. Ajouter la route dans la plus petite frontière runtime claire.
3. Ajouter le chemin OpenAPI, `operationId`, schéma et exemples.
4. Ajouter des tests runtime proches du comportement de l'endpoint.
5. Laisser `tests/OpenApi/RuntimeContractTest.php` vérifier les exemples de succès documentés.
6. Exécuter un smoke check HTTP local via Docker.

## Garder OpenAPI comme contrat de transfert

OpenAPI doit être mis à jour dans le même PR que le comportement de l'endpoint.

Avant d'envoyer le transfert :

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## Ajouter MCP uniquement via des frontières API

1. Ajouter ou confirmer l'opération OpenAPI.
2. Ajouter une entrée read-only dans `docs/mcp/tools.json`.
3. Exécuter `docker compose run --rm app composer mcp`.
4. Tester en fumée le serveur MCP local uniquement contre des APIs locales.

## Protéger les chemins machine-client

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

Ne commitez pas les vraies clés API, secrets générés ou fichiers `.env` locaux.

## Vérifier le comportement de base de données

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Checklist de transfert

Avant de transférer un projet style client, confirmez :

- `README.md` décrit le projet, pas juste le starter.
- `docs/openapi/openapi.yaml` correspond au comportement JSON livré.
- L'interface Swagger se charge localement.
- Les nouveaux endpoints ont des tests runtime et des exemples OpenAPI.
- Les routes protégées documentent les credentials requis sans exposer les valeurs secrètes.
- Les outils MCP, s'il y en a, appellent uniquement les frontières API documentées.
- `docker compose run --rm app composer check` passe.
- Le travail différé est visible dans `docs/todo/current.md`.

## Prochains documents utiles

- Politique de couche domaine : `docs/development/domain-layer.md`
- Workflow d'échafaudage d'endpoint : `docs/development/endpoint-scaffold.md`
- Guidance serveur MCP local : `docs/integrations/local-mcp-server.md`
- Politique d'authentification : `docs/development/authentication-boundary.md`
- Stratégie de test de base de données : `docs/development/test-database-strategy.md`
