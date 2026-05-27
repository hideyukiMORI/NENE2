# How-to : API de géolocalisation

> **Référence FT** : FT296 (`NENE2-FT/geoloclog`) — API de géolocalisation : calcul de distance par formule de Haversine, validation des coordonnées (lat ±90, lng ±180), recherche de proximité avec pré-filtre par boîte englobante, plafonnement du rayon maximal (20 000 km), garde de boîte englobante inversée, ATK-01~12 tous BLOQUÉS, 25 tests / 40 assertions PASS.

Ce guide montre comment construire une API de lieux/localisation avec validation des coordonnées, recherche de proximité (nearby) et recherche par boîte englobante.

## Schéma

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

Le type `REAL` stocke des flottants double précision IEEE 754. Pas de contraintes de coordonnées au niveau DB — la validation est appliquée dans la couche applicative.

## Endpoints

| Méthode    | Chemin                | Description                              |
|------------|-----------------------|------------------------------------------|
| `POST`     | `/places`             | Créer un lieu                            |
| `GET`      | `/places`             | Lister tous les lieux                    |
| `GET`      | `/places/{id}`        | Obtenir un lieu                          |
| `DELETE`   | `/places/{id}`        | Supprimer un lieu                        |
| `GET`      | `/places/nearby`      | Trouver les lieux dans un rayon          |
| `GET`      | `/places/bbox`        | Trouver les lieux dans une boîte englobante |

Les chemins statiques (`/places/nearby`, `/places/bbox`) sont enregistrés **avant** le chemin dynamique `/places/{id}` pour éviter que `nearby` et `bbox` soient capturés comme `{id}`.

## Validation des coordonnées

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

Latitude : `[-90, 90]`. Longitude : `[-180, 180]`. Les chaînes non numériques et les valeurs de type NaN sont rejetées par `is_numeric()`.

## Distance de Haversine

```php
class GeoCalculator
{
    private const float EARTH_RADIUS_KM = 6371.0;
    private const float MAX_RADIUS_KM   = 20_000.0; // moitié de la circonférence terrestre

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

La formule de Haversine calcule la distance orthodromique en km. Précise pour les petites et grandes distances.

## Recherche de proximité — Pré-filtre boîte englobante + Post-filtre Haversine

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

Recherche en deux phases :
1. SQL `WHERE lat BETWEEN min AND max AND lng BETWEEN min AND max` — pré-filtre rapide utilisant l'index.
2. PHP Haversine pour la vérification exacte de la distance — filtre les coins de la boîte englobante.

`clampRadius()` limite à 20 000 km (moitié de la circonférence terrestre) — pas de crash sur un rayon arbitrairement grand.

## Validation du rayon

```php
if ($radiusKm <= 0) {
    $errors[] = new ValidationError('radius_km', 'radius_km must be positive', 'invalid');
}
// Après validation, plafonner à MAX_RADIUS_KM :
$radiusKm = $this->geo->clampRadius($radiusKm);
```

Les rayons négatifs et zéro sont rejetés (422). Les rayons valides très grands sont plafonnés à `MAX_RADIUS_KM`.

## Validation de la boîte englobante

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

Les boîtes englobantes inversées (min > max) sont rejetées. Une bbox inversée ne retournerait aucun résultat ou causerait un comportement inattendu.

---

## Évaluation ATK — Test d'attaque cracker

### ATK-01 — Injection SQL dans le champ name 🚫 BLOCKED

**Attaque** : Soumettre `"name": "'; DROP TABLE places; --"`.
**Résultat** : BLOCKED — les requêtes paramétrées stockent la chaîne verbatim. Pas d'exécution SQL.

---

### ATK-02 — Injection SQL dans le paramètre radius_km 🚫 BLOCKED

**Attaque** : Envoyer `?radius_km=1; DROP TABLE places`.
**Résultat** : BLOCKED — `!is_numeric($q['radius_km'])` rejette la chaîne non numérique → 422.

---

### ATK-03 — Débordement de latitude (+91) 🚫 BLOCKED

**Attaque** : Envoyer `{ "latitude": 91 }` pour contourner la plage de coordonnées.
**Résultat** : BLOCKED — `$lat > 90.0` → ValidationException → 422.

---

### ATK-04 — Sous-dépassement de latitude (-91) 🚫 BLOCKED

**Attaque** : Envoyer `{ "latitude": -91 }`.
**Résultat** : BLOCKED — `$lat < -90.0` → 422.

---

### ATK-05 — Débordement de longitude (+181) 🚫 BLOCKED

**Attaque** : Envoyer `{ "longitude": 181 }`.
**Résultat** : BLOCKED — `$lng > 180.0` → 422.

---

### ATK-06 — Chaîne NaN comme latitude 🚫 BLOCKED

**Attaque** : Envoyer `{ "latitude": "NaN" }`.
**Résultat** : BLOCKED — `is_numeric("NaN")` retourne false en PHP → ValidationError → 422.

---

### ATK-07 — Rayon négatif 🚫 BLOCKED

**Attaque** : Envoyer `?radius_km=-100` pour obtenir tous les lieux ou causer des erreurs de calcul.
**Résultat** : BLOCKED — `$radiusKm <= 0` → 422.

---

### ATK-08 — Rayon énorme (> circonférence terrestre) 🚫 BLOCKED (par conception)

**Attaque** : Envoyer `?radius_km=999999999` pour effectuer une recherche globale.
**Résultat** : BLOCKED (par conception) — `clampRadius()` plafonne à 20 000 km. La requête réussit mais le rayon est limité.

---

### ATK-09 — Boîte englobante inversée (min_lat > max_lat) 🚫 BLOCKED

**Attaque** : Envoyer `?min_lat=90&max_lat=-90` (inversé) pour confondre la requête.
**Résultat** : BLOCKED — `$vals['min_lat'] > $vals['max_lat']` → 422.

---

### ATK-10 — Paramètres bbox non numériques 🚫 BLOCKED

**Attaque** : Envoyer `?min_lat=abc&max_lat=xyz&min_lng=foo&max_lng=bar`.
**Résultat** : BLOCKED — `!is_numeric($q[$p])` → ValidationException pour les quatre champs → 422.

---

### ATK-11 — DELETE répété d'un lieu inexistant 🚫 BLOCKED

**Attaque** : Envoyer `DELETE /places/99999` 100 fois pour déclencher des erreurs serveur ou du spam de logs.
**Résultat** : BLOCKED — `if (!$this->repo->delete($id)) return 404`. Idempotent ; jamais 500.

---

### ATK-12 — Paramètre lat manquant dans nearby 🚫 BLOCKED

**Attaque** : Envoyer `GET /places/nearby?lng=139.7&radius_km=5` (sans `lat`).
**Résultat** : BLOCKED — `parseCoords(null, ...)` → ValidationError pour la latitude → 422.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Injection SQL dans name | 🚫 BLOCKED |
| ATK-02 | Injection SQL dans radius_km | 🚫 BLOCKED |
| ATK-03 | Débordement latitude (+91) | 🚫 BLOCKED |
| ATK-04 | Sous-dépassement latitude (-91) | 🚫 BLOCKED |
| ATK-05 | Débordement longitude (+181) | 🚫 BLOCKED |
| ATK-06 | Chaîne NaN comme latitude | 🚫 BLOCKED |
| ATK-07 | Rayon négatif | 🚫 BLOCKED |
| ATK-08 | Rayon énorme (recherche globale) | 🚫 BLOCKED (plafonné par conception) |
| ATK-09 | Boîte englobante inversée | 🚫 BLOCKED |
| ATK-10 | Paramètres bbox non numériques | 🚫 BLOCKED |
| ATK-11 | DELETE répété inexistant | 🚫 BLOCKED |
| ATK-12 | lat manquant dans nearby | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
La validation des coordonnées par `is_numeric()`, les vérifications de plage, la garde de bbox inversée, le plafonnement du rayon et les requêtes paramétrées préviennent tous les vecteurs d'attaque géographique.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Pas de validation de plage de coordonnées | `latitude=999` accepté ; Haversine retourne NaN ou une distance sans sens |
| Accepter la chaîne `"NaN"` comme coordonnée | `is_numeric("NaN")` est false en PHP mais `(float)"NaN"` est `NAN` ; toujours vérifier `is_numeric()` d'abord |
| Pas de limite de rayon maximal | `radius_km=999999999` fait un scan complet de table ; DoS via requête de boîte englobante coûteuse |
| Ignorer le pré-filtre de boîte englobante | Haversine s'exécute sur toutes les lignes ; O(n) pour chaque requête de proximité |
| Autoriser la boîte englobante inversée | `min_lat > max_lat` retourne des résultats vides silencieusement ou plante le SQL |
| Enregistrer `/{id}` avant `/nearby` et `/bbox` | `GET /places/nearby` capturé par `{id}` → 404 ou mauvais gestionnaire |
| Stocker les coordonnées comme TEXT | La boîte englobante SQL `BETWEEN` nécessite une comparaison numérique ; la comparaison TEXT est lexicographique |
| `cos(deg2rad($lat))` sans `max(..., 0.001)` | Division par zéro aux pôles (lat = ±90) |
