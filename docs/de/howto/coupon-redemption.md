# How-to: Gutschein / Rabattcode-Einlösungs-API

Diese Anleitung zeigt, wie mit NENE2 ein Gutschein-Einlösungssystem mit Nutzungslimits und Ablaufdatum aufgebaut wird.
Muster demonstriert durch das **couponlog**-Field Trial (FT218).

## Funktionen

- Gutscheincodes mit Rabattbetrag, Nutzungslimit und Ablauf erstellen (nur Admin)
- Optionale automatische Generierung von Zufallscodes (`bin2hex(random_bytes(6))`)
- Eine Einlösung pro Benutzer pro Gutschein (`UNIQUE(coupon_id, user_id)`)
- Nutzungslimit-Erzwingung (`max_uses`)
- Ablaufprüfung gegen aktuelle UTC-Zeit
- Nur-Admin-Einlösungsliste

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/coupons` | Admin | Gutschein erstellen |
| `GET` | `/coupons/{code}` | Öffentlich | Gutscheininfo abrufen |
| `POST` | `/coupons/{code}/redeem` | Benutzer | Gutschein einlösen |
| `GET` | `/coupons/{code}/redemptions` | Admin | Einlösungen auflisten |

## Code-Validierung

Gutscheincodes verwenden ein striktes Muster zur Injection-Verhinderung:

```php
/** Gutscheincode: Großbuchstaben alphanumerisch, 4–32 Zeichen */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

Pfadparameter wird vor der Validierung zu Großbuchstaben normalisiert:

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## Einlösungslogik

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // Ablauf prüfen
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // Nutzungslimit prüfen
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // Pro-Benutzer-Limit prüfen
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // Aufzeichnen + Zähler inkrementieren
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

Route-Handler verwendet `match`-Ausdruck für saubere Verzweigung:

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## Automatisch generierte Codes

Wenn kein `code` im Request-Body angegeben ist, wird einer generiert:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 Großbuchstaben-Hex-Zeichen
}
```

## Sicherheitsmuster

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` vor `hash_equals()`
- **Code-Muster**: `ctype_digit()`-Äquivalent für Codes — Regex `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`**: Strikter Typcheck für `discount` und `max_uses` — lehnt Floats ab
- **ISO-8601-Ablauf**: Regex-Validierung + lexikografischer Vergleich (UTC-Strings)
- **Atomares Inkrementieren**: `UPDATE SET used_count = used_count + 1` verhindert Race Conditions
- **UNIQUE-Constraint**: Sicherheitsnetz auf DB-Ebene zur Duplikat-Verhinderung
