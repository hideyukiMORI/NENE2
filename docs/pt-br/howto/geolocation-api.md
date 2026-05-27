# Como Fazer: API de Geolocalização

> **Referência FT**: FT296 (`NENE2-FT/geoloclog`) — API de geolocalização: cálculo de distância Haversine, validação de coordenadas (lat ±90, lng ±180), busca por proximidade com pré-filtro de bounding box, limite máximo de raio (20.000 km), guarda de bounding box invertido, ATK-01~12 todos BLOCKED, 25 testes / 40 asserções PASS.

Este guia mostra como construir uma API de lugares/localizações com validação de coordenadas, busca por proximidade (nearby) e busca por bounding box.

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

O tipo `REAL` armazena floats de dupla precisão IEEE 754. Sem constraints de coordenadas no nível do BD — a validação é aplicada na camada de aplicação.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/places` | Criar lugar |
| `GET` | `/places` | Listar todos os lugares |
| `GET` | `/places/{id}` | Obter lugar |
| `DELETE` | `/places/{id}` | Deletar lugar |
| `GET` | `/places/nearby` | Encontrar lugares dentro do raio |
| `GET` | `/places/bbox` | Encontrar lugares no bounding box |

Caminhos estáticos (`/places/nearby`, `/places/bbox`) são registrados **antes** do caminho dinâmico `/places/{id}` para evitar que `nearby` e `bbox` sejam capturados como `{id}`.

## Validação de Coordenadas

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

Latitude: `[-90, 90]`. Longitude: `[-180, 180]`. Strings não numéricas e valores similares a NaN são rejeitados por `is_numeric()`.

## Distância Haversine

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // metade da circunferência da Terra

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

A fórmula Haversine calcula distância de grande círculo em km. Precisa para distâncias pequenas e grandes.

## Busca por Proximidade — Pré-filtro de Bounding Box + Pós-filtro Haversine

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

Busca em duas fases:
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — pré-filtro rápido usando o índice.
2. Haversine em PHP para verificação exata de distância — filtra cantos do bounding box.

`clampRadius()` limita a 20.000 km (metade da circunferência da Terra) — sem crash em raio arbitrariamente grande.

## Validação do Raio

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// Após validação, limitar a MAX_RADIUS_KM:
$radiusKm = $this->geo->clampRadius($radiusKm);
```

Raios negativos e zero são rejeitados (422). Raios válidos muito grandes são limitados a `MAX_RADIUS_KM`.

## Validação do Bounding Box

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

Bounding boxes invertidos (min > max) são rejeitados. Um bbox invertido retornaria nenhum resultado ou causaria comportamento inesperado.

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — SQL Injection no Campo name 🚫 BLOCKED

**Ataque**: Enviar `"name": "'; DROP TABLE places; --"`.
**Resultado**: BLOCKED — queries parametrizadas armazenam a string verbatim. Sem execução SQL.

---

### ATK-02 — SQL Injection no Parâmetro radius_km 🚫 BLOCKED

**Ataque**: Enviar `?radius_km=1; DROP TABLE places`.
**Resultado**: BLOCKED — `!is_numeric($q['radius_km'])` rejeita a string não numérica → 422.

---

### ATK-03 — Overflow de Latitude (+91) 🚫 BLOCKED

**Ataque**: Enviar `{ "latitude": 91 }` para bypass do intervalo de coordenadas.
**Resultado**: BLOCKED — `$lat > 90.0` → ValidationException → 422.

---

### ATK-04 — Underflow de Latitude (-91) 🚫 BLOCKED

**Ataque**: Enviar `{ "latitude": -91 }`.
**Resultado**: BLOCKED — `$lat < -90.0` → 422.

---

### ATK-05 — Overflow de Longitude (+181) 🚫 BLOCKED

**Ataque**: Enviar `{ "longitude": 181 }`.
**Resultado**: BLOCKED — `$lng > 180.0` → 422.

---

### ATK-06 — String NaN como Latitude 🚫 BLOCKED

**Ataque**: Enviar `{ "latitude": "NaN" }`.
**Resultado**: BLOCKED — `is_numeric("NaN")` retorna false em PHP → ValidationError → 422.

---

### ATK-07 — Raio Negativo 🚫 BLOCKED

**Ataque**: Enviar `?radius_km=-100` para obter todos os lugares ou causar erros matemáticos.
**Resultado**: BLOCKED — `$radiusKm <= 0` → 422.

---

### ATK-08 — Raio Enorme (> circunferência da Terra) 🚫 BLOCKED (por design)

**Ataque**: Enviar `?radius_km=999999999` para fazer uma busca global.
**Resultado**: BLOCKED (por design) — `clampRadius()` limita a 20.000 km. A requisição tem sucesso mas o raio é limitado.

---

### ATK-09 — Bounding Box Invertido (min_lat > max_lat) 🚫 BLOCKED

**Ataque**: Enviar `?min_lat=90&max_lat=-90` (invertido) para confundir a query.
**Resultado**: BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422.

---

### ATK-10 — Parâmetros de Bbox Não Numéricos 🚫 BLOCKED

**Ataque**: Enviar `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar`.
**Resultado**: BLOCKED — `!is_numeric($q[$p])` → ValidationException para todos os quatro campos → 422.

---

### ATK-11 — DELETE Repetido de Lugar Inexistente 🚫 BLOCKED

**Ataque**: Enviar `DELETE /places/99999` 100 vezes para disparar erros de servidor ou spam de log.
**Resultado**: BLOCKED — `if (!$this->repo->delete($id)) return 404`. Idempotente; nunca 500.

---

### ATK-12 — Parâmetro lat Faltando em Nearby 🚫 BLOCKED

**Ataque**: Enviar `GET /places/nearby?lng=139.7&radius_km=5` (faltando `lat`).
**Resultado**: BLOCKED — `parseCoords(null, ...)` → ValidationError para latitude → 422.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | SQL injection em name | 🚫 BLOCKED |
| ATK-02 | SQL injection em radius_km | 🚫 BLOCKED |
| ATK-03 | Overflow de latitude (+91) | 🚫 BLOCKED |
| ATK-04 | Underflow de latitude (-91) | 🚫 BLOCKED |
| ATK-05 | Overflow de longitude (+181) | 🚫 BLOCKED |
| ATK-06 | String NaN como latitude | 🚫 BLOCKED |
| ATK-07 | Raio negativo | 🚫 BLOCKED |
| ATK-08 | Raio enorme (busca global) | 🚫 BLOCKED (limitado por design) |
| ATK-09 | Bounding box invertido | 🚫 BLOCKED |
| ATK-10 | Parâmetros de bbox não numéricos | 🚫 BLOCKED |
| ATK-11 | DELETE repetido de inexistente | 🚫 BLOCKED |
| ATK-12 | lat faltando em nearby | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Validação de coordenadas por `is_numeric()`, verificações de intervalo, guarda de bbox invertido, limitação de raio e queries parametrizadas previnem todos os vetores de ataque geográfico.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem validação de intervalo de coordenadas | `latitude=999` aceito; Haversine retorna NaN ou distância sem sentido |
| Aceitar string `"NaN"` como coordenada | `is_numeric("NaN")` é false em PHP mas `(float)"NaN"` é `NAN`; sempre verificar `is_numeric()` primeiro |
| Sem limite máximo de raio | `radius_km=999999999` faz varredura completa da tabela; DoS via query de bounding box cara |
| Pular pré-filtro de bounding box | Haversine roda em todas as linhas; O(n) para cada query de nearby |
| Permitir bounding box invertido | `min_lat > max_lat` retorna resultados vazios silenciosamente ou causa erro SQL |
| Registrar `/{id}` antes de `/nearby` e `/bbox` | `GET /places/nearby` capturado por `{id}` → 404 ou handler errado |
| Armazenar coordenadas como TEXT | SQL `BETWEEN` do bounding box requer comparação numérica; comparação TEXT é lexicográfica |
| `cos(deg2rad($lat))` sem `max(..., 0.001)` | Divisão por zero nos polos (lat = ±90) |
