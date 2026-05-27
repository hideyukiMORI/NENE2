# Gutschein- und Promo-Code-Verwaltung

Implementierungsleitfaden für ein Gutscheinsystem mit Admin-RBAC, benutzerspezifischer Nutzungsverfolgung, Ablaufdatum und Obergrenzenkontrolle.

## Überblick

- Nur die Admin-Rolle kann Gutscheine erstellen, deaktivieren und Nutzungsverläufe einsehen
- Normale Benutzer können jeden Gutschein nur einmal verwenden (`UNIQUE (coupon_id, user_id)`)
- `discount_pct`: Ganzzahl von 1 bis 100 (Validierung erforderlich)
- `max_uses = 0` bedeutet unbegrenzte Nutzung
- `expires_at` ist ein ISO-8601-String (NULL = unbegrenzt)
- user_id wird **ausschließlich aus dem X-User-Id-Header** entnommen (keine Body-Injection)

## Endpunkte

| Methode | Pfad | Beschreibung | Berechtigung |
|---------|------|--------------|--------------|
| `POST` | `/coupons` | Gutschein erstellen | Admin |
| `GET` | `/coupons/{code}` | Gutscheininfo abrufen | Jeder |
| `POST` | `/coupons/{code}/use` | Gutschein verwenden (1 Benutzer 1 Mal) | Authentifiziert |
| `GET` | `/coupons/{code}/uses` | Nutzungsverlauf auflisten | Admin |
| `DELETE` | `/coupons/{code}` | Gutschein deaktivieren | Admin |

## Datenbankdesign

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_pct INTEGER NOT NULL CHECK (discount_pct >= 1 AND discount_pct <= 100),
    max_uses INTEGER NOT NULL DEFAULT 0,
    use_count INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE coupon_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    used_at TEXT NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (coupon_id, user_id)` verhindert die doppelte Nutzung durch denselben Benutzer auf DB-Ebene.

## Admin-Prüfmuster

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}

// Am Anfang von handleCreate / handleDeactivate / handleListUses
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
if (!$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'admin role required'], 403);
}
```

## Gutschein-Nutzungsprüfungs-Reihenfolge

```php
// 1. Authentifizierungsprüfung
if ($actorId === null) { return 401; }

// 2. Gutschein-Existenzprüfung
$coupon = $this->repository->findByCode($code);
if ($coupon === null) { return 404; }

// 3. is_active-Prüfung
if (!(bool) $coupon['is_active']) { return 422 'not active'; }

// 4. Ablaufdatum-Prüfung
$now = date('c');
if ($coupon['expires_at'] !== null && $now > $coupon['expires_at']) { return 422 'expired'; }

// 5. max_uses-Prüfung (0 = unbegrenzt)
if ($maxUses > 0 && $coupon['use_count'] >= $maxUses) { return 422 'limit reached'; }

// 6. Benutzer-Duplikat-Prüfung (App-Layer-Überprüfung des UNIQUE-Constraints)
$existing = $this->repository->findUse($coupon['id'], $actorId);
if ($existing !== null) { return 422 'already used'; }

// 7. Nutzung aufzeichnen + use_count inkrementieren
$this->repository->recordUse($coupon['id'], $actorId, $now);
return 201;
```

## Gutschein-Nutzungsprotokollierung

```php
public function recordUse(int $couponId, int $userId, string $now): int
{
    $id = $this->executor->insert(
        'INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)',
        [$couponId, $userId, $now]
    );
    $this->executor->execute(
        'UPDATE coupons SET use_count = use_count + 1 WHERE id = ?',
        [$couponId]
    );
    return $id;
}
```

Das Inkrementieren von `use_count` erfolgt im selben Prozess wie INSERT.
In MySQL funktioniert `use_count = use_count + 1` bei parallelen Zugriffen atomar.

## discount_pct-Validierung

```php
$discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
    return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
}
```

`CHECK (discount_pct >= 1 AND discount_pct <= 100)` wird auch DB-seitig garantiert,
aber die App-Schicht lehnt zuerst ab und gibt ein angemessenes 422 zurück.

## Beispielantworten

### POST /coupons (Erstellen)
```json
{
  "id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "max_uses": 100,
  "use_count": 0,
  "is_active": true,
  "expires_at": "2026-08-31T23:59:59+00:00",
  "created_by": 1,
  "created_at": "2026-05-21T..."
}
```

### POST /coupons/{code}/use (Verwenden)
```json
{
  "id": 42,
  "coupon_id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "user_id": 7,
  "used_at": "2026-05-21T..."
}
```

## user_id-Injektions-Prävention

user_id muss immer aus dem `X-User-Id`-Header entnommen werden.
Das `user_id`-Feld im Request-Body wird ignoriert.

```php
// Falsch: $userId = (int) $body['user_id'];  // Kann vom Angreifer manipuliert werden
// Richtig:
$actorId = $this->requireUserId($request);  // Nur aus X-User-Id-Header
```
