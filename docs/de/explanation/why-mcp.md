# Warum MCP als KI-Integrationsgrenze?

NENE2 integriert KI-Agenten über das Model Context Protocol (MCP) anstatt ihnen direkten Datenbank- oder Dateisystemzugriff zu geben. Diese Seite erklärt die Designentscheidung.

## Wie die Grenze aussieht

```
KI-Agent (Claude, Cursor, …)
    │  MCP stdio
    ▼
local-mcp-server.php          ← NENE2s MCP-Server
    │  HTTP
    ▼
NENE2-API (PSR-7 / OpenAPI)   ← Dieselben Endpunkte wie der Browser
    │  PDO
    ▼
Datenbank
```

Der KI-Agent erreicht die Datenbank niemals direkt. Jede Operation läuft über einen dokumentierten HTTP-Endpunkt mit Request-Validierung, Authentifizierung und strukturierten Fehlerantworten.

## Warum Agenten nicht direkt die Datenbank abfragen lassen?

### 1. Der API-Vertrag ist die Quelle der Wahrheit

Das OpenAPI-Dokument beschreibt, welche Operationen existieren, welche Eingaben sie akzeptieren und welche Ausgaben sie zurückgeben. SQL-Abfragen umgehen diesen Vertrag.

### 2. Autorisierung lebt in der API-Schicht

API-Key-Authentifizierung, CORS-Richtlinie und Request-Größenlimits werden in PSR-15-Middleware durchgesetzt. Eine direkte Datenbankverbindung umgeht all das.

### 3. Strukturierte Fehler helfen Agenten bei der Wiederherstellung

Wenn ein API-Aufruf fehlschlägt, erhält der Agent eine Problem Details-Antwort mit maschinenlesbarem `type` und strukturierten `errors`.

### 4. Dieselben Endpunkte dienen allen Clients

Der MCP-Server ruft dieselben Routen auf wie ein Browser, eine Testsuite oder ein curl-Befehl.

## Tool-Sicherheitsstufen

| Stufe | Beispiele | Anforderungen |
|-------|----------|---------------|
| `read` | `getHealth`, `getNote` | Nur API-Key |
| `write` | `createNote`, `updateNote` | Wie oben |
| `admin` | Hypothetische Rollenänderungen | Expliziter Bestätigungsschritt |
| `destructive` | Batch-Löschungen | Außerhalb des lokalen Geltungsbereichs |
