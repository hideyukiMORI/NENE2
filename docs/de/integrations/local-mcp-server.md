# Lokale MCP-Server-Integration

Die lokale MCP-Server-Integration ermöglicht es Agenten, NENE2 über dokumentierte Grenzen zu inspizieren und zu validieren.

Dies ist eine Entwicklungsannehmlichkeit, keine Produktions-Backdoor.

## Position

Der lokale MCP-Server kann nur-lesende Inspektionstools und sichere Validierungskommandos auf dem lokalen NENE2-Checkout des Entwicklers exponieren.

Verwendet:

- Öffentliche lokale HTTP-API
- Committete Dokumentation
- `docs/mcp/tools.json`
- Dokumentierte sichere lokale Kommandos

## Erster Lokaler Server

NENE2 enthält einen nur-lokalen stdio-MCP-Server:

```bash
docker compose run --rm app php tools/local-mcp-server.php
```

Ruft standardmäßig die lokale API unter `http://localhost:8080` auf. Basis-URL bei Bedarf außerhalb des Repositories überschreiben:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://localhost:8080 app php tools/local-mcp-server.php
```

Bei Ausführung des Servers in Docker gegen den Compose-`app`-Service:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

### DB-Voraussetzungen für Schreib-Tools

Lese-Tools (`getHealth`, `listExampleNotes`, `getExampleNoteById` usw.) benötigen nur den `app`-Container.

Schreib-Tools (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) rufen Endpunkte auf, die in der Datenbank persistieren. Vor dem Aufruf von Schreib-Tools MySQL starten und Migrationen anwenden:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

Der Server unterstützt die Methoden:

- `initialize`
- `tools/list`
- `tools/call`

Tools werden aus `docs/mcp/tools.json` geladen. Nur-lesende (`safety: read`) und Schreib- (`safety: write`) OpenAPI-entsprechende Tools werden exponiert.

Lese-Tools (`getHealth`, `getFrameworkSmoke`, `listExampleNotes`, `getExampleNoteById`) mappen auf HTTP GET. Argumente werden zu Pfadparametern oder Query-String-Werten.

Schreib-Tools (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) mappen jeweils auf HTTP POST, PUT und DELETE.

## Was Nicht Zu Verwenden Ist

- Direkter Zugriff auf Produktionsdatenbank
- Rohe `.env`-Secret-Lesevorgänge
- Private Dateisystempfade des Benutzers
- Verstecktes Anwendungsverhalten, das nicht über normale Grenzen testbar ist

## Erlaubte Operationen für Lokale Tools

- Commetteten MCP-Katalog lesen
- `http://localhost:8080/` und andere dokumentierte lokale API-Routen aufrufen
- `X-Request-Id`-Metadaten aus HTTP-Antworten zurückgeben
- Dokumentierte Validierungskommandos aus `docs/integrations/local-ai-commands.md` ausführen

## Tool-Form

Lokale Tools sollten existierenden Katalog- oder OpenAPI-Operationen mappen, wenn praktisch.

Empfohlene Metadaten:

- Tool-Name
- Sicherheitslevel (`read`, `write`, `admin`, `destructive`)
- Quell-Operation oder -Kommando
- Erforderliche Scopes (falls vorhanden)
- Ob das Tool HTTP aufruft
- Ob das Tool Request-ID-Metadaten zurückgibt

`admin`- und `destructive`-Tools liegen außerhalb des aktuellen lokalen MCP-Server-Anleitungsumfangs.

### Ganzzahlige Pfadparameter

Wenn ein Tool auf einen OpenAPI-Pfad mit ganzzahligen Parametern wie `{year}` oder `{id}` mappt, diese als `"type": "integer"` im `inputSchema` deklarieren und als JSON-Zahlen in `tools/call`-Argumenten übergeben.

## HTTP-Verhalten

Wenn ein lokales MCP-Tool eine HTTP-API aufruft:

- Die konfigurierte lokale API-Basis-URL verwenden
- `Accept: application/json` für JSON-APIs senden
- Problem-Details-Fehler ohne Umschreiben beibehalten
- `X-Request-Id`-Antwort-Header zurückgeben oder loggen, wenn vorhanden
- Keine Anmeldedaten in zurückgegebenen Metadaten einschließen

## Sichere Kommandos

Lokale Kommando-Tools sollten auf dokumentierte Prüfungen begrenzt sein:

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

Kommandos, die Abhängigkeiten installieren, Datenbanken modifizieren, Releases taggen, PRs mergen oder Git-Verlauf ändern, erfordern ein fokussiertes Issue und explizite Benutzerabsicht.

## Produktionsgrenze

Produktions-MCP-Tools sollten als Produktfunktionen mit Authentifizierung, Autorisierung, Audit und operativer Eigenverantwortung gestaltet werden.

Die lokale MCP-Server-Konfiguration nicht als Produktionskonfiguration wiederverwenden.
