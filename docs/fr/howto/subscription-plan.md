# How-to : API de gestion des abonnements / plans (VULN-A~L)

Ce guide démontre une API de gestion d'abonnements où les utilisateurs souscrivent à des plans, avec prévention des doublons, annulation, et protection IDOR.

## Vue d'ensemble du pattern

- Les plans de départ sont insérés au moment du schéma (`free`, `starter`, `pro`, `annual`).
- Les utilisateurs souscrivent via `POST /subscriptions` avec un `plan_id`.
- Chaque paire (utilisateur, plan) peut avoir au maximum un abonnement actif.
- L'annulation change le statut en `'cancelled'` ; les abonnements annulés ne peuvent pas être annulés à nouveau.

## Schéma

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

## VULN-A : Injection SQL

Toutes les requêtes utilisent des requêtes préparées PDO. Les noms de plans et les IDs d'utilisateur ne sont jamais interpolés.

## VULN-C : IDOR

Les utilisateurs non-admin ne peuvent accéder qu'à leurs propres abonnements. Accéder à l'abonnement d'un autre utilisateur retourne 404 (pas 403) :

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Subscription not found.');
}
```

## VULN-D : Admin fail-closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G : ReDoS

Les IDs de chemin utilisent `ctype_digit()` + limite de longueur. Les chemins non numériques (`/subscriptions/abc`) retournent 404 immédiatement.

## VULN-J : Confusion de type

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id must be a positive integer.');
}
```

La chaîne `"2"`, le flottant `2.5`, et zéro retournent tous 422.

## Prévention des doublons

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

Tenter de souscrire à un plan déjà actif retourne 409.

## Idempotence de l'annulation

La méthode `cancel()` vérifie le statut avant la mise à jour. Une deuxième tentative d'annulation sur un abonnement `'cancelled'` retourne `'already_cancelled'` → 409 (pas 204).

## JOIN pour réponse enrichie

Le détail d'abonnement inclut les informations du plan via JOIN :

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## Routes

```
GET    /plans                           Lister les plans disponibles (public)
POST   /subscriptions                   Souscrire à un plan (X-User-Id requis)
GET    /subscriptions/{id}              Obtenir l'abonnement (propriétaire ou admin)
POST   /subscriptions/{id}/cancel       Annuler l'abonnement (propriétaire ou admin)
GET    /users/{userId}/subscriptions    Lister les abonnements de l'utilisateur (propriétaire ou admin)
```

## Voir aussi

- Source FT213 : `../NENE2-FT/subscriptionlog/`
- Connexe : `docs/howto/coupon-redemption.md` (FT204, aussi des limites par utilisateur avec état)
- Connexe : `docs/howto/wish-list-api.md` (FT207, pattern VULN)
