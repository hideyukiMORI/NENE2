# MCP-Tool-Integrationsrichtlinie

NENE2s MCP-Tools müssen Anwendungsfunktionalität über dokumentierte Grenzen exponieren, nicht über versteckte Datenbank- oder Dateisystem-Abkürzungen.

## Position

MCP-Integration ist eine API-kompatible Integrationsschicht.

Standardrichtung:

- Tool-Form aus OpenAPI ableiten, wenn praktisch
- Mit nur-lesenden Inspektionstools beginnen
- Lokale Entwicklungstools von Produktionstools trennen
- Explizite Autorisierung und Audit-Richtlinie vor Mutations-Tools erfordern
- Direkten Datenbankzugriff von MCP-Tools standardmäßig vermeiden

## Tool-Quellen

Empfohlene Quellen für Tool-Definitionen:

- OpenAPI-Operationen öffentlicher JSON-APIs
- Dokumentierte Anwendungsservices für interne Tools ohne HTTP
- Explizite Wartungskommandos für nur-lokale Workflows

Vermeiden Sie das Erstellen von nur-MCP-Verhalten, das nicht über normale Anwendungsgrenzen ausgeführt und verifiziert werden kann.

## Katalog

Der erste maschinenlesbare MCP-Tool-Katalog befindet sich in `docs/mcp/tools.json`.

Enthält nur-lesende Tool-Metadaten entsprechend ausgelieferten OpenAPI-Operationen. Der Katalog wird validiert durch:

```bash
docker compose run --rm app composer mcp
```

`composer check` enthält diese Validierung.

## Sicherheitslevel

Jedes MCP-Tool muss vor der Implementierung klassifiziert werden:

- `read`: gibt zurück ohne Anwendungszustand zu ändern
- `write`: ändert Anwendungszustand
- `admin`: ändert Konfiguration, Berechtigungen, Datenhaltung oder Betriebszustand
- `destructive`: löscht Daten oder führt irreversible Operationen durch

Erste MCP-Tools sollten `read`-Tools sein.

`write`-, `admin`- und `destructive`-Tools erfordern:

- Dokumentiertes Authentifizierungs- und Autorisierungsverhalten
- Audit-/Logging-Felder
- Request-ID-Weitergabe
- Explizites Bestätigungsverhalten für destruktive Aktionen
- Tests, die Fehler und Berechtigungsgrenzen abdecken

API-Schlüssel- und Token-Grenzen sind in `docs/development/authentication-boundary.md` definiert.

## Lokale Entwicklungstools

Nur-lokale MCP-Tools helfen Agenten, die Entwicklungsanwendung zu inspizieren, aber der Geltungsbereich muss klar begrenzt sein.

Erlaubte Operationen für lokale Tools:

- Lokale HTTP-API aufrufen
- Committete Dokumentation lesen
- Dokumentierte sichere Validierungskommandos ausführen

Verbotene Operationen für lokale Tools:

- `.env`-Secrets lesen
- Anwendungsautorisierung auf eine Art umgehen, die dem Produktionsverhalten ähnelt
- Datenbank außerhalb dokumentierter Test- oder Migrationskommandos modifizieren
- Vom privaten Dateisystem-Layout des Entwicklers abhängen

## Produktionstools

Produktions-MCP-Tools müssen als Produktfunktionen gestaltet werden, nicht als Debug-Abkürzungen.

Vor dem Aktivieren eines Produktionstools dokumentieren:

- Eigentümer und Zweck
- Erforderliche Anmeldedaten oder Scopes
- Erlaubte Umgebungen
- Ratenbegrenzungen oder Anti-Missbrauchsmaßnahmen
- Audit-Felder
- Rollback- oder Reparaturpfad für fehlgeschlagene Mutationen

## Ausrichtung auf OpenAPI

Wenn ein Tool auf eine HTTP-API-Operation mappt:

- OpenAPI-Operations-Summary und -Schema als Ausgangspunkt verwenden
- Parameternamen mit dem API-Vertrag abgleichen
- Problem-Details-Fehlerverhalten beibehalten
- Request-ID in Logs und zurückgegebene Metadaten einschließen, wenn nützlich

Wenn ein Tool eine Form benötigt, die nicht zur aktuellen API passt, zuerst den API-Vertrag aktualisieren oder dokumentieren, warum eine interne Service-Grenze besser ist.

### Pfadparameter-Typen

Wenn ein OpenAPI-Pfadparameter vom Typ `integer` ist (z.B. `{year}`, `{id}`), muss das `inputSchema` des Tools diesen Typ widerspiegeln:

```json
"inputSchema": {
  "type": "object",
  "properties": {
    "year": { "type": "integer" }
  },
  "required": ["year"]
}
```

LLM-Clients müssen ganzzahlige Pfadparameter als JSON-Zahlen senden, nicht als Strings:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Einen String (`"2026"`) zu senden wird von der Adapter-Validierung abgelehnt, wenn das Schema `"type": "integer"` angibt.

## Nicht-Ziele

- Direkte Produktionsdatenbank-Tools als ersten MCP-Meilenstein bereitstellen.
- Nur-MCP-Geschäftslogik, die HTTP/API-Tests umgeht.
- MCP-Anmeldedaten im Repository speichern.
- Destruktive Tools exponieren, bevor Authentifizierungs-, Autorisierungs- und Audit-Richtlinien existieren.
