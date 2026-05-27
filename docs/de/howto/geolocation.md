# So fügen Sie Geolocation-Suche hinzu

Latitude/Longitude-Punkte speichern und nach Nähe (nearby radius) oder Bounding Box suchen.

## Schema

```sql
CREATE TABLE places (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    latitude   REAL NOT NULL,
    longitude  REAL NOT NULL,
    category   TEXT NOT NULL DEFAULT 'general',
    created_at TEXT NOT NULL
);
```

## Haversine-Distanz (GeoCalculator)

SQLite hat keine trigonometrischen Funktionen, daher Distanz in PHP nach einem SQL-Bounding-Box-Vorfilter berechnen.

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0;

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_RADIUS_KM * 2.0 * asin(sqrt($a));
    }

    /** @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float} */
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

    public function clampRadius(float $radiusKm): float
    {
        return min(max($radiusKm, 0.0), self::MAX_RADIUS_KM);
    }
}
```

## Nearby-Suche (Zwei-Pass)

```php
// 1. SQL-Bounding-Box-Vorfilter (schnell, approximativ)
$box        = $this->geo->boundingBox($lat, $lng, $radiusKm);
$candidates = $this->repo->findInBoundingBox(
    $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
);

// 2. Exakter Haversine-Filter + Sortierung
$results = [];
foreach ($candidates as $place) {
    $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
    if ($dist <= $radiusKm) {
        $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
    }
}
usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
```

## Routen-Registrierungsreihenfolge

Feste Pfade **vor** Muster-Pfaden registrieren, damit `nearby`/`bbox` nicht als `{id}` geparst werden:

```php
$router->get('/places/nearby', $this->handleNearby(...));  // fest — zuerst registrieren
$router->get('/places/bbox',   $this->handleBbox(...));    // fest — zuerst registrieren
$router->get('/places',        $this->handleList(...));
$router->post('/places',       $this->handleCreate(...));
$router->get('/places/{id}',   $this->handleGet(...));     // Muster — zuletzt registrieren
$router->delete('/places/{id}',$this->handleDelete(...));
```

## Koordinatenvalidierung

```php
// latitude: -90 bis 90, longitude: -180 bis 180
if (!is_numeric($rawLat)) { /* 422 */ }
$lat = (float) $rawLat;
if ($lat < -90.0 || $lat > 90.0) { /* 422 */ }
```

## Bounding-Box-Validierung

Invertierte Bereiche vor der Abfrage ablehnen:

```php
assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));
if ($vals['min_lat'] > $vals['max_lat']) { throw new ValidationException([...]); }
if ($vals['min_lng'] > $vals['max_lng']) { throw new ValidationException([...]); }
```

## Sicherheitshinweise

- Alle Koordinateneingaben werden als numerisch und im Bereich validiert — verhindert SQL-Injection via parametrisierte Abfragen.
- Radius auf `MAX_RADIUS_KM` (20.000 km) begrenzt, um degenerierte Bounding Boxes zu verhindern.
- Negativer Radius abgelehnt (422) vor der Abfrageausführung.
- NaN / nicht-numerische Strings durch `is_numeric()`-Prüfung abgelehnt.
