# Authentifizierungsgrenz-Richtlinie

NENE2 behandelt Authentifizierung als explizite Anwendungsgrenze, nicht als versteckte Framework-Magie.

Diese Richtlinie definiert die erste API-Schlüssel- und Token-Richtung für Machine-Clients, MCP-Tools und zukünftige Authentifizierungs-Middleware.

## Position

Authentifizierung und Autorisierung sind explizite Middleware-Grenzen.

Die Standardrichtung ist:

- API-Schlüssel sind für Machine-Clients und MCP-Tools.
- Bearer-Tokens sind für Benutzer- oder Service-Authentifizierung, wenn eine Anwendung sie einsetzt.
- Session-Authentifizierung gehört zu Anwendungen, die serverseitige Browser-Sessions benötigen.
- OpenAPI-Sicherheitsschemata sollten nur hinzugefügt werden, wenn entsprechendes Middleware-Verhalten existiert.
- Secrets dürfen niemals committet, geloggt oder durch MCP-Metadaten exponiert werden.

Der erste implementierte Middleware-Pfad ist eine API-Schlüssel-Prüfung für Machine-Client-Endpunkte mit:

```text
X-NENE2-API-Key
```

Der Schlüsselwert wird von `NENE2_MACHINE_API_KEY` geladen, wenn konfiguriert. Für rein öffentliche lokale Entwicklung ungesetzt lassen, und außerhalb des Repositories setzen, wenn geschützte Routen getestet werden.

## API-Schlüssel

API-Schlüssel sind langlebige Anmeldeinformationen für nicht-menschliche Clients.

API-Schlüssel verwenden für:

- lokale MCP-Tools, die lokale HTTP-APIs aufrufen
- Service-zu-Service-Inspektionstools
- Machine-Clients, die stabilen, skalierten Zugriff benötigen

API-Schlüssel sollten haben:

- einen Eigentümer
- eine Umgebung
- eine Scope-Liste
- Erstellungszeitpunkt
- Zeitpunkt der letzten Nutzung, wenn Speicher vorhanden
- Rotations- oder Widerrufspfad

Keine rohen API-Schlüssel in OpenAPI-Beispiele, MCP-Tool-Kataloge, Logs, Screenshots oder versionierte Konfiguration einfügen.

## Bearer-Tokens

Bearer-Tokens sind Anfrage-Anmeldedaten, die im `Authorization`-Header gesendet werden.

Bearer-Tokens verwenden für:

- kurzlebige Benutzer-Tokens
- Service-Tokens mit expliziten Scopes
- zukünftige OAuth- oder First-Party-Token-Flows

Bearer-Tokens sollten auch bei kurzer Lebensdauer als Secrets behandelt werden.

Das Framework sollte kein Token-Format vorschreiben, bevor ein Authentifizierungs-Adapter existiert.

## Scopes

Scopes beschreiben erlaubte Fähigkeiten.

Initiale Scope-Benennung sollte klein und lesbar bleiben:

- `read:system`
- `read:health`
- `read:docs`
- `write:*` erst nachdem Schreib-Tools entworfen wurden
- `admin:*` erst nachdem die Admin-Richtlinie dokumentiert ist

MCP-Tools sollten die minimalen erforderlichen Scopes deklarieren, bevor sie in der Produktion eingesetzt werden.

## Lokale Entwicklung

Lokale Entwicklung darf Platzhalter-Anmeldedaten nur verwenden, wenn sie eindeutig nicht-geheim und als Beispiele dokumentiert sind.

Lokale Tools dürfen:

- öffentliche lokale HTTP-Endpunkte ohne Anmeldedaten aufrufen, wenn der Endpunkt absichtlich öffentlich ist
- nur für Tests generierte API-Schlüssel außerhalb des Repositories verwenden
- erforderliche Umgebungsvariablennamen ohne Werte dokumentieren

Lokale Tools dürfen nicht:

- `.env`-Werte über MCP-Tools lesen
- Anmeldedaten in der Befehlsausgabe ausgeben
- auf den privaten Anmeldedatenspeicher des Entwicklers angewiesen sein
- Authentifizierung auf eine Art umgehen, die dem Produktionsverhalten ähnelt

## Produktionserwartungen

Produktions-Authentifizierung erfordert explizites Design vor der Implementierung.

Vor dem Aktivieren von Produktions-Anmeldedaten dokumentieren:

- Anmeldedatentyp
- Eigentümer und Rotationsprozess
- erlaubte Umgebungen
- erforderliche Scopes
- Speicher-Backend
- Audit-Felder
- Fehlerverhalten bei fehlenden, ungültigen, abgelaufenen oder unzureichenden Anmeldedaten

Anmeldedaten-Validierungsfehler sollten Problem-Details-Antworten verwenden und nicht preisgeben, ob ein Geheimniswert existiert.

## Logging und Beobachtbarkeit

Logs dürfen enthalten:

- Request-ID
- Anmeldedatentyp
- Anmeldedaten-Eigentümer-ID wenn sicher
- Scope-Namen
- Authentifizierungsergebnis
- Fehlerkategorie

Logs dürfen nicht enthalten:

- rohe API-Schlüssel
- Bearer-Tokens
- Cookies
- Autorisierungs-Header
- Anmeldedaten-Hashes

## OpenAPI und MCP

OpenAPI-Sicherheitsschemata sollten der implementierten Middleware entsprechen.

Wenn hinzugefügt, sollte OpenAPI beschreiben:

- Anmeldedaten-Ort
- erforderliche Scopes
- `401`- und `403`-Problem-Details-Antworten
- Beispiele ohne echte Secrets

MCP-Metadaten sollten erforderliche Scopes referenzieren, keine rohen Anmeldedaten.

Schreib-, Admin- und destruktive MCP-Tools erfordern Authentifizierung, Autorisierung, Audit, Request-ID-Weitergabe und Bestätigungsverhalten vor der Implementierung.
