# Lokale MCP-Client-Konfiguration

Diese Anleitung erklärt, wie ein lokaler MCP-Client mit dem stdio-MCP-Server von NENE2 verbunden wird.

Nur für lokale Entwicklung. Diese Konfiguration nicht für Produktions-MCP-Deployments wiederverwenden.

## Voraussetzungen

PHP-Image erstellen und lokale API starten:

```bash
docker compose build app
docker compose up -d app
```

Prüfen, ob die API erreichbar ist:

```bash
curl -i http://localhost:8080/health
```

Der MCP-Server ist ein stdio-Prozess. Kein HTTP-Server — er muss vom MCP-Client gestartet werden.

## Generische stdio-Konfiguration

Für MCP-Clients, die Befehl, Argumente und Umgebungsvariablen akzeptieren, dieses Format verwenden:

```json
{
  "mcpServers": {
    "nene2-local": {
      "command": "docker",
      "args": [
        "compose",
        "run",
        "--rm",
        "-e",
        "NENE2_LOCAL_API_BASE_URL=http://app",
        "app",
        "php",
        "tools/local-mcp-server.php"
      ]
    }
  }
}
```

Warum `http://app` verwendet wird:

- Der MCP-Server-Prozess läuft innerhalb des Docker Compose `app`-Containers
- Der Ziel-Web-Service ist über den Compose-Service-Namen erreichbar
- `localhost` in diesem Container verweist auf den einmaligen MCP-Container, nicht auf den laufenden Web-Service

Keine Secrets in committeten MCP-Client-Konfigurationen.

## Lokaler Smoke-Check

Das Smoke-Helfer-Skript verwenden, um eine vollständige JSON-RPC-Sequenz ohne Boilerplate auszuführen.

Der App-Service muss zuerst gestartet sein:

```bash
docker compose up -d app
```

Dann den Helper ausführen:

```bash
# nur initialize + tools/list
bash tools/mcp-smoke.sh

# Ein spezifisches Tool aufrufen
bash tools/mcp-smoke.sh getHealth '{}'

# Ein Tool mit Pfadparametern aufrufen (JSON-Zahlen für Integer-Felder verwenden)
bash tools/mcp-smoke.sh getExhibitionWorkByYearAndId '{"year":2026,"workId":20260101}'
```

Die API-Basis-URL bei Bedarf überschreiben:

```bash
NENE2_LOCAL_API_BASE_URL=http://my-api bash tools/mcp-smoke.sh getHealth '{}'
```

**Manuelle Alternative** — für mehr Kontrolle rohe JSON-RPC-Zeilen pipen:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Verfügbare Tools

Der erste lokale Server lädt nur-lesende Tools aus `docs/mcp/tools.json`.

Aktuelle Beispiele:

- `getFrameworkSmoke`
- `getHealth`

Zum Validieren des Katalogs:

```bash
docker compose run --rm app composer mcp
```

### Pfadparameter-Typen

Tools, die auf OpenAPI-Pfade mit ganzzahligen Parametern (z.B. `{year}`, `{id}`) mappen, erfordern JSON-Zahlen in `tools/call`-Argumenten, keine Strings.

Korrekt:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Falsch (wird abgelehnt, wenn Schema `integer` angibt):

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

`inputSchema` des Tools in `docs/mcp/tools.json` für die erwarteten Typen prüfen.

## Sicherheitsregeln

Erlaubte Operationen für den lokalen MCP-Client:

- Dokumentierte lokale HTTP-API aufrufen
- Committete MCP-Metadaten über den Server lesen
- Nur-lesende Tools entsprechend OpenAPI-Operationen verwenden

Verbotene Operationen für den lokalen MCP-Client:

- `.env`-Secrets lesen
- Produktions-APIs aufrufen
- Direkten Datenbank- oder Dateisystemzugriff exponieren
- Schreib-, Admin- oder destruktive Tools ohne fokussiertes Issue und Design hinzufügen
- Benutzerspezifische MCP-Client-Konfigurationen committen

## Verwandte Dokumentation

- Lokale MCP-Server-Anleitung: `docs/integrations/local-mcp-server.md`
- MCP-Tool-Richtlinie: `docs/integrations/mcp-tools.md`
- MCP-Katalog: `docs/mcp/tools.json`
- Client-Projekt-Startanleitung: `docs/development/client-project-start.md`
- Authentifizierungsgrenze: `docs/development/authentication-boundary.md`
