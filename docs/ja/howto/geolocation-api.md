# ハウツー: ジオロケーション API

> **FT リファレンス**: FT296 (`NENE2-FT/geoloclog`) — ジオロケーション API: ハーバーサイン距離計算、座標バリデーション（緯度 ±90、経度 ±180）、バウンディングボックス事前フィルター付きの近傍検索、最大半径クランプ（20,000 km）、反転バウンディングボックスガード、ATK-01〜12 すべて BLOCKED、25 テスト / 40 アサーション PASS。

このガイドでは、座標バリデーション、近接検索（nearby）、バウンディングボックス検索を備えた場所/ロケーション API の構築方法を示します。

## スキーマ

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

`REAL` 型は IEEE 754 倍精度浮動小数点数を格納します。DB レベルの座標制約はなく — バリデーションはアプリケーション層で強制されます。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/places` | 場所を作成する |
| `GET` | `/places` | すべての場所を一覧表示する |
| `GET` | `/places/{id}` | 場所を取得する |
| `DELETE` | `/places/{id}` | 場所を削除する |
| `GET` | `/places/nearby` | 半径内の場所を検索する |
| `GET` | `/places/bbox` | バウンディングボックス内の場所を検索する |

静的パス（`/places/nearby`、`/places/bbox`）は動的パス `/places/{id}` より**先に**登録してください。`nearby` と `bbox` が `{id}` にキャプチャされるのを防ぐためです。

## 座標バリデーション

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

緯度: `[-90, 90]`。経度: `[-180, 180]`。数値でない文字列と NaN 類似の値は `is_numeric()` で拒否されます。

## ハーバーサイン距離

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // 地球の半周

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

ハーバーサイン公式は大圏距離を km 単位で計算します。小さい距離でも大きい距離でも正確です。

## 近傍検索 — バウンディングボックス事前フィルター + ハーバーサイン後処理フィルター

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

2 段階検索:
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — インデックスを使った高速な事前フィルター。
2. PHP ハーバーサインによる正確な距離チェック — バウンディングボックスの角を除外。

`clampRadius()` は 20,000 km（地球の半周）に制限します — 極端に大きな半径でもクラッシュしません。

## 半径バリデーション

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// バリデーション後、MAX_RADIUS_KM にクランプ:
$radiusKm = $this->geo->clampRadius($radiusKm);
```

負とゼロの半径は拒否されます（422）。非常に大きな有効な半径は `MAX_RADIUS_KM` にクランプされます。

## バウンディングボックスバリデーション

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

反転したバウンディングボックス（min > max）は拒否されます。反転した bbox は結果が返らないか予期しない動作を引き起こします。

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — name フィールドへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `"name": "'; DROP TABLE places; --"` を送信する。
**結果**: BLOCKED — パラメーター化されたクエリが文字列をそのまま保存。SQL は実行されない。

---

### ATK-02 — radius_km パラメーターへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `?radius_km=1; DROP TABLE places` を送信する。
**結果**: BLOCKED — `!is_numeric($q['radius_km'])` が数値でない文字列を拒否 → 422。

---

### ATK-03 — 緯度オーバーフロー（+91） 🚫 BLOCKED

**攻撃**: 座標範囲をバイパスするために `{ "latitude": 91 }` を送信する。
**結果**: BLOCKED — `$lat > 90.0` → ValidationException → 422。

---

### ATK-04 — 緯度アンダーフロー（-91） 🚫 BLOCKED

**攻撃**: `{ "latitude": -91 }` を送信する。
**結果**: BLOCKED — `$lat < -90.0` → 422。

---

### ATK-05 — 経度オーバーフロー（+181） 🚫 BLOCKED

**攻撃**: `{ "longitude": 181 }` を送信する。
**結果**: BLOCKED — `$lng > 180.0` → 422。

---

### ATK-06 — 緯度としての NaN 文字列 🚫 BLOCKED

**攻撃**: `{ "latitude": "NaN" }` を送信する。
**結果**: BLOCKED — PHP の `is_numeric("NaN")` は false を返す → ValidationError → 422。

---

### ATK-07 — 負の半径 🚫 BLOCKED

**攻撃**: すべての場所を取得するか数学的エラーを起こすために `?radius_km=-100` を送信する。
**結果**: BLOCKED — `$radiusKm <= 0` → 422。

---

### ATK-08 — 巨大な半径（地球の周囲を超える） 🚫 BLOCKED（設計上）

**攻撃**: グローバル検索を実行するために `?radius_km=999999999` を送信する。
**結果**: BLOCKED（設計上） — `clampRadius()` が 20,000 km にキャップ。リクエストは成功するが半径は制限される。

---

### ATK-09 — 反転バウンディングボックス（min_lat > max_lat） 🚫 BLOCKED

**攻撃**: クエリを混乱させるために `?min_lat=90&max_lat=-90`（逆順）を送信する。
**結果**: BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422。

---

### ATK-10 — 数値でない bbox パラメーター 🚫 BLOCKED

**攻撃**: `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar` を送信する。
**結果**: BLOCKED — `!is_numeric($q[$p])` → 4 つのフィールドすべてで ValidationException → 422。

---

### ATK-11 — 存在しない場所への DELETE 繰り返し 🚫 BLOCKED

**攻撃**: サーバーエラーやログスパムを発生させるために `DELETE /places/99999` を 100 回送信する。
**結果**: BLOCKED — `if (!$this->repo->delete($id)) return 404`。冪等で 500 にはならない。

---

### ATK-12 — Nearby の lat パラメーター欠如 🚫 BLOCKED

**攻撃**: `GET /places/nearby?lng=139.7&radius_km=5`（`lat` なし）を送信する。
**結果**: BLOCKED — `parseCoords(null, ...)` → 緯度の ValidationError → 422。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | name への SQL インジェクション | 🚫 BLOCKED |
| ATK-02 | radius_km への SQL インジェクション | 🚫 BLOCKED |
| ATK-03 | 緯度オーバーフロー（+91） | 🚫 BLOCKED |
| ATK-04 | 緯度アンダーフロー（-91） | 🚫 BLOCKED |
| ATK-05 | 経度オーバーフロー（+181） | 🚫 BLOCKED |
| ATK-06 | 緯度としての NaN 文字列 | 🚫 BLOCKED |
| ATK-07 | 負の半径 | 🚫 BLOCKED |
| ATK-08 | 巨大な半径（グローバル検索） | 🚫 BLOCKED（設計上クランプ） |
| ATK-09 | 反転バウンディングボックス | 🚫 BLOCKED |
| ATK-10 | 数値でない bbox パラメーター | 🚫 BLOCKED |
| ATK-11 | 存在しない場所への DELETE 繰り返し | 🚫 BLOCKED |
| ATK-12 | Nearby の lat 欠如 | 🚫 BLOCKED |

**12 BLOCKED、0 EXPOSED**
`is_numeric()` による座標バリデーション、範囲チェック、反転 bbox ガード、半径クランプ、パラメーター化されたクエリがすべての地理的攻撃ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 座標範囲バリデーションなし | `latitude=999` が受け付けられる。ハーバーサインが NaN や無意味な距離を返す |
| `"NaN"` 文字列を座標として受け入れる | PHP の `is_numeric("NaN")` は false だが `(float)"NaN"` は `NAN`。常に先に `is_numeric()` を確認すること |
| 最大半径制限なし | `radius_km=999999999` がフルテーブルスキャンを実行。高コストなバウンディングボックスクエリによる DoS |
| バウンディングボックス事前フィルターをスキップする | ハーバーサインがすべての行で実行される。近傍クエリごとに O(n) |
| 反転バウンディングボックスを許可する | `min_lat > max_lat` は無音で空の結果を返すか SQL をクラッシュさせる |
| `/nearby` と `/bbox` より前に `/{id}` を登録する | `GET /places/nearby` が `{id}` にキャプチャされる → 404 または誤ったハンドラー |
| 座標を TEXT として保存する | バウンディングボックス SQL の `BETWEEN` は数値比較が必要。TEXT 比較は辞書的比較になる |
| `max(..., 0.001)` なしで `cos(deg2rad($lat))` を使う | 極（lat = ±90）でゼロ除算が発生する |
