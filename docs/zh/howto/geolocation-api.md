# 操作指南：地理位置 API

> **FT 参考**：FT296（`NENE2-FT/geoloclog`）——地理位置 API：Haversine 距离计算，坐标校验（纬度 ±90，经度 ±180），带边界框预过滤的附近搜索，最大半径限制（20,000 km），倒置边界框防护，ATK-01 至 ATK-12 全部阻断，25 个测试 / 40 个断言全部通过。

本指南展示如何构建支持坐标校验、近距离搜索（附近）和边界框搜索的地点/位置 API。

## 数据库结构

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

`REAL` 类型存储 IEEE 754 双精度浮点数。DB 层面无坐标约束——校验在应用层执行。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/places` | 创建地点 |
| `GET` | `/places` | 列出所有地点 |
| `GET` | `/places/{id}` | 获取地点 |
| `DELETE` | `/places/{id}` | 删除地点 |
| `GET` | `/places/nearby` | 查找半径内的地点 |
| `GET` | `/places/bbox` | 查找边界框内的地点 |

静态路径（`/places/nearby`、`/places/bbox`）必须在动态路径 `/places/{id}` **之前**注册，以防止 `nearby` 和 `bbox` 被捕获为 `{id}`。

## 坐标校验

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

纬度：`[-90, 90]`。经度：`[-180, 180]`。非数字字符串和类 NaN 值被 `is_numeric()` 拒绝。

## Haversine 距离

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // 地球周长的一半

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

Haversine 公式计算大圆距离（单位：km）。对近距离和远距离都准确。

## 附近搜索——边界框预过滤 + Haversine 后过滤

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

两阶段搜索：
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — 利用索引的快速预过滤。
2. PHP Haversine 精确距离检查——过滤掉边界框的角落部分。

`clampRadius()` 限制为 20,000 km（地球周长的一半）——对任意大半径不会崩溃。

## 半径校验

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// 校验后，限制到 MAX_RADIUS_KM：
$radiusKm = $this->geo->clampRadius($radiusKm);
```

负数和零半径被拒绝（422）。非常大但有效的半径会被限制到 `MAX_RADIUS_KM`。

## 边界框校验

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

倒置边界框（min > max）被拒绝。倒置的 bbox 会导致没有结果或意外行为。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — name 字段 SQL 注入 🚫 BLOCKED

**攻击**：提交 `"name": "'; DROP TABLE places; --"`。
**结果**：BLOCKED — 参数化查询原样存储字符串。无 SQL 执行。

---

### ATK-02 — radius_km 参数 SQL 注入 🚫 BLOCKED

**攻击**：发送 `?radius_km=1; DROP TABLE places`。
**结果**：BLOCKED — `!is_numeric($q['radius_km'])` 拒绝非数字字符串 → 422。

---

### ATK-03 — 纬度溢出（+91） 🚫 BLOCKED

**攻击**：发送 `{ "latitude": 91 }` 绕过坐标范围。
**结果**：BLOCKED — `$lat > 90.0` → ValidationException → 422。

---

### ATK-04 — 纬度下溢（-91） 🚫 BLOCKED

**攻击**：发送 `{ "latitude": -91 }`。
**结果**：BLOCKED — `$lat < -90.0` → 422。

---

### ATK-05 — 经度溢出（+181） 🚫 BLOCKED

**攻击**：发送 `{ "longitude": 181 }`。
**结果**：BLOCKED — `$lng > 180.0` → 422。

---

### ATK-06 — 将 NaN 字符串作为纬度 🚫 BLOCKED

**攻击**：发送 `{ "latitude": "NaN" }`。
**结果**：BLOCKED — PHP 中 `is_numeric("NaN")` 返回 false → ValidationError → 422。

---

### ATK-07 — 负半径 🚫 BLOCKED

**攻击**：发送 `?radius_km=-100` 以获取所有地点或导致数学错误。
**结果**：BLOCKED — `$radiusKm <= 0` → 422。

---

### ATK-08 — 超大半径（超过地球周长） 🚫 BLOCKED（按设计）

**攻击**：发送 `?radius_km=999999999` 进行全球搜索。
**结果**：BLOCKED（按设计）— `clampRadius()` 限制到 20,000 km。请求成功但半径被限制。

---

### ATK-09 — 倒置边界框（min_lat > max_lat） 🚫 BLOCKED

**攻击**：发送 `?min_lat=90&max_lat=-90`（倒置）以混淆查询。
**结果**：BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422。

---

### ATK-10 — 非数字边界框参数 🚫 BLOCKED

**攻击**：发送 `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar`。
**结果**：BLOCKED — `!is_numeric($q[$p])` → 四个字段均 ValidationException → 422。

---

### ATK-11 — 重复 DELETE 不存在的地点 🚫 BLOCKED

**攻击**：发送 `DELETE /places/99999` 100 次触发服务器错误或日志垃圾。
**结果**：BLOCKED — `if (!$this->repo->delete($id)) return 404`。幂等；永不 500。

---

### ATK-12 — nearby 中缺少 lat 参数 🚫 BLOCKED

**攻击**：发送 `GET /places/nearby?lng=139.7&radius_km=5`（缺少 `lat`）。
**结果**：BLOCKED — `parseCoords(null, ...)` → 纬度 ValidationError → 422。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | name 字段 SQL 注入 | 🚫 BLOCKED |
| ATK-02 | radius_km 参数 SQL 注入 | 🚫 BLOCKED |
| ATK-03 | 纬度溢出（+91） | 🚫 BLOCKED |
| ATK-04 | 纬度下溢（-91） | 🚫 BLOCKED |
| ATK-05 | 经度溢出（+181） | 🚫 BLOCKED |
| ATK-06 | 将 NaN 字符串作为纬度 | 🚫 BLOCKED |
| ATK-07 | 负半径 | 🚫 BLOCKED |
| ATK-08 | 超大半径（全球搜索） | 🚫 BLOCKED（按设计限制） |
| ATK-09 | 倒置边界框 | 🚫 BLOCKED |
| ATK-10 | 非数字边界框参数 | 🚫 BLOCKED |
| ATK-11 | 重复 DELETE 不存在的地点 | 🚫 BLOCKED |
| ATK-12 | nearby 中缺少 lat | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED**
`is_numeric()` 坐标校验、范围检查、倒置边界框防护、半径限制和参数化查询，共同防御了所有地理位置攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 无坐标范围校验 | 接受 `latitude=999`；Haversine 返回 NaN 或无意义距离 |
| 接受 `"NaN"` 字符串作为坐标 | PHP 中 `is_numeric("NaN")` 为 false，但 `(float)"NaN"` 为 `NAN`；始终先检查 `is_numeric()` |
| 无最大半径限制 | `radius_km=999999999` 运行全表扫描；昂贵边界框查询的 DoS |
| 跳过边界框预过滤 | Haversine 在所有行上运行；每次附近查询 O(n) |
| 允许倒置边界框 | `min_lat > max_lat` 静默返回空结果或导致 SQL 崩溃 |
| 在 `/nearby` 和 `/bbox` 之前注册 `/{id}` | `GET /places/nearby` 被 `{id}` 捕获 → 404 或错误处理程序 |
| 将坐标存储为 TEXT | 边界框 SQL `BETWEEN` 需要数字比较；TEXT 比较是词典序 |
| 无 `max(..., 0.001)` 的 `cos(deg2rad($lat))` | 在极点（lat = ±90）处除以零 |
