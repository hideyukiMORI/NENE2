# Umgebungsvariablen

Alle von NENE2 unterstützten Umgebungsvariablen.
Setzen Sie diese in `.env` (von phpdotenv geladen) oder exportieren Sie sie vor dem Serverstart.

## Anwendung

| Variable | Typ | Standard | Beschreibung |
|---|---|---|---|
| `APP_ENV` | string | `local` | Laufzeitumgebung. Gültige Werte: `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Debug-Ausgabe aktivieren. Nur in der Entwicklung auf `true` setzen. |
| `APP_NAME` | string | `NENE2` | Anwendungsname für Log-Ausgaben. Darf nicht leer sein. |

## Authentifizierung

| Variable | Typ | Standard | Beschreibung |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(leer — deaktiviert)* | API-Schlüssel, der im `X-NENE2-API-Key`-Header für Machine-Client-Endpunkte erwartet wird. Leer lassen, um den Machine-Key-Pfad zu deaktivieren. |
| `NENE2_LOCAL_JWT_SECRET` | string | *(leer — deaktiviert)* | HMAC-HS256-Geheimnis zum Schutz der Schreibwerkzeuge des lokalen MCP-Servers. Leer lassen für Lesezugriff ohne Authentifizierung. |

## Lokaler MCP-Server

| Variable | Typ | Standard | Beschreibung |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(erforderlich)* | Basis-URL für API-Proxying durch den MCP-Server (z.B. `http://app`). Erforderlich bei Verwendung von Docker Compose. |

## Datenbank

| Variable | Typ | Standard | Beschreibung |
|---|---|---|---|
| `DATABASE_URL` | string | *(leer — verwendet `DB_*`)* | Vollständige Datenbankverbindungs-URL. Wenn nicht leer, überschreibt alle `DB_*`-Variablen. |
| `DB_ADAPTER` | string | `mysql` | Datenbanktreiber. Gültig: `sqlite`, `mysql`. |
| `DB_HOST` | string | `127.0.0.1` | Datenbankhost. |
| `DB_PORT` | integer | `3306` | Datenbankport (1–65535). |
| `DB_NAME` | string | `nene2` | Datenbankname. |
| `DB_USER` | string | `nene2` | Datenbankbenutzername. |
| `DB_PASSWORD` | string | *(leer)* | Datenbankpasswort. |
| `DB_CHARSET` | string | `utf8mb4` | Datenbankzeichensatz. |

::: warning Keine Geheimnisse committen
Committen Sie keine `.env`-Dateien mit Passwörtern, API-Schlüsseln oder JWT-Geheimnissen.
:::
