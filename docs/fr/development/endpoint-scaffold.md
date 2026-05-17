# Workflow d'échafaudage d'endpoint

Ce workflow aligne un nouvel endpoint JSON NENE2 sur l'ensemble du code runtime, OpenAPI, tests et métadonnées MCP optionnelles.

Intentionnellement manuel pour le moment. L'objectif est de clarifier les étapes avant d'ajouter des générateurs.

## Position

Un endpoint n'est complet que lorsque son comportement est visible dans tous les endroits concernés :

- Route runtime et handler
- Chemin OpenAPI, schéma de réponse et exemples
- Tests runtime ou handler
- Tests de contrat via `docs/openapi/openapi.yaml`
- Mise à jour du catalogue MCP si l'endpoint devient un outil
- Mise à jour de la documentation si l'endpoint modifie les politiques ou workflows du projet

## Procédure standard

1. Créer ou réutiliser une Issue GitHub ciblée.
2. Ajouter ou mettre à jour la route runtime à la plus petite frontière de handler appropriée.
3. Ajouter le chemin OpenAPI avec `operationId`, exemples, schéma de succès et réponses Problem Details.
4. Ajouter ou mettre à jour les tests proches du comportement.
5. Exécuter d'abord les tests ciblés, puis `docker compose run --rm app composer check`.
6. Si l'endpoint est accessible via Docker, exécuter un smoke check HTTP local.
7. Mettre à jour `docs/todo/current.md`, les documents de milestone et le catalogue MCP uniquement si le travail actuel est impacté.

## Route runtime

Dans le runtime minimal actuel, les routes d'exemple sont décrites dans `RuntimeApplicationFactory`.

Exemple d'échafaudage :

```text
GET /examples/ping
```

Réponse retournée :

```json
{
  "message": "pong",
  "status": "ok"
}
```

Cet endpoint existe pour pratiquer le workflow. Au fur et à mesure que le comportement augmente, les endpoints applicatifs doivent migrer vers des handlers fins qui délèguent aux cas d'utilisation.

### Paramètres de chemin

Le routeur stocke les paramètres de chemin correspondants dans un tableau nommé sous `Router::PARAMETERS_ATTRIBUTE` — ils ne sont pas définis comme des attributs PSR-7 individuels.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $request) use ($json): ResponseInterface {
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

Écrire `$request->getAttribute('id')` retourne `null`. Lisez toujours depuis `Router::PARAMETERS_ATTRIBUTE`.

## Exigences OpenAPI

Chaque endpoint JSON livré doit inclure :

- un `operationId` stable
- un court summary et une description sûre
- un schéma de réponse `200` et un exemple `ok`
- des réponses Problem Details appropriées (`401`, `413`, `500`, etc.)
- des exigences de sécurité uniquement si le middleware correspondant existe

Les tests de contrat runtime lisent automatiquement `docs/openapi/openapi.yaml` et valident les exemples `200` documentés des endpoints JSON.

## Exigences de test

Utilisez d'abord la vérification la plus ciblée :

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

Puis exécutez la vérification backend complète :

```bash
docker compose run --rm app composer check
```

Pour les endpoints servis via Docker, exécutez un smoke check local :

```bash
curl -i http://localhost:8080/examples/ping
```

## Relation avec MCP

Si un nouvel endpoint devient un outil MCP :

1. Ajouter d'abord l'opération OpenAPI.
2. Ajouter une entrée read-only dans `docs/mcp/tools.json` uniquement si l'outil peut appeler la frontière API publique en toute sécurité.
3. Exécuter `docker compose run --rm app composer mcp`.
4. Les outils de mutation, admin et destructifs restent hors scope jusqu'à ce que l'authentification, l'autorisation et le comportement d'audit soient documentés et implémentés.

## Non-objectifs

- Générer automatiquement des fichiers d'endpoint avant que le workflow manuel soit prouvé utile.
- Cacher le comportement des routes avec une détection magique de contrôleurs.
- Mettre à jour le catalogue MCP pour tous les endpoints par défaut.
- Exiger la validation OpenAPI runtime avant que les patterns de routes et schémas soient stables.

## Documentation associée

- Runtime : `src/Http/RuntimeApplicationFactory.php`
- OpenAPI : `docs/openapi/openapi.yaml`
- Tests de contrat runtime : `tests/OpenApi/RuntimeContractTest.php`
- Politique de validation des requêtes : `docs/development/request-validation.md`
- Politique de couche domaine : `docs/development/domain-layer.md`
- Politique des outils MCP : `docs/integrations/mcp-tools.md`
