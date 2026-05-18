# Health Check hinzufügen

Diese Anleitung zeigt, wie Sie den `GET /health`-Endpunkt mit Abhängigkeits-Health-Checks erweitern,
indem Sie `HealthCheckInterface` verwenden.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung. Falls nicht, beginnen Sie mit dem [Tutorial](../tutorial/first-api.md).

---

## Funktionsweise der Health Checks

`GET /health` gibt immer einen Basis-Payload zurück:

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

Wenn Sie `HealthCheckInterface`-Implementierungen registrieren, fügt der Endpunkt eine `checks`-Map hinzu:

- Alle Checks bestehen → `200 OK`, `"status": "ok"`, jeder Check zeigt `"ok"`
- Ein Check schlägt fehl → `503 Service Unavailable`, `"status": "degraded"`, fehlgeschlagener Check zeigt `"error"`

---

## Schnellstart

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'cache'; }
    public function check(): bool { return $this->ping(); }
    private function ping(): bool { return true; }
}

$psr17 = new Psr17Factory();
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

Gesunde Antwort (200 OK):
```json
{ "service": "NENE2", "status": "ok", "timestamp": "...", "checks": { "cache": "ok" } }
```

Degradierte Antwort (503 Service Unavailable):
```json
{ "service": "NENE2", "status": "degraded", "timestamp": "...", "checks": { "cache": "error" } }
```

---

## Die DatabaseHealthCheck-Referenzimplementierung verwenden

`src/Example/Health/DatabaseHealthCheck` ist ein sofort einsatzbereiter Check für PDO-Datenbankverbindungen.
Er ist Teil von `src/Example/` — kopieren und passen Sie ihn für Ihr eigenes Projekt an.

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

> **Hinweis**: `DatabaseHealthCheck` befindet sich in `src/Example/` — es handelt sich um eine Referenzimplementierung,
> keine stabile API-Oberfläche. Kopieren Sie ihn in Ihre Anwendung und passen Sie ihn an Ihre Bedürfnisse an.

---

## Mehrere Health Checks

Übergeben Sie so viele Checks wie nötig. Jeder Fehler degradiert den Gesamtstatus.

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

---

## Ausnahmen in Checks behandeln

Wenn `check()` eine Ausnahme wirft, behandelt das Framework sie als `false` — der Status wird zu `"degraded"`,
der Check zeigt `"error"`. Sie müssen keine Ausnahmen innerhalb von `check()` abfangen.

---

## Nächster Schritt

Siehe [HTTP-Endpunkte](../reference/http-endpoints.md) für das vollständige `/health`-Antwortschema,
oder [Rate-Limiting hinzufügen](./add-rate-limiting.md) für den Schutz der Anfragerate.
