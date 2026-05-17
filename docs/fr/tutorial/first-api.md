# Votre première API en 10 minutes

Ce tutoriel vous guide de zéro à une API JSON fonctionnelle avec NENE2.

À la fin vous aurez :
- une API locale qui répond aux requêtes HTTP
- un endpoint `/hello` qui retourne du JSON
- une compréhension du flux des requêtes à travers le framework

**Pour qui** : Les développeurs qui connaissent JavaScript ou Python mais n'ont jamais utilisé PHP. Si vous avez utilisé Express ou FastAPI, les concepts s'y mappent directement.

**Durée** : environ 10 minutes.

---

## Ce dont vous avez besoin

| Outil | Pourquoi | Vérification |
|---|---|---|
| PHP 8.4 | exécute l'application | `php --version` |
| Composer | gestionnaire de paquets PHP (comme npm) | `composer --version` |
| Un terminal | toutes les commandes s'y exécutent | — |

> **Alternative Docker** : si vous préférez ne pas installer PHP localement, Docker fonctionne aussi.
> Voir [Configuration avec Docker](#configuration-avec-docker) en bas de cette page.

---

## Étape 1 — Créer un répertoire de projet

```bash
mkdir my-api && cd my-api
```

C'est comme `mkdir my-app && cd my-app` dans un projet Node.js.

---

## Étape 2 — Installer NENE2

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` est l'équivalent PHP de `npm install`. Il télécharge NENE2 et ses dépendances dans `vendor/`.

Après cela, votre répertoire ressemble à :

```
my-api/
  vendor/        ← paquets installés (comme node_modules/)
  composer.json  ← métadonnées du paquet (comme package.json)
  composer.lock  ← versions verrouillées (comme package-lock.json)
```

---

## Étape 3 — Créer un fichier `.env`

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` fonctionne comme dans Node.js. Le framework le lit automatiquement au démarrage.

---

## Étape 4 — Créer le contrôleur frontal

Créez `public/index.php` :

```php
<?php
declare(strict_types=1);

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/hello', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['message' => 'Hello, world!', 'status' => 'ok']);
            });
        },
    ],
))->create();

$request  = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response = $app->handle($request);

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
```

**Ce que ça fait** (ligne par ligne) :

- `require .../vendor/autoload.php` — charge tous les paquets installés, comme `import` en JS
- `$psr17 = new Psr17Factory()` — crée des factories d'objets HTTP (pensez : constructeurs de requêtes/réponses)
- `RuntimeApplicationFactory` — assemble le pipeline middleware complet
- `routeRegistrars` — là où vous ajoutez vos propres routes (voir les docs HOWTO)
- `$router->get('/hello', ...)` — enregistre une route GET, comme `app.get('/hello', ...)` dans Express
- `$json->create([...])` — construit une réponse JSON depuis un tableau PHP

---

## Étape 5 — Démarrer le serveur

```bash
php -S localhost:8080 -t public
```

C'est le serveur de développement intégré de PHP. C'est l'équivalent de `npm run dev` — pas pour la production, mais parfait pour le développement local.

Vous devriez voir :

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## Étape 6 — Appeler l'API

Ouvrez un nouveau terminal et exécutez :

```bash
curl http://localhost:8080/hello
```

Vous devriez voir :

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

Essayez aussi l'endpoint de santé intégré :

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

C'est votre première API en fonctionnement. Voyons ce qui est inclus d'autre.

---

## Étape 7 — Voir la gestion des erreurs en action

NENE2 retourne [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457) pour toutes les erreurs. Appelez une route qui n'existe pas :

```bash
curl http://localhost:8080/missing
```

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "instance": "/missing"
}
```

Chaque réponse d'erreur a un URI `type`, un `title` et un `status` HTTP. C'est le format standard utilisé dans toutes les réponses d'erreur NENE2.

---

## Ce qui vient de se passer

Voici le flux de requête pour `GET /hello` :

```
Requête HTTP
  → RequestIdMiddleware      ajoute l'en-tête X-Request-Id
  → SecurityHeadersMiddleware ajoute X-Content-Type-Options etc.
  → CorsMiddleware           gère le preflight CORS
  → ErrorHandlerMiddleware   capture les exceptions non gérées
  → RequestSizeLimitMiddleware rejette les charges trop importantes
  → Router                   correspond /hello → votre handler
  → votre handler            retourne {"message": "Hello, world!"}
Réponse HTTP
```

Tout cela se passe automatiquement. Votre handler a seulement besoin de retourner une réponse — le framework gère les en-têtes, le formatage des erreurs et la corrélation des requêtes.

---

## Étapes suivantes

- **Ajouter un paramètre de chemin** (comme `/hello/{name}`) : voir [Ajouter une route personnalisée](../howto/add-custom-route.md)
- **Connecter une base de données** : voir [Ajouter un endpoint avec base de données](../howto/add-database-endpoint.md)
- **Voir la documentation API complète** : démarrez le serveur et ouvrez `http://localhost:8080/openapi.php`

---

## Configuration avec Docker

Si vous préférez Docker à une installation PHP locale :

```bash
mkdir my-api && cd my-api
```

Créez un `compose.yaml` minimal :

```yaml
services:
  app:
    image: php:8.4-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html
```

Puis installez Composer à l'intérieur du conteneur :

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

Suivez les étapes 3 à 4 ci-dessus pour créer `.env` et `public/index.php`, puis :

```bash
docker compose up -d
curl http://localhost:8080/hello
```

Pour une configuration Docker plus complète avec support MySQL, consultez le [guide de configuration du dépôt NENE2](../development/setup.md).
