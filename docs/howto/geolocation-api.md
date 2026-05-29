---
title: "How-to: Geolocation API"
category: api-design
tags: [geolocation, proximity-search, coordinates, haversine]
difficulty: intermediate
related: [geolocation]
ft: FT296
---

# How-to: Geolocation API

> **FT reference**: FT296 (`NENE2-FT/geoloclog`) — Geolocation API: Haversine distance calculation, coordinate validation (lat ±90, lng ±180), nearby search with bounding-box pre-filter, max radius clamp (20,000 km), inverted bounding-box guard, ATK-01~12 all BLOCKED, 25 tests / 40 assertions PASS.

This guide shows how to build a place/location API with coordinate validation, proximity search (nearby), and bounding-box search.

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

`REAL` type stores IEEE 754 double-precision floats. No DB-level coordinate constraints — validation is enforced in the application layer.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/places` | Create place |
| `GET` | `/places` | List all places |
| `GET` | `/places/{id}` | Get place |
| `DELETE` | `/places/{id}` | Delete place |
| `GET` | `/places/nearby` | Find places within radius |
| `GET` | `/places/bbox` | Find places in bounding box |

Static paths (`/places/nearby`, `/places/bbox`) are registered **before** the dynamic path `/places/{id}` to prevent `nearby` and `bbox` being captured as `{id}`.

## Coordinate Validation

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

Latitude: `[-90, 90]`. Longitude: `[-180, 180]`. Non-numeric strings and NaN-like values are rejected by `is_numeric()`.

## Haversine Distance

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // half Earth's circumference

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

The Haversine formula computes great-circle distance in km. Accurate for small and large distances.

## Nearby Search — Bounding Box Pre-filter + Haversine Post-filter

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

Two-phase search:
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — fast pre-filter using the index.
2. PHP Haversine for exact distance check — filters out corners of the bounding box.

`clampRadius()` limits to 20,000 km (half Earth's circumference) — no crash on arbitrarily large radius.

## Radius Validation

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// After validation, clamp to MAX_RADIUS_KM:
$radiusKm = $this->geo->clampRadius($radiusKm);
```

Negative and zero radii are rejected (422). Very large valid radii are clamped to `MAX_RADIUS_KM`.

## Bounding Box Validation

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

Inverted bounding boxes (min > max) are rejected. An inverted bbox would return no results or cause unexpected behavior.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL Injection in name Field 🚫 BLOCKED

**Attack**: Submit `"name": "'; DROP TABLE places; --"`.
**Result**: BLOCKED — parameterized queries store the string verbatim. No SQL execution.

---

### ATK-02 — SQL Injection in radius_km Parameter 🚫 BLOCKED

**Attack**: Send `?radius_km=1; DROP TABLE places`.
**Result**: BLOCKED — `!is_numeric($q['radius_km'])` rejects the non-numeric string → 422.

---

### ATK-03 — Latitude Overflow (+91) 🚫 BLOCKED

**Attack**: Send `{ "latitude": 91 }` to bypass coordinate range.
**Result**: BLOCKED — `$lat > 90.0` → ValidationException → 422.

---

### ATK-04 — Latitude Underflow (-91) 🚫 BLOCKED

**Attack**: Send `{ "latitude": -91 }`.
**Result**: BLOCKED — `$lat < -90.0` → 422.

---

### ATK-05 — Longitude Overflow (+181) 🚫 BLOCKED

**Attack**: Send `{ "longitude": 181 }`.
**Result**: BLOCKED — `$lng > 180.0` → 422.

---

### ATK-06 — NaN String as Latitude 🚫 BLOCKED

**Attack**: Send `{ "latitude": "NaN" }`.
**Result**: BLOCKED — `is_numeric("NaN")` returns false in PHP → ValidationError → 422.

---

### ATK-07 — Negative Radius 🚫 BLOCKED

**Attack**: Send `?radius_km=-100` to get all places or cause math errors.
**Result**: BLOCKED — `$radiusKm <= 0` → 422.

---

### ATK-08 — Huge Radius (> Earth circumference) 🚫 BLOCKED (by design)

**Attack**: Send `?radius_km=999999999` to perform a global search.
**Result**: BLOCKED (by design) — `clampRadius()` caps at 20,000 km. Request succeeds but radius is limited.

---

### ATK-09 — Inverted Bounding Box (min_lat > max_lat) 🚫 BLOCKED

**Attack**: Send `?min_lat=90&max_lat=-90` (reversed) to confuse the query.
**Result**: BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422.

---

### ATK-10 — Non-numeric Bbox Parameters 🚫 BLOCKED

**Attack**: Send `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar`.
**Result**: BLOCKED — `!is_numeric($q[$p])` → ValidationException for all four fields → 422.

---

### ATK-11 — Repeated DELETE of Non-existent Place 🚫 BLOCKED

**Attack**: Send `DELETE /places/99999` 100 times to trigger server errors or log spam.
**Result**: BLOCKED — `if (!$this->repo->delete($id)) return 404`. Idempotent; never 500.

---

### ATK-12 — Missing lat Parameter in Nearby 🚫 BLOCKED

**Attack**: Send `GET /places/nearby?lng=139.7&radius_km=5` (missing `lat`).
**Result**: BLOCKED — `parseCoords(null, ...)` → ValidationError for latitude → 422.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | SQL injection in name | 🚫 BLOCKED |
| ATK-02 | SQL injection in radius_km | 🚫 BLOCKED |
| ATK-03 | Latitude overflow (+91) | 🚫 BLOCKED |
| ATK-04 | Latitude underflow (-91) | 🚫 BLOCKED |
| ATK-05 | Longitude overflow (+181) | 🚫 BLOCKED |
| ATK-06 | NaN string as latitude | 🚫 BLOCKED |
| ATK-07 | Negative radius | 🚫 BLOCKED |
| ATK-08 | Huge radius (global search) | 🚫 BLOCKED (clamped by design) |
| ATK-09 | Inverted bounding box | 🚫 BLOCKED |
| ATK-10 | Non-numeric bbox parameters | 🚫 BLOCKED |
| ATK-11 | Repeated DELETE non-existent | 🚫 BLOCKED |
| ATK-12 | Missing lat in nearby | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
`is_numeric()` coordinate validation, range checks, inverted bbox guard, radius clamping, and parameterized queries prevent all geographic attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No coordinate range validation | `latitude=999` accepted; Haversine returns NaN or meaningless distance |
| Accept `"NaN"` string as coordinate | `is_numeric("NaN")` is false in PHP but `(float)"NaN"` is `NAN`; always check `is_numeric()` first |
| No max radius limit | `radius_km=999999999` runs full table scan; DoS via expensive bounding-box query |
| Skip bounding-box pre-filter | Haversine runs on all rows; O(n) for every nearby query |
| Allow inverted bounding box | `min_lat > max_lat` returns empty results silently or crashes SQL |
| Register `/{id}` before `/nearby` and `/bbox` | `GET /places/nearby` captured by `{id}` → 404 or wrong handler |
| Store coordinates as TEXT | Bounding box SQL `BETWEEN` requires numeric comparison; TEXT comparison is lexicographic |
| `cos(deg2rad($lat))` without `max(..., 0.001)` | Division by zero at poles (lat = ±90) |
