# How-to: A/B-Testing-Framework

> **FT-Referenz**: FT293 (`NENE2-FT/ablog`) — A/B-Experiment-Framework: gewichtete deterministische Variantenzuweisung via crc32-Seed, Zustandsmaschine draft→active→stopped, UNIQUE(experiment_id, user_id) idempotente Zuweisung, CVR-Aggregation in SQL, 16 Tests / 26 Assertions PASS.

Führen Sie kontrollierte Experimente durch, indem Sie Benutzer Varianten zuweisen und Conversion-Events erfassen.

## Schema

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/experiments` | Experiment erstellen (beginnt als `draft`) |
| `GET` | `/experiments` | Alle Experimente auflisten |
| `GET` | `/experiments/{id}` | Experiment + Varianten abrufen |
| `PUT` | `/experiments/{id}/status` | Status wechseln |
| `POST` | `/experiments/{id}/variants` | Variante hinzufügen |
| `POST` | `/experiments/{id}/assign` | Benutzer einer Variante zuweisen (idempotent) |
| `POST` | `/experiments/{id}/events` | Conversion-Event erfassen |
| `GET` | `/experiments/{id}/results` | Aggregierte CVR pro Variante |

## Status-Lebenszyklus

```
draft → active → stopped
```

Ungültige Übergänge mit 422 ablehnen:

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## Deterministische Variantenzuweisung

Benutzer müssen immer in der gleichen Variante landen — verwenden Sie `crc32` für einen reproduzierbaren, zustandslosen Bucket:

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

Die DB speichert die Zuweisung beim ersten Aufruf; nachfolgende Aufrufe geben die gespeicherte Variante zurück — Determinismus + DB-Wahrheit.

## Idempotente Zuweisung

```php
// Bestehende Zuweisung zurückgeben, ohne neu zu würfeln
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200, nicht 201
}
// Erstes Mal: berechnen und speichern
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## Ergebnis-Aggregation (CVR)

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

Anschließend CVR in PHP berechnen:

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## Schutzmaßnahmen

- Nur `active`-Experimente akzeptieren Zuweisungen (andernfalls 409).
- Events erfordern eine Zuweisung des Benutzers (andernfalls 404).
- `UNIQUE(experiment_id, user_id)` verhindert Doppelzuweisungen auf DB-Ebene.
- Gewichte müssen positive Ganzzahlen sein; Varianten mit Nullgewicht werden abgelehnt (422).

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Zufällige (nicht-deterministische) Zuweisung | Gleicher Benutzer erhält bei jedem Aufruf unterschiedliche Varianten; inkonsistente Erfahrung |
| Kein `UNIQUE(experiment_id, user_id)` | Gleichzeitige Zuweisungen erzeugen doppelte Zeilen; Benutzer landet in mehreren Varianten |
| Zuweisung in `draft`- oder `stopped`-Status erlauben | Draft-Experimente haben keine gültigen Varianten; gestoppte Experimente sollten keine neuen Daten sammeln |
| Rückwärts-Statusübergänge erlauben | `stopped → active` öffnet ein abgeschlossenes Experiment wieder; historische Daten werden kontaminiert |
| Keine Gewichtsvalidierung (0 erlauben) | Gesamtgewicht von Null verursacht Division durch Null bei der Bucket-Berechnung |
| CVR in der Anwendung mit allen Zeilen berechnen | Alle Zeilen abrufen und schleifenweise verarbeiten; stattdessen `GROUP BY`-SQL-Aggregation verwenden |
| Keine Event → Zuweisungs-Validierung | Events ohne gültige Zuweisung verzerren die Conversion-Rate pro Variante |
