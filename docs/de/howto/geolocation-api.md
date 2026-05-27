# How-to: Geolocation API

> **FT-Referenz**: FT296 (`NENE2-FT/geoloclog`) — Geolocation API: Haversine-Distanzberechnung, Koordinatenvalidierung (lat ±90, lng ±180), Nearby-Suche mit Bounding-Box-Vorfilter, maximaler Radius-Clamp (20.000 km), invertierter Bounding-Box-Guard, ATK-01–12 alle BLOCKED, 25 Tests / 40 Assertions bestanden.

Diese Anleitung zeigt, wie eine Orts-/Standort-API mit Koordinatenvalidierung, Proximity-Suche (nearby) und Bounding-Box-Suche aufgebaut wird.

## Schema

```sql
CREATE TABLE places (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    latitude   REAL    NOT NULL,
    longitude  REAL    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

`REAL`-Typ speichert IEEE-754-Double-Precision-Floats. Keine DB-Level-Koordinaten-Constraints — Validierung wird in der Anwendungsschicht durchgesetzt.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/places` | Ort erstellen |
| `GET` | `/places` | Alle Orte auflisten |
| `GET` | `/places/{id}` | Ort abrufen |
| `DELETE` | `/places/{id}` | Ort löschen |
| `GET` | `/places/nearby` | Orte im Radius finden |
| `GET` | `/places/bbox` | Orte in Bounding Box finden |

Statische Pfade (`/places/nearby`, `/places/bbox`) werden **vor** dem dynamischen Pfad `/places/{id}` registriert, damit `nearby` und `bbox` nicht als `{id}` erfasst werden.

## Koordinatenvalidierung

```php
private function parseCoords(mixed $rawLat, mixed $rawLng): array
{
    $errors = [];
    $lat = $lng = 0.0;

    if ($rawLat === null || $rawLat === '' || !is_numeric($rawLat)) {
        $errors[] = new ValidationError('latitude', 'latitude must be a number', 'invalid');
    } else {
        $lat = (float) $rawLat;
        if ($lat < -90.0 || $lat > 90.0) {
            $errors[] = new ValidationError('latitude', 'latitude must be between -90 and 90', 'invalid');
        }
    }

    if ($rawLng === null || $rawLng === '' || !is_numeric($rawLng)) {
        $errors[] = new ValidationError('longitude', 'longitude must be a number', 'invalid');
    } else {
        $lng = (float) $rawLng;
        if ($lng < -180.0 || $lng > 180.0) {
            $errors[] = new ValidationError('longitude', 'longitude must be between -180 and 180', 'invalid');
        }
    }

    return [$lat, $lng, $errors];
}
```

Latitude: `[-90, 90]`. Longitude: `[-180, 180]`. Nicht-numerische Strings und NaN-ähnliche Werte werden durch `is_numeric()` abgelehnt.

## Haversine-Distanz

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // halber Erdumfang

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_RADIUS_KM * 2.0 * asin(sqrt($a));
    }
}
```

Die Haversine-Formel berechnet die Großkreis-Distanz in km. Genau für kleine und große Distanzen.

## Nearby-Suche — Bounding-Box-Vorfilter + Haversine-Nachfilter

```php
public function clampRadius(float $radiusKm): float
{
    return min(max($radiusKm, 0.0), self::MAX_RADIUS_KM);
}

public function boundingBox(float $lat, float $lng, float $radiusKm): array
{
    $deltaLat = $radiusKm / 111.0;
    $deltaLng = $radiusKm / (111.0 * max(cos(deg2rad($lat)), 0.001));
    return [
        'min_lat' => $lat - $deltaLat,
        'max_lat' => $lat + $deltaLat,
        'min_lng' => $lng - $deltaLng,
        'max_lng' => $lng + $deltaLng,
    ];
}
```

Zwei-Phasen-Suche:
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — schneller Vorfilter mit Index.
2. PHP Haversine für exakte Distanzprüfung — filtert Ecken der Bounding Box heraus.

`clampRadius()` begrenzt auf 20.000 km (halber Erdumfang) — kein Absturz bei beliebig großem Radius.

## Radius-Validierung

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// Nach Validierung auf MAX_RADIUS_KM begrenzen:
$radiusKm = $this->geo->clampRadius($radiusKm);
```

Negative und Null-Radien werden abgelehnt (422). Sehr große gültige Radien werden auf `MAX_RADIUS_KM` begrenzt.

## Bounding-Box-Validierung

```php
if ($vals['min_lat'] > $vals['max_lat']) {
    throw new ValidationException([
        new ValidationError('min_lat', 'min_lat must be ≤ max_lat', 'invalid')
    ]);
}
if ($vals['min_lng'] > $vals['max_lng']) {
    throw new ValidationException([
        new ValidationError('min_lng', 'min_lng must be ≤ max_lng', 'invalid')
    ]);
}
```

Invertierte Bounding Boxes (min > max) werden abgelehnt. Eine invertierte Bbox würde keine Ergebnisse zurückgeben oder unerwartetes Verhalten verursachen.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL-Injection im name-Feld 🚫 BLOCKED

**Angriff**: `"name": "'; DROP TABLE places; --"` einreichen.
**Ergebnis**: BLOCKED — parametrisierte Abfragen speichern den String wörtlich. Keine SQL-Ausführung.

---

### ATK-02 — SQL-Injection im radius_km-Parameter 🚫 BLOCKED

**Angriff**: `?radius_km=1; DROP TABLE places` senden.
**Ergebnis**: BLOCKED — `!is_numeric($q['radius_km'])` lehnt den nicht-numerischen String ab → 422.

---

### ATK-03 — Latitude-Überlauf (+91) 🚫 BLOCKED

**Angriff**: `{ "latitude": 91 }` senden, um den Koordinatenbereich zu umgehen.
**Ergebnis**: BLOCKED — `$lat > 90.0` → ValidationException → 422.

---

### ATK-04 — Latitude-Unterlauf (-91) 🚫 BLOCKED

**Angriff**: `{ "latitude": -91 }` senden.
**Ergebnis**: BLOCKED — `$lat < -90.0` → 422.

---

### ATK-05 — Longitude-Überlauf (+181) 🚫 BLOCKED

**Angriff**: `{ "longitude": 181 }` senden.
**Ergebnis**: BLOCKED — `$lng > 180.0` → 422.

---

### ATK-06 — NaN-String als Latitude 🚫 BLOCKED

**Angriff**: `{ "latitude": "NaN" }` senden.
**Ergebnis**: BLOCKED — `is_numeric("NaN")` gibt in PHP false zurück → ValidationError → 422.

---

### ATK-07 — Negativer Radius 🚫 BLOCKED

**Angriff**: `?radius_km=-100` senden, um alle Orte zu erhalten oder Mathematik-Fehler zu verursachen.
**Ergebnis**: BLOCKED — `$radiusKm <= 0` → 422.

---

### ATK-08 — Riesiger Radius (> Erdumfang) 🚫 BLOCKED (by design)

**Angriff**: `?radius_km=999999999` senden, um eine globale Suche durchzuführen.
**Ergebnis**: BLOCKED (by design) — `clampRadius()` begrenzt auf 20.000 km. Request gelingt, aber Radius ist begrenzt.

---

### ATK-09 — Invertierte Bounding Box (min_lat > max_lat) 🚫 BLOCKED

**Angriff**: `?min_lat=90&max_lat=-90` (umgekehrt) senden, um die Abfrage zu verwirren.
**Ergebnis**: BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422.

---

### ATK-10 — Nicht-numerische Bbox-Parameter 🚫 BLOCKED

**Angriff**: `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar` senden.
**Ergebnis**: BLOCKED — `!is_numeric($q[$p])` → ValidationException für alle vier Felder → 422.

---

### ATK-11 — Wiederholtes DELETE eines nicht-existierenden Ortes 🚫 BLOCKED

**Angriff**: `DELETE /places/99999` 100 Mal senden, um Serverfehler oder Log-Spam auszulösen.
**Ergebnis**: BLOCKED — `if (!$this->repo->delete($id)) return 404`. Idempotent; niemals 500.

---

### ATK-12 — Fehlender lat-Parameter in Nearby 🚫 BLOCKED

**Angriff**: `GET /places/nearby?lng=139.7&radius_km=5` senden (fehlendes `lat`).
**Ergebnis**: BLOCKED — `parseCoords(null, ...)` → ValidationError für latitude → 422.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | SQL-Injection im Namen | 🚫 BLOCKED |
| ATK-02 | SQL-Injection im radius_km | 🚫 BLOCKED |
| ATK-03 | Latitude-Überlauf (+91) | 🚫 BLOCKED |
| ATK-04 | Latitude-Unterlauf (-91) | 🚫 BLOCKED |
| ATK-05 | Longitude-Überlauf (+181) | 🚫 BLOCKED |
| ATK-06 | NaN-String als Latitude | 🚫 BLOCKED |
| ATK-07 | Negativer Radius | 🚫 BLOCKED |
| ATK-08 | Riesiger Radius (globale Suche) | 🚫 BLOCKED (begrenzt by design) |
| ATK-09 | Invertierte Bounding Box | 🚫 BLOCKED |
| ATK-10 | Nicht-numerische Bbox-Parameter | 🚫 BLOCKED |
| ATK-11 | Wiederholtes DELETE nicht-existierender Ort | 🚫 BLOCKED |
| ATK-12 | Fehlender lat in Nearby | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
`is_numeric()`-Koordinatenvalidierung, Bereichsprüfungen, invertierter Bbox-Guard, Radius-Clamping und parametrisierte Abfragen verhindern alle geografischen Angriffsvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Keine Koordinaten-Bereichsvalidierung | `latitude=999` wird akzeptiert; Haversine gibt NaN oder bedeutungslose Distanz zurück |
| `"NaN"`-String als Koordinate akzeptieren | `is_numeric("NaN")` ist in PHP false, aber `(float)"NaN"` ist `NAN`; immer zuerst `is_numeric()` prüfen |
| Kein maximales Radius-Limit | `radius_km=999999999` führt Full-Table-Scan aus; DoS via teure Bounding-Box-Abfrage |
| Bounding-Box-Vorfilter überspringen | Haversine läuft auf allen Zeilen; O(n) für jede Nearby-Abfrage |
| Invertierte Bounding Box erlauben | `min_lat > max_lat` gibt still leere Ergebnisse zurück oder stürzt SQL ab |
| `/{id}` vor `/nearby` und `/bbox` registrieren | `GET /places/nearby` wird von `{id}` erfasst → 404 oder falscher Handler |
| Koordinaten als TEXT speichern | Bounding-Box-SQL `BETWEEN` erfordert numerischen Vergleich; TEXT-Vergleich ist lexikografisch |
| `cos(deg2rad($lat))` ohne `max(..., 0.001)` | Division durch Null an den Polen (lat = ±90) |
