# How-to: Geolocation-API

> **FT-Referenz**: FT296 (`NENE2-FT/geoloclog`) — Geolocation-API: Haversine-Distanzberechnung, Koordinatenvalidierung (Breite ±90, Länge ±180), Umgebungssuche mit Bounding-Box-Vorfilter, maximaler Radius-Clamp (20.000 km), invertierte Bounding-Box-Absicherung, ATK-01~12 alle BLOCKIERT, 25 Tests / 40 Assertions bestanden.

Dieser Leitfaden zeigt, wie eine Ort/Standort-API mit Koordinatenvalidierung, Nähesuche (Nearby) und Bounding-Box-Suche aufgebaut wird.

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

`REAL`-Typ speichert IEEE-754-Double-Precision-Floats. Keine Koordinaten-Constraints auf DB-Ebene — Validierung wird auf Anwendungsebene erzwungen.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/places` | Ort erstellen |
| `GET` | `/places` | Alle Orte auflisten |
| `GET` | `/places/{id}` | Ort abrufen |
| `DELETE` | `/places/{id}` | Ort löschen |
| `GET` | `/places/nearby` | Orte innerhalb eines Radius finden |
| `GET` | `/places/bbox` | Orte in einer Bounding Box finden |

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

Breitengrad: `[-90, 90]`. Längengrad: `[-180, 180]`. Nicht-numerische Strings und NaN-ähnliche Werte werden von `is_numeric()` abgelehnt.

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

Die Haversine-Formel berechnet die Großkreisdistanz in km. Genau für kleine und große Distanzen.

## Umgebungssuche — Bounding-Box-Vorfilter + Haversine-Nachfilter

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

Zweiphasige Suche:
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — schneller Vorfilter mit dem Index.
2. PHP-Haversine für exakte Distanzprüfung — filtert die Ecken der Bounding Box heraus.

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

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — SQL-Injektion im name-Feld 🚫 BLOCKIERT

**Angriff**: `"name": "'; DROP TABLE places; --"` einreichen.
**Ergebnis**: BLOCKIERT — parametrisierte Abfragen speichern den String wörtlich. Keine SQL-Ausführung.

---

### ATK-02 — SQL-Injektion im radius_km-Parameter 🚫 BLOCKIERT

**Angriff**: `?radius_km=1; DROP TABLE places` senden.
**Ergebnis**: BLOCKIERT — `!is_numeric($q['radius_km'])` lehnt den nicht-numerischen String ab → 422.

---

### ATK-03 — Breitengrad-Überlauf (+91) 🚫 BLOCKIERT

**Angriff**: `{ "latitude": 91 }` senden, um Koordinatenbereich zu umgehen.
**Ergebnis**: BLOCKIERT — `$lat > 90.0` → ValidationException → 422.

---

### ATK-04 — Breitengrad-Unterlauf (-91) 🚫 BLOCKIERT

**Angriff**: `{ "latitude": -91 }` senden.
**Ergebnis**: BLOCKIERT — `$lat < -90.0` → 422.

---

### ATK-05 — Längengrad-Überlauf (+181) 🚫 BLOCKIERT

**Angriff**: `{ "longitude": 181 }` senden.
**Ergebnis**: BLOCKIERT — `$lng > 180.0` → 422.

---

### ATK-06 — NaN-String als Breitengrad 🚫 BLOCKIERT

**Angriff**: `{ "latitude": "NaN" }` senden.
**Ergebnis**: BLOCKIERT — `is_numeric("NaN")` gibt in PHP false zurück → ValidationError → 422.

---

### ATK-07 — Negativer Radius 🚫 BLOCKIERT

**Angriff**: `?radius_km=-100` senden, um alle Orte zu erhalten oder Mathematikfehler zu verursachen.
**Ergebnis**: BLOCKIERT — `$radiusKm <= 0` → 422.

---

### ATK-08 — Riesiger Radius (> Erdumfang) 🚫 BLOCKIERT (by Design)

**Angriff**: `?radius_km=999999999` für eine globale Suche senden.
**Ergebnis**: BLOCKIERT (by Design) — `clampRadius()` begrenzt auf 20.000 km. Anfrage gelingt, aber Radius wird begrenzt.

---

### ATK-09 — Invertierte Bounding Box (min_lat > max_lat) 🚫 BLOCKIERT

**Angriff**: `?min_lat=90&max_lat=-90` (vertauscht) senden, um die Abfrage zu verwirren.
**Ergebnis**: BLOCKIERT — `$vals['min_lat'] > $vals['max_lat']` → 422.

---

### ATK-10 — Nicht-numerische Bbox-Parameter 🚫 BLOCKIERT

**Angriff**: `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar` senden.
**Ergebnis**: BLOCKIERT — `!is_numeric($q[$p])` → ValidationException für alle vier Felder → 422.

---

### ATK-11 — Wiederholtes DELETE eines nicht-existierenden Orts 🚫 BLOCKIERT

**Angriff**: `DELETE /places/99999` 100 Mal senden, um Serverfehler oder Log-Spam auszulösen.
**Ergebnis**: BLOCKIERT — `if (!$this->repo->delete($id)) return 404`. Idempotent; niemals 500.

---

### ATK-12 — Fehlender lat-Parameter in Nearby 🚫 BLOCKIERT

**Angriff**: `GET /places/nearby?lng=139.7&radius_km=5` (fehlendes `lat`) senden.
**Ergebnis**: BLOCKIERT — `parseCoords(null, ...)` → ValidationError für Breitengrad → 422.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | SQL-Injektion im Namen | 🚫 BLOCKIERT |
| ATK-02 | SQL-Injektion in radius_km | 🚫 BLOCKIERT |
| ATK-03 | Breitengrad-Überlauf (+91) | 🚫 BLOCKIERT |
| ATK-04 | Breitengrad-Unterlauf (-91) | 🚫 BLOCKIERT |
| ATK-05 | Längengrad-Überlauf (+181) | 🚫 BLOCKIERT |
| ATK-06 | NaN-String als Breitengrad | 🚫 BLOCKIERT |
| ATK-07 | Negativer Radius | 🚫 BLOCKIERT |
| ATK-08 | Riesiger Radius (globale Suche) | 🚫 BLOCKIERT (durch Clamp) |
| ATK-09 | Invertierte Bounding Box | 🚫 BLOCKIERT |
| ATK-10 | Nicht-numerische Bbox-Parameter | 🚫 BLOCKIERT |
| ATK-11 | Wiederholtes DELETE nicht-existierend | 🚫 BLOCKIERT |
| ATK-12 | Fehlender lat in Nearby | 🚫 BLOCKIERT |

**12 BLOCKIERT, 0 EXPONIERT**
`is_numeric()`-Koordinatenvalidierung, Bereichsprüfungen, invertierte Bbox-Absicherung, Radius-Clamp und parametrisierte Abfragen verhindern alle geografischen Angriffsvektoren.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Keine Koordinaten-Bereichsvalidierung | `latitude=999` akzeptiert; Haversine gibt NaN oder sinnlose Distanz zurück |
| `"NaN"`-String als Koordinate akzeptieren | `is_numeric("NaN")` ist false in PHP, aber `(float)"NaN"` ist `NAN`; immer zuerst `is_numeric()` prüfen |
| Kein maximales Radius-Limit | `radius_km=999999999` läuft Full-Table-Scan; DoS über teure Bounding-Box-Abfrage |
| Bounding-Box-Vorfilter weglassen | Haversine läuft auf allen Zeilen; O(n) für jede Umgebungsabfrage |
| Invertierte Bounding Box erlauben | `min_lat > max_lat` gibt lautlos leere Ergebnisse oder Absturz in SQL |
| `/{id}` vor `/nearby` und `/bbox` registrieren | `GET /places/nearby` wird von `{id}` abgefangen → 404 oder falscher Handler |
| Koordinaten als TEXT speichern | Bounding-Box-SQL-`BETWEEN` erfordert numerischen Vergleich; TEXT-Vergleich ist lexikografisch |
| `cos(deg2rad($lat))` ohne `max(..., 0.001)` | Division durch null an Polen (Breite = ±90) |
