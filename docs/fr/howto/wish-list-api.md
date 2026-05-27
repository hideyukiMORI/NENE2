# How-to : API de liste de souhaits (Évaluation de sécurité VULN-A~L)

Ce guide démontre une API de liste de souhaits personnelle avec CRUD complet, override admin et durcissement de sécurité couvrant VULN-A à VULN-L.

## Vue d'ensemble du pattern

- Les utilisateurs gèrent des listes de souhaits privées via `POST /wishes`, `GET /wishes/{id}`, `PATCH /wishes/{id}`, `DELETE /wishes/{id}`.
- `GET /users/{userId}/wishes` liste les souhaits d'un utilisateur (propriétaire ou admin uniquement).
- IDOR : les non-propriétaires reçoivent toujours 404 (pas 403) pour éviter de révéler l'existence des ressources.
- Les admins identifiés par l'en-tête `X-Admin-Key` ; fail-closed quand la clé est vide.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A : Injection SQL

Toutes les requêtes utilisent des instructions préparées PDO avec des espaces réservés nommés. Le titre `'; DROP TABLE wishes; --` est stocké verbatim sans dommage :

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B : Mass Assignment

Le handler `update()` maintient une liste d'autorisation explicite des champs. Les champs comme `user_id`, `created_at` ou `id` envoyés par un client sont silencieusement ignorés :

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C : IDOR

Les lectures et suppressions par les non-propriétaires retournent 404 (pas 403) pour cacher l'existence des ressources :

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

L'endpoint de liste cache également les listes des autres utilisateurs :

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D : Admin Fail-Closed

Un `adminKey` vide n'accorde jamais de privilèges admin. Sans cette garde, un déploiement non configuré traiterait chaque en-tête `X-Admin-Key: ` comme valide :

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

Les paramètres de chemin ID sont validés avec `ctype_digit()` au lieu de patterns regex qui pourraient être sujets au ReDoS :

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I : Valeurs négatives

La priorité doit être comprise entre 0 et 100. Les valeurs négatives et les valeurs supérieures à 100 retournent 422 :

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J : Confusion de type JSON

`is_int()` rejette les nombres encodés en chaîne (`"5"`) et les flottants (`1.5`) pour le champ `priority`. `is_bool()` rejette les entiers `1`/`0` pour `fulfilled` :

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## Routes

```
POST   /wishes                 Créer un souhait (X-User-Id requis)
GET    /wishes/{id}            Obtenir un souhait par ID (propriétaire ou admin)
PATCH  /wishes/{id}            Mettre à jour les champs du souhait (propriétaire uniquement)
DELETE /wishes/{id}            Supprimer le souhait (propriétaire ou admin)
GET    /users/{userId}/wishes  Lister les souhaits d'un utilisateur (propriétaire ou admin)
```

## Résumé de validation

| Champ | Règle |
|---|---|
| `X-User-Id` | Requis pour POST/PATCH ; `ctype_digit`, >0 |
| `title` | Non vide, max 200 caractères |
| `url` | Optionnel, max 500 caractères |
| `priority` | Entier 0–100 (pas chaîne/flottant) ; par défaut 0 |
| `fulfilled` | Booléen uniquement (pas 1/0) sur PATCH |
| Paramètre `{id}` | `ctype_digit`, max 18 caractères, >0 ; sinon 404 |

## Voir aussi

- Source FT207 : `../NENE2-FT/wishlistlog/`
- Lié : `docs/howto/booking-resource.md` (FT201, aussi VULN)
- Lié : `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
