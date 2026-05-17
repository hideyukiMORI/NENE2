# Client-Projekt-Startanleitung

Diese Anleitung erklärt, wie NENE2 in ein kleines Client-artiges API-Projekt adaptiert wird.

Absichtlich praktisch und manuell. Das Ziel ist es, die erste Projektübergabe glaubwürdig zu machen, bevor Generatoren oder breite Framework-Komfort-Schichten hinzugefügt werden.

## Ausgangspunkt

Verwenden Sie diese Anleitung, wenn ein Projekt benötigt:

- eine laufende lokale JSON-API
- OpenAPI-Dokumentation, die früh geteilt werden kann
- einen kleinen Satz getesteter Endpunkte
- optionale React-Starter-Integration
- sichere lokale MCP-Inspektion über dokumentierte API-Grenzen
- grundlegende Machine-Client-Authentifizierung
- einen Docker-basierten Datenbank-Verifikationspfad

NENE2 ist noch eine `0.x`-Grundlage. Öffentliche Verträge als nützlich, aber noch in Bildung, behandeln.

## Öffentliche Feldtest-Referenz-Sandbox (optional)

Nach dem ersten lokalen Meilenstein kann es helfen, eine **abgeschlossene öffentliche Demo** zu inspizieren, die auf dem dokumentierten Scaffold-Pfad geblieben ist:

- Repository: [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial) (basiert auf NENE2 **`v0.1.1`**).
- Inhalt: nur-lesende Ausstellungs-JSON-APIs, OpenAPI, PHPUnit, lokale MCP-Tools und Markdown-Feldtest-Notizen.

Dies ist **kein** offizielles Produkt-Repository und **impliziert keine Befürwortung** einer echten Ausstellung. **Fiktive Sandbox-Daten** — lesen Sie `README.md` und `SECURITY.md` dieses Projekts, bevor Sie Namen oder Jahre als Fakten behandeln.

## Start von `composer require`

Wenn Sie ein neues Projekt von Grund auf starten statt das NENE2-Repository zu forken:

```bash
mkdir my-project && cd my-project
composer init --name="vendor/my-project" --no-interaction
composer require hideyukimori/nene2:^0.3
```

Dann die minimalen Dateien manuell erstellen:

**`.env`**
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="My Project"
DB_ADAPTER=sqlite
```

**`public/index.php`** — Front-Controller mit eingebautem Container:
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

Lokal mit dem eingebauten PHP-Server bereitstellen:
```bash
php -S localhost:8080 -t public
```

### Benutzerdefinierte Routen hinzufügen

Benutzerdefinierte Routen über `$routeRegistrars` übergeben:

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

## Erste Lokale Einrichtung

Von einem sauberen Klon starten:

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

Lokale API und Docs bestätigen:

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

Nützliche Browser-URLs:

- OpenAPI: `http://localhost:8080/openapi.php`
- Swagger-Oberfläche: `http://localhost:8080/docs/`

## Projektgrenze umbenennen

Vor dem Hinzufügen von Anwendungsverhalten zuerst projektbezogene Metadaten aktualisieren:

- Projektbeschreibung in `README.md`
- Paketname und -beschreibung in `composer.json`
- OpenAPI `info.title`, `info.description` und `info.version`
- Standardbeispiele, die NENE2 selbst nicht mehr beschreiben sollen

## Ersten Anwendungsendpunkt hinzufügen

`docs/development/endpoint-scaffold.md` für jeden ausgelieferten JSON-Endpunkt verwenden.

1. Ein fokussiertes GitHub Issue erstellen oder wiederverwenden.
2. Die Route in der kleinsten klaren Laufzeitgrenze hinzufügen.
3. Den OpenAPI-Pfad, `operationId`, Schema und Beispiele hinzufügen.
4. Laufzeittests nahe am Endpunktverhalten hinzufügen.
5. `tests/OpenApi/RuntimeContractTest.php` die dokumentierten Erfolgsbeispiele verifizieren lassen.
6. Einen lokalen HTTP-Smoke-Check über Docker ausführen.

## OpenAPI als Übergabevertrag behalten

OpenAPI sollte im selben PR wie Endpunktverhalten aktualisiert werden.

Vor dem Versand der Übergabe ausführen:

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## MCP nur über API-Grenzen hinzufügen

1. OpenAPI-Operation hinzufügen oder bestätigen.
2. Einen Read-only-Eintrag in `docs/mcp/tools.json` hinzufügen.
3. `docker compose run --rm app composer mcp` ausführen.
4. Den lokalen MCP-Server nur gegen lokale APIs smoke-testen.

## Machine-Client-Pfade schützen

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

Keine echten API-Schlüssel, generierten Secrets oder lokalen `.env`-Dateien committen.

## Datenbankverhalten verifizieren

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Übergabe-Checkliste

Vor der Übergabe eines Client-artigen Projekts bestätigen:

- `README.md` beschreibt das Projekt, nicht nur den Starter.
- `docs/openapi/openapi.yaml` entspricht dem ausgelieferten JSON-Verhalten.
- Swagger-Oberfläche lädt lokal.
- Neue Endpunkte haben Laufzeittests und OpenAPI-Beispiele.
- Geschützte Routen dokumentieren erforderliche Anmeldedaten ohne Secret-Werte preiszugeben.
- MCP-Tools, falls vorhanden, rufen nur dokumentierte API-Grenzen auf.
- `docker compose run --rm app composer check` besteht.
- Zurückgestellte Arbeit ist in `docs/todo/current.md` sichtbar.

## Nützliche Folgedokumente

- Domain-Layer-Richtlinie: `docs/development/domain-layer.md`
- Endpoint-Scaffold-Workflow: `docs/development/endpoint-scaffold.md`
- Lokale MCP-Server-Anleitung: `docs/integrations/local-mcp-server.md`
- Authentifizierungsgrenze: `docs/development/authentication-boundary.md`
- Datenbanktest-Strategie: `docs/development/test-database-strategy.md`
