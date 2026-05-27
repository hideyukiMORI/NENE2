# How-to: Service-Status-Seiten-API

> **NENE2 Feldversuch 185** — Komponenten-Gesundheitsverfolgung, Incident-Lifecycle-Management,
> Admin-Key-Schutz mit `V::secret()` + `hash_equals()`.

---

## Was dieser Feldversuch beweist

Eine Service-Status-Seiten-API benötigt:
1. **Komponentenstatus-Verfolgung** — operational / degraded / partial_outage / major_outage
2. **Incident-Lifecycle** — investigating → identified → monitoring → resolved
3. **Unveränderlichkeitsschutz** — aufgelöste Incidents können nicht aktualisiert werden (Wiedereröffnung verhindern)
4. **Admin-Key-Schutz** — `V::secret()` erzwingt zeitkonstanten Vergleich für Schreiboperationen
5. **Status-Enum-Durchsetzung** — `V::enum()`-Allowlist verhindert Einschleusung unbekannter Werte

---

## API

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `GET` | `/components` | — | Alle Komponenten auflisten (öffentlich) |
| `POST` | `/components` | X-Admin-Key | Komponente erstellen |
| `PATCH` | `/components/{id}` | X-Admin-Key | Komponentenstatus aktualisieren |
| `GET` | `/incidents` | — | Incidents auflisten (öffentlich, `?open=1` für aktive) |
| `GET` | `/incidents/{id}` | — | Incident-Detail mit Aktualisierungs-Timeline |
| `POST` | `/incidents` | X-Admin-Key | Incident erstellen |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | Incident-Status aktualisieren |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | Aktualisierungsmeldung hinzufügen |

---

## Kernmuster: Admin-Key-Auth mit `V::secret()`

```php
// V::secret() prüft: $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// Verwendung in jedem Schreib-Handler:
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key ist erforderlich.'], 401);
}
```

**Warum `V::secret()` und nicht `=== $key`:**
- `===` ist kurzschlussend: Timing variiert mit der Übereinstimmungslänge → Timing-Oracle
- `hash_equals()` ist zeitkonstant unabhängig davon, wo sich Strings unterscheiden
- Der `$expected !== ''`-Guard verhindert versehentliches Akzeptieren leerer Keys

---

## Status-Enum-Durchsetzung mit `V::enum()`

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// Übergibt Klassenname — gibt typisierte Enum-Instanz oder null zurück

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status muss eines sein von: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum ist bereits das korrekte typisierte Enum — kein ::from() nötig
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**Warum Enum-Durchsetzung wichtig ist:**
- Ohne sie erreichen beliebige Strings die DB
- SQL-`ORDER BY status`-Injektionsvektoren werden blockiert
- Die Allowlist sind die eigenen Cases des Enums — immer synchron

---

## Incident-Lifecycle und Übergangsschutz

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**Übergangsschutz in jedem Schreib-Handler:**
```php
$incident = $this->repository->findIncidentById($id);

// Aufgelöste Incidents sind unveränderlich — versehentliche Wiedereröffnung verhindern
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Aufgelöste Incidents können nicht aktualisiert werden.'],
        409,
    );
}
```

**Warum 409 (Conflict) und nicht 422 (Unprocessable):**
- Die Anfrage ist syntaktisch gültig
- Der Konflikt liegt im aktuellen Zustand der Ressource
- 409 kommuniziert "gültige Anfrage, falscher Zeitpunkt"

---

## Komponentenstatus-Werte

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // alle Systeme funktionieren
    case Degraded      = 'degraded';       // reduzierte Leistung
    case PartialOutage = 'partial_outage'; // einige Funktionen nicht verfügbar
    case MajorOutage   = 'major_outage';   // vollständiger Serviceausfall
}
```

---

## Automatischer `resolved_at`-Zeitstempel

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

Der `resolved_at`-Zeitstempel wird vom Server gesetzt — niemals aus dem Request-Body.

---

## Integer-ID-Parsing (keine Injection)

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit: lehnt Negative, Floats, Strings, Path-Traversal ab
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // lehnt auch null ab
}
```

---

## Filter für offene Incidents

```php
// ?open=1 filtert aufgelöste Incidents heraus
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## Vollständiges Incident-Lifecycle-Beispiel

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Grundursache identifiziert."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Aufgelöste Incidents können nicht aktualisiert werden.
GET /incidents?open=1    → 200 {count: 0}  — aufgelöste nicht mehr angezeigt
```

---

## Testergebnisse

```
46 Tests / 93 Assertions — alle bestanden
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

---

## Wichtige Erkenntnisse

| Muster | Regel |
|---|---|
| Admin-Key-Auth | `V::secret()` — zeitkonstantes `hash_equals()`, schützt leeren Key |
| Enum-Validierung | `V::enum($raw, EnumClass::class)` — gibt typisiertes Enum oder null zurück |
| Übergangsschutz | Aktuellen Zustand vor Änderung prüfen — 409 bei aufgelösten |
| `resolved_at` | Vom Server gesetzter Zeitstempel, niemals aus Request-Body |
| Integer-IDs | `ctype_digit()` + `> 0`-Guard — lehnt Strings, Negative, Null ab |
| Öffentliches Lesen | Keine Auth für GET-Endpunkte — Status-Seiten sollen öffentlich sein |
| Unveränderliche Historie | Incident-Aktualisierungen sind nur-anhängend — kein Bearbeiten/Löschen |

Vollständiges Beispiel: [`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples) im Beispiel-Repository.
