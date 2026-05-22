# How to Add Geolocation Search

Store latitude/longitude points and search by proximity (nearby radius) or bounding box.

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

## Haversine Distance (GeoCalculator)

SQLite has no trigonometric functions, so compute distance in PHP after a bounding-box SQL pre-filter.

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

## Nearby Search (two-pass)

```php
// 1. SQL bounding-box pre-filter (fast, approximate)
$box        = $this->geo->boundingBox($lat, $lng, $radiusKm);
$candidates = $this->repo->findInBoundingBox(
    $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
);

// 2. Exact Haversine filter + sort
$results = [];
foreach ($candidates as $place) {
    $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
    if ($dist <= $radiusKm) {
        $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
    }
}
usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
```

## Route Registration Order

Register fixed paths **before** pattern paths to prevent `nearby`/`bbox` being parsed as `{id}`:

```php
$router->get('/places/nearby', $this->handleNearby(...));  // fixed — register first
$router->get('/places/bbox',   $this->handleBbox(...));    // fixed — register first
$router->get('/places',        $this->handleList(...));
$router->post('/places',       $this->handleCreate(...));
$router->get('/places/{id}',   $this->handleGet(...));     // pattern — register last
$router->delete('/places/{id}',$this->handleDelete(...));
```

## Coordinate Validation

```php
// latitude: -90 to 90, longitude: -180 to 180
if (!is_numeric($rawLat)) { /* 422 */ }
$lat = (float) $rawLat;
if ($lat < -90.0 || $lat > 90.0) { /* 422 */ }
```

## Bounding Box Validation

Reject inverted ranges before querying:

```php
assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));
if ($vals['min_lat'] > $vals['max_lat']) { throw new ValidationException([...]); }
if ($vals['min_lng'] > $vals['max_lng']) { throw new ValidationException([...]); }
```

## Security Notes

- All coordinate inputs validated as numeric and in range — prevents SQL injection via parameterized queries.
- Radius clamped to `MAX_RADIUS_KM` (20,000 km) to prevent degenerate bounding boxes.
- Negative radius rejected (422) before query execution.
- NaN / non-numeric strings rejected by `is_numeric()` check.
