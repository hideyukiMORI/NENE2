# How-to: Gutschein-Rabattcode-API

> **FT-Referenz**: FT302 (`NENE2-FT/couponlog`) — Gutschein-Rabattcode-API: Nur-Admin-Erstellung mit `X-Admin-Key` (hash_equals), CODE_PATTERN `[A-Z0-9]{4,32}` automatische Normalisierung zu Großbuchstaben, `UNIQUE(coupon_id, user_id)` verhindert doppeltes Einlösen, abgelaufen/erschöpft/Duplikat → 409, 26 Tests / 50 Assertions PASS.

Diese Anleitung zeigt, wie ein Gutscheinsystem aufgebaut wird, bei dem Admins Rabattcodes erstellen und Benutzer diese gegen Nutzungslimits und Ablaufdaten einlösen.

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- in Cent, z.B. 500 = 5,00 €
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

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` verhindert, dass derselbe Benutzer denselben Gutschein zweimal einlöst. Der Index auf `code` beschleunigt die Suche nach Code-String.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/coupons` | `X-Admin-Key` | Gutschein erstellen (nur Admin) |
| `GET` | `/coupons/{code}` | — | Gutscheindetails abrufen |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | Gutschein einlösen |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | Einlösungen auflisten (nur Admin) |

## Admin-Authentifizierung — hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` verhindert Timing-Seitenkanal-Angriffe auf den Schlüsselvergleich. Wenn `adminKey` ein leerer String ist (Fehlkonfiguration), gibt `isAdmin()` false zurück — fail closed.

## Gutscheincode-Format — CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- Nur Großbuchstaben und alphanumerische Zeichen
- 4–32 Zeichen
- `\A` / `\z`-Anker (vollständiger String-Match, kein Teilstring)

Eingabecodes werden vor der Validierung zu Großbuchstaben normalisiert:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // Automatisch generieren, wenn nicht angegeben
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

Ein Benutzer, der `"summer50"` sendet, erhält denselben Gutschein wie `"SUMMER50"` — das System normalisiert automatisch zu Großbuchstaben. `pathCode()` normalisiert auch Pfadparameter zu Großbuchstaben, sodass `GET /coupons/summer50` und `GET /coupons/SUMMER50` denselben Gutschein auflösen.

## Gutscheinerstellungs-Validierung

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount`: strenge `is_int()`-Prüfung — Floats wie `9.99` werden abgelehnt
- `max_uses`: Standardwert `1`, wenn nicht angegeben
- `expires_at`: muss dem ISO-8601-Präfix `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}` entsprechen

## Einlösen — Vier Fehlermodi

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

Alle Geschäftsregelverstoße geben **409 Conflict** zurück (nicht 422). Der `match`-Ausdruck ist erschöpfend — der Default-Zweig wird nur bei einem erfolgreichen `'redeemed'`-String aus dem Repository ausgelöst.

## Benutzer-ID-Validierung

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` — nur reine Ziffern-Strings akzeptiert (kein `-`, `+`, Leerzeichen)
- `strlen > 18` — verhindert Integer-Überlauf bei 64-Bit-PHP (`PHP_INT_MAX` hat 19 Stellen)
- `$id > 0` — Null-ID ist nicht gültig

Gibt `null` zurück → 400 Bad Request, wenn der Header fehlt oder fehlformatiert ist.

## UNIQUE(coupon_id, user_id) — Idempotentes Einlösen

Der DB-Constraint verhindert doppeltes Einlösen auf Storage-Ebene. Die Anwendung prüft auch über das Repository vor dem Einfügen und gibt `'already_redeemed'` zurück, anstatt auf eine DB-Exception zu warten.

Mehrere verschiedene Benutzer können denselben Gutschein einlösen (bis zu `max_uses`). Nur wenn derselbe Benutzer denselben Gutschein zweimal versucht, wird er blockiert.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Einfaches `==` für Admin-Schlüssel-Vergleich | Timing-Angriff enthüllt Schlüssellänge / Teilübereinstimmungen |
| Leerer `adminKey` erlaubt Admin-Zugriff | Fehlkonfigurierter Admin-Schlüssel wird zu offenem Zugriff — fail closed |
| Groß-/Kleinschreibung-sensitiver Code-Lookup | `"summer50"` und `"SUMMER50"` als unterschiedliche Gutscheine behandelt |
| `discount` ohne `is_int()` | Float `9.99` wird akzeptiert; Bruchzahl-Cent korrumpiert das Ledger |
| 422 für abgelaufen/erschöpft | Dies sind Geschäftszustands-Konflikte, keine Validierungsfehler — 409 verwenden |
| Kein `UNIQUE(coupon_id, user_id)` | Race Condition erlaubt gleichzeitiges zweifaches Einlösen desselben Benutzers |
| Keine `max_uses`-Obergrenze | Angreifer erstellt Gutschein mit `max_uses: 999999999` für effektiv unbegrenzten Rabatt |
| `strlen > N` bei Benutzer-ID überspringen | Sehr große Ganzzahl-Strings überlaufen `(int)`-Cast stillschweigend |
| Kein Index auf `code`-Spalte | Vollständiger Tabellen-Scan bei jeder Gutschein-Suche |
| Einlösungsliste an Nicht-Admin zurückgeben | Enthüllt welche Benutzer-IDs eingelöst haben — Datenschutzverletzung |
