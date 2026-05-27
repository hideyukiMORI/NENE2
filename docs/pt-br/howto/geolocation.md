# Como Adicionar Busca por Geolocalização

Armazene pontos de latitude/longitude e faça buscas por proximidade (raio nearby) ou bounding box.

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

## Distância Haversine (GeoCalculator)

O SQLite não possui funções trigonométricas, então calcule a distância em PHP após um pré-filtro SQL de bounding box.

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

## Busca por Proximidade (duas passagens)

```php
// 1. Pré-filtro SQL de bounding box (rápido, aproximado)
$box        = $this->geo->boundingBox($lat, $lng, $radiusKm);
$candidates = $this->repo->findInBoundingBox(
    $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
);

// 2. Filtro Haversine exato + ordenação
$results = [];
foreach ($candidates as $place) {
    $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
    if ($dist <= $radiusKm) {
        $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
    }
}
usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
```

## Ordem de Registro de Rotas

Registre caminhos fixos **antes** de caminhos com padrão para evitar que `nearby`/`bbox` sejam interpretados como `{id}`:

```php
$router->get('/places/nearby', $this->handleNearby(...));  // fixo — registrar primeiro
$router->get('/places/bbox',   $this->handleBbox(...));    // fixo — registrar primeiro
$router->get('/places',        $this->handleList(...));
$router->post('/places',       $this->handleCreate(...));
$router->get('/places/{id}',   $this->handleGet(...));     // padrão — registrar por último
$router->delete('/places/{id}',$this->handleDelete(...));
```

## Validação de Coordenadas

```php
// latitude: -90 a 90, longitude: -180 a 180
if (!is_numeric($rawLat)) { /* 422 */ }
$lat = (float) $rawLat;
if ($lat < -90.0 || $lat > 90.0) { /* 422 */ }
```

## Validação do Bounding Box

Rejeite intervalos invertidos antes de consultar:

```php
assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));
if ($vals['min_lat'] > $vals['max_lat']) { throw new ValidationException([...]); }
if ($vals['min_lng'] > $vals['max_lng']) { throw new ValidationException([...]); }
```

## Notas de Segurança

- Todas as entradas de coordenadas validadas como numéricas e dentro do intervalo — previne SQL injection via queries parametrizadas.
- Raio limitado a `MAX_RADIUS_KM` (20.000 km) para evitar bounding boxes degenerados.
- Raio negativo rejeitado (422) antes da execução da query.
- Strings NaN / não numéricas rejeitadas pela verificação `is_numeric()`.
