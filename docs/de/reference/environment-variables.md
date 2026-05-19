# Umgebungsvariablen

Alle von NENE2 unterstützten Umgebungsvariablen.
Setzen Sie diese in `.env` (von phpdotenv geladen) oder exportieren Sie sie vor dem Serverstart.

## Anwendung

| Variable | Typ | Standard | Beschreibung |
|---|---|---|---|
| `APP_ENV` | string | `local` | Laufzeitumgebung. Gültige Werte: `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Debug-Ausgabe aktivieren. Nur in der Entwicklung auf `true` setzen. |
| `APP_NAME` | string | `NENE2` | Anwendungsname für Log-Ausgaben. Darf nicht leer sein. |
| `PROBLEM_DETAILS_BASE_URL` | string | `https://nene2.dev/problems/` | Basis-URL, die den `type`-Bezeichnern von Problem Details vorangestellt wird. Bei benutzerdefinierten Problemtypen auf eigener Domain überschreiben. |

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
| `DB_HOST` | string | `127.0.0.1` | Datenbankhost. **Nicht verwendet von SQLite.** |
| `DB_PORT` | integer | `3306` | Datenbankport (1–65535). **Nicht validiert für SQLite.** |
| `DB_NAME` | string | `nene2` | Datenbankname. Bei SQLite: Dateipfad (z. B. `/tmp/myapp.sqlite`). |
| `DB_USER` | string | `nene2` | Datenbankbenutzername. **Nicht verwendet von SQLite.** |
| `DB_PASSWORD` | string | *(leer)* | Datenbankpasswort. |
| `DB_CHARSET` | string | `utf8mb4` | Datenbankzeichensatz. **Nicht verwendet von SQLite.** |


### SQLite-Adapter

Bei `DB_ADAPTER=sqlite` ist nur `DB_NAME` (der Dateipfad) erforderlich. `DB_HOST`, `DB_USER` und `DB_CHARSET` werden nicht validiert und müssen nicht gesetzt werden.

```dotenv
DB_ADAPTER=sqlite
DB_NAME=/tmp/myapp.sqlite
```

Für In-Memory-SQLite (nützlich in Tests) verwenden Sie `DB_NAME=:memory:`.

::: warning Keine Geheimnisse committen
Committen Sie keine `.env`-Dateien mit Passwörtern, API-Schlüsseln oder JWT-Geheimnissen.
:::
