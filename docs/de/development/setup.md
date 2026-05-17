# Lokale Einrichtungsanleitung

Diese Anleitung beschreibt die Einrichtung von NENE2 lokal, von einem frischen Klon bis zu einer laufenden API.

## Voraussetzungen

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (oder Docker Engine + Compose-Plugin)
- Git

Keine lokale Installation von PHP, Node.js oder MySQL erforderlich. Alle Laufzeitabhängigkeiten laufen innerhalb von Docker.

## 1. Klonen und Konfigurieren

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

Öffnen Sie `.env` und passen Sie Werte bei Bedarf an. Die Standardwerte funktionieren für die lokale Entwicklung ohne Änderungen.

Wichtige Umgebungsvariablen:

| Variable | Standard | Zweck |
|---|---|---|
| `APP_ENV` | `local` | Laufzeitumgebung |
| `NENE2_MACHINE_API_KEY` | *(leer)* | Leer lassen, um Machine-Client-Auth in der lokalen Entwicklung zu deaktivieren |
| `DB_ADAPTER` | `mysql` | `sqlite` oder `mysql` |
| `DB_HOST` | `mysql` | Entspricht dem Docker Compose Service-Namen |

## 2. Erstellen und Installieren

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. Backend-Prüfungen ausführen

```bash
docker compose run --rm app composer check
```

Dieser Befehl führt PHPUnit, PHPStan, PHP-CS-Fixer, OpenAPI-Validierung und MCP-Katalog-Validierung sequenziell aus. Bei einem sauberen Klon sollte alles bestehen.

## 4. Webserver starten

```bash
docker compose up -d app
```

Prüfen, ob er läuft:

```bash
curl -i http://localhost:8080/health
```

Erwartete Antwort:

```json
{"status":"ok","service":"NENE2"}
```

Andere nützliche lokale Endpunkte:

| URL | Beschreibung |
|---|---|
| `http://localhost:8080/` | Framework-Informationen |
| `http://localhost:8080/health` | Gesundheitsprüfung |
| `http://localhost:8080/examples/ping` | Ping-Beispiel |
| `http://localhost:8080/examples/notes/{id}` | Notiz nach ID (benötigt DB) |
| `http://localhost:8080/openapi.php` | Raw OpenAPI JSON |
| `http://localhost:8080/docs/` | Swagger UI |

## 5. Server stoppen

```bash
docker compose down
```

## Optional: MySQL-Datenbank einrichten

Die Standard-Testsuite verwendet SQLite im Speicher. Um den MySQL-Adapter zu verifizieren oder Smoke-Tests für Schreiboperationen durchzuführen:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

## Optional: Machine-Client-Authentifizierung

Der Endpunkt `/machine/health` erfordert einen API-Schlüssel. Zum lokalen Testen:

1. `NENE2_MACHINE_API_KEY=local-dev-key` in `.env` setzen.
2. App-Service neustarten: `docker compose up -d app`
3. Den geschützten Endpunkt aufrufen:

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

## Optional: Frontend-Einrichtung

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

## Optional: Lokaler MCP-Server

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Optional: Request-ID in Logs überprüfen

Jede Anfrage erzeugt eine `X-Request-Id`, die im Antwort-Header zurückgegeben und jedem Monolog-Log-Eintrag angehängt wird.

1. App starten: `docker compose up -d app`
2. Anfrage senden:
   ```bash
   curl -i http://localhost:8080/health
   # X-Request-Id in den Antwort-Headern suchen
   ```
3. Strukturierte Log-Ausgabe beobachten:
   ```bash
   docker compose logs app
   # Jede JSON-Zeile enthält "extra":{"request_id":"<id>"}
   ```

Sie können auch Ihre eigene ID angeben:
```bash
curl -i -H 'X-Request-Id: my-trace-id' http://localhost:8080/health
```

## Fehlerbehebung

**`composer check` schlägt bei einem sauberen Klon fehl**
Führen Sie zuerst `docker compose run --rm app composer install` aus. Das `vendor/`-Verzeichnis ist nicht versioniert.

**Port 8080 bereits belegt**
Stoppen Sie was ihn nutzt, oder ändern Sie die Port-Zuordnung in `compose.yaml`:
```yaml
ports:
  - "8081:80"   # stattdessen 8081 verwenden
```

**MySQL-Verbindung bei Migrationen abgelehnt**
Der `mysql`-Container benötigt einige Sekunden zum Starten. Warten Sie einen Moment und wiederholen Sie den Versuch.
