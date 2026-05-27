# ジオロケーション検索の追加方法

緯度/経度の地点を保存し、近接（nearby 半径）またはバウンディングボックスで検索します。

## スキーマ

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

## ハーバーサイン距離（GeoCalculator）

SQLite には三角関数がないため、バウンディングボックス SQL 事前フィルターの後に PHP で距離を計算します。

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

## 近傍検索（2 パス）

```php
// 1. SQL バウンディングボックス事前フィルター（高速、近似）
$box        = $this->geo->boundingBox($lat, $lng, $radiusKm);
$candidates = $this->repo->findInBoundingBox(
    $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
);

// 2. 正確なハーバーサインフィルター + ソート
$results = [];
foreach ($candidates as $place) {
    $dist = $this->geo->haversineKm($lat, $lng, (float) $place['latitude'], (float) $place['longitude']);
    if ($dist <= $radiusKm) {
        $results[] = array_merge($place, ['distance_km' => round($dist, 4)]);
    }
}
usort($results, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
```

## ルート登録順序

`nearby`/`bbox` がパターンパスの `{id}` として解析されないよう、固定パスを**先に**登録してください:

```php
$router->get('/places/nearby', $this->handleNearby(...));  // 固定 — 先に登録
$router->get('/places/bbox',   $this->handleBbox(...));    // 固定 — 先に登録
$router->get('/places',        $this->handleList(...));
$router->post('/places',       $this->handleCreate(...));
$router->get('/places/{id}',   $this->handleGet(...));     // パターン — 最後に登録
$router->delete('/places/{id}',$this->handleDelete(...));
```

## 座標バリデーション

```php
// 緯度: -90 〜 90、経度: -180 〜 180
if (!is_numeric($rawLat)) { /* 422 */ }
$lat = (float) $rawLat;
if ($lat < -90.0 || $lat > 90.0) { /* 422 */ }
```

## バウンディングボックスバリデーション

クエリ前に反転した範囲を拒否してください:

```php
assert(isset($vals['min_lat'], $vals['max_lat'], $vals['min_lng'], $vals['max_lng']));
if ($vals['min_lat'] > $vals['max_lat']) { throw new ValidationException([...]); }
if ($vals['min_lng'] > $vals['max_lng']) { throw new ValidationException([...]); }
```

## セキュリティノート

- すべての座標入力は数値かつ範囲内であることをバリデーション — パラメーター化されたクエリによる SQL インジェクションを防止。
- 半径は `MAX_RADIUS_KM`（20,000 km）にクランプして異常なバウンディングボックスを防止。
- 負の半径はクエリ実行前に拒否（422）。
- NaN / 数値でない文字列は `is_numeric()` チェックで拒否。
