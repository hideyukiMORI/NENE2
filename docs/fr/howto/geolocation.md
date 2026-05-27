# Comment ajouter la recherche géolocalisée

Stocker des points latitude/longitude et effectuer des recherches par proximité (rayon nearby) ou boîte englobante.

## Schéma

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

## Distance de Haversine (GeoCalculator)

SQLite n'a pas de fonctions trigonométriques, donc la distance est calculée en PHP après un pré-filtre SQL par boîte englobante.

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

## Recherche de proximité (deux passes)

```php
// 1. Pré-filtre SQL par boîte englobante (rapide, approximatif)
$box        = $this->geo->boundingBox($lat, $lng, $radiusKm);
$candidates = $this->repo->findInBoundingBox(
    $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
);

// 2. Filtre exact Haversine + tri
$results = [];
foreach ($candidates as $place) {
    $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
    if ($dist <= $radiusKm) {
        $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
    }
}
usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
```

## Ordre d'enregistrement des routes

Enregistrer les chemins fixes **avant** les chemins avec paramètres pour éviter que `nearby`/`bbox` soient parsés comme `{id}` :

```php
$router->get('/places/nearby', $this->handleNearby(...));  // fixe — enregistrer en premier
$router->get('/places/bbox',   $this->handleBbox(...));    // fixe — enregistrer en premier
$router->get('/places',        $this->handleList(...));
$router->post('/places',       $this->handleCreate(...));
$router->get('/places/{id}',   $this->handleGet(...));     // pattern — enregistrer en dernier
$router->delete('/places/{id}',$this->handleDelete(...));
```

## Validation des coordonnées

```php
// latitude : -90 à 90, longitude : -180 à 180
if (!is_numeric($rawLat)) { /* 422 */ }
$lat = (float) $rawLat;
if ($lat < -90.0 || $lat > 90.0) { /* 422 */ }
```

## Validation de la boîte englobante

Rejeter les plages inversées avant d'interroger :

```php
assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));
if ($vals['min_lat'] > $vals['max_lat']) { throw new ValidationException([...]); }
if ($vals['min_lng'] > $vals['max_lng']) { throw new ValidationException([...]); }
```

## Notes de sécurité

- Toutes les entrées de coordonnées validées comme numériques et dans la plage — prévient l'injection SQL via les requêtes paramétrées.
- Rayon plafonné à `MAX_RADIUS_KM` (20 000 km) pour éviter les boîtes englobantes dégénérées.
- Rayon négatif rejeté (422) avant l'exécution de la requête.
- Chaînes NaN / non numériques rejetées par la vérification `is_numeric()`.
