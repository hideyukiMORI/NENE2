# Anleitung: Abonnement-/Plan-Verwaltungs-API (VULN-A~L)

Diese Anleitung demonstriert eine Abonnement-Verwaltungs-API, bei der Benutzer Pläne abonnieren, mit Duplikatvermeidung, Kündigung und IDOR-Schutz.

## Musterübersicht

- Seed-Pläne werden beim Schema-Setup eingefügt (`free`, `starter`, `pro`, `annual`).
- Benutzer abonnieren über `POST /subscriptions` mit einer `plan_id`.
- Jedes (Benutzer, Plan)-Paar kann höchstens ein aktives Abonnement haben.
- Kündigung ändert den Status auf `'cancelled'`; gekündigte Abonnements können nicht erneut gekündigt werden.

## Schema

```sql
CREATE TABLE IF NOT EXISTS plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    price_cents INTEGER NOT NULL,
    interval    TEXT    NOT NULL DEFAULT 'monthly'
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE (user_id, plan_id, status)
);
```

## VULN-A: SQL-Injection

Alle Abfragen verwenden PDO Prepared Statements. Plan-Namen und Benutzer-IDs werden niemals interpoliert.

## VULN-C: IDOR

Nicht-Admin-Benutzer können nur auf ihre eigenen Abonnements zugreifen. Der Zugriff auf das Abonnement eines anderen Benutzers gibt 404 zurück (nicht 403):

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Subscription not found.');
}
```

## VULN-D: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

Pfad-IDs verwenden `ctype_digit()` + Längenlimit. Nicht-numerische Pfade (`/subscriptions/abc`) geben sofort 404 zurück.

## VULN-J: Typverwirrung

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id must be a positive integer.');
}
```

String `"2"`, Float `2.5` und Null geben alle 422 zurück.

## Duplikatvermeidung

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

Ein Versuch, einen bereits aktiven Plan zu abonnieren, gibt 409 zurück.

## Kündigungs-Idempotenz

Die `cancel()`-Methode prüft den Status vor dem Update. Ein zweiter Kündigungsversuch bei einem `'cancelled'`-Abonnement gibt `'already_cancelled'` → 409 zurück (nicht 204).

## JOIN für reichhaltige Antwort

Abonnement-Details enthalten Plan-Info via JOIN:

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## Routen

```
GET    /plans                           Verfügbare Pläne auflisten (öffentlich)
POST   /subscriptions                   Einen Plan abonnieren (X-User-Id erforderlich)
GET    /subscriptions/{id}              Abonnement abrufen (Eigentümer oder Admin)
POST   /subscriptions/{id}/cancel       Abonnement kündigen (Eigentümer oder Admin)
GET    /users/{userId}/subscriptions    Benutzerabonnements auflisten (Eigentümer oder Admin)
```

## Siehe auch

- FT213-Quelle: `../NENE2-FT/subscriptionlog/`
- Verwandt: `docs/howto/coupon-redemption.md` (FT204, ebenfalls zustandsbehaftete Benutzer-Limits)
- Verwandt: `docs/howto/wish-list-api.md` (FT207, VULN-Muster)
