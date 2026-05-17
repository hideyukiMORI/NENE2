# Endpoint-Scaffold-Workflow

Dieser Workflow stellt sicher, dass ein neuer NENE2-JSON-Endpunkt über Laufzeitcode, OpenAPI, Tests und optionale MCP-Metadaten hinweg konsistent ist.

Derzeit absichtlich manuell. Das Ziel ist es, die Schritte zu klären, bevor Generatoren hinzugefügt werden.

## Position

Ein Endpunkt ist erst vollständig, wenn sein Verhalten an allen relevanten Stellen sichtbar ist:

- Laufzeitroute und Handler
- OpenAPI-Pfad, Antwortschema und Beispiele
- Laufzeit- oder Handler-Tests
- Vertragstests über `docs/openapi/openapi.yaml`
- MCP-Katalog-Update, wenn der Endpunkt ein Tool wird
- Dokumentations-Update, wenn der Endpunkt Projektrichtlinien oder -workflows ändert

## Standardverfahren

1. Ein fokussiertes GitHub Issue erstellen oder wiederverwenden.
2. Die Laufzeitroute an der kleinsten geeigneten Handler-Grenze hinzufügen oder aktualisieren.
3. Den OpenAPI-Pfad mit `operationId`, Beispielen, Erfolgsschema und Problem-Details-Antworten hinzufügen.
4. Tests nahe am Verhalten hinzufügen oder aktualisieren.
5. Zuerst fokussierte Tests ausführen, dann `docker compose run --rm app composer check`.
6. Wenn der Endpunkt über Docker erreichbar ist, einen lokalen HTTP-Smoke-Check ausführen.
7. `docs/todo/current.md`, Milestone-Dokumente und MCP-Katalog nur aktualisieren, wenn die aktuelle Arbeit betroffen ist.

## Laufzeitroute

Im aktuellen minimalen Runtime sind Beispielrouten in `RuntimeApplicationFactory` beschrieben.

Scaffold-Beispiel:

```text
GET /examples/ping
```

Zurückgegebene Antwort:

```json
{
  "message": "pong",
  "status": "ok"
}
```

Dieser Endpunkt existiert zum Üben des Workflows. Wenn das Verhalten zunimmt, sollten Anwendungsendpunkte zu dünnen Handlern migrieren, die an Use-Cases delegieren.

### Pfadparameter

Der Router speichert übereinstimmende Pfadparameter als benanntes Array unter `Router::PARAMETERS_ATTRIBUTE` — nicht als einzelne PSR-7-Anforderungsattribute.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $request) use ($json): ResponseInterface {
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

`$request->getAttribute('id')` schreiben gibt `null` zurück. Immer von `Router::PARAMETERS_ATTRIBUTE` lesen.

## OpenAPI-Anforderungen

Jeder ausgelieferte JSON-Endpunkt muss enthalten:

- eine stabile `operationId`
- eine kurze Zusammenfassung und sichere Beschreibung
- `200`-Antwortschema und `ok`-Beispiel
- geeignete Problem-Details-Antworten (`401`, `413`, `500` usw.)
- Sicherheitsanforderungen nur wenn entsprechende Middleware existiert

Laufzeit-Vertragstests lesen automatisch `docs/openapi/openapi.yaml` und validieren dokumentierte `200`-Beispiele für JSON-Endpunkte.

## Testanforderungen

Zuerst die engste nützliche Prüfung verwenden:

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

Dann die vollständige Backend-Prüfung ausführen:

```bash
docker compose run --rm app composer check
```

Für über Docker bereitgestellte Endpunkte einen lokalen Smoke-Check ausführen:

```bash
curl -i http://localhost:8080/examples/ping
```

## Beziehung zu MCP

Wenn ein neuer Endpunkt ein MCP-Tool wird:

1. Zuerst die OpenAPI-Operation hinzufügen.
2. Einen Read-only-Eintrag in `docs/mcp/tools.json` nur hinzufügen, wenn das Tool die öffentliche API-Grenze sicher aufrufen kann.
3. `docker compose run --rm app composer mcp` ausführen.
4. Mutations-, Admin- und destruktive Tools bleiben außerhalb des Geltungsbereichs, bis Authentifizierungs-, Autorisierungs- und Audit-Verhalten dokumentiert und implementiert ist.

## Nicht-Ziele

- Endpunkt-Dateien automatisch generieren, bevor der manuelle Workflow als nützlich erwiesen wurde.
- Routenverhalten mit magischer Controller-Erkennung verstecken.
- Den MCP-Katalog standardmäßig für alle Endpunkte aktualisieren.
- Laufzeit-OpenAPI-Validierung erfordern, bevor Routen- und Schema-Muster stabil sind.

## Verwandte Dokumentation

- Runtime: `src/Http/RuntimeApplicationFactory.php`
- OpenAPI: `docs/openapi/openapi.yaml`
- Laufzeit-Vertragstests: `tests/OpenApi/RuntimeContractTest.php`
- Anforderungsvalidierungsrichtlinie: `docs/development/request-validation.md`
- Domain-Layer-Richtlinie: `docs/development/domain-layer.md`
- MCP-Tool-Richtlinie: `docs/integrations/mcp-tools.md`
