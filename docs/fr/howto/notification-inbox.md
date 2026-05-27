# How-to : API de boîte de réception de notifications

> **Référence FT** : FT271 (`NENE2-FT/notificationlog`) — Boîte de réception de notifications : création de notification avec liste blanche de types, protection IDOR par utilisateur (404 pas 403), pattern admin à défaut fermé, marquage en masse comme lu, idempotence de is_read, limitation de pagination avec liaison PDO::PARAM_INT, 31 tests / 98 assertions PASS.
>
> Également validé dans FT222 (`NENE2-FT/notificationlog`) — évaluation VULN sur le même pattern.

Ce guide montre comment construire un système de boîte de réception de notifications avec des notifications push à liste blanche de types, une protection IDOR par utilisateur, et un marquage en masse comme lu avec NENE2.

## Fonctionnalités

- Création de notification réservée aux admins avec liste blanche de types
- Protection IDOR par utilisateur : les utilisateurs voient uniquement leurs propres notifications (404 en cas d'accès non autorisé)
- Marquage comme lu unitaire et en masse avec vérification de propriété
- Nombre de non-lus retourné à chaque listage
- Filtre de non-lus uniquement optionnel et pagination
- Admin à défaut fermé

## Schéma

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

Pas de table `users` séparée — l'API fait confiance à l'en-tête `X-User-Id` (remplacer par une vraie auth en production).

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/notifications` | Admin | Créer une notification pour un utilisateur |
| `GET` | `/users/{userId}/notifications` | Soi-même / Admin | Lister les notifications |
| `POST` | `/notifications/{id}/read` | Soi-même / Admin | Marquer une notification comme lue |
| `POST` | `/users/{userId}/notifications/read-all` | Soi-même / Admin | Marquer tout comme lu |

## Liste blanche de types

Les chaînes de type en forme libre sont rejetées pour prévenir les attaques d'injection et d'énumération :

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

Le gestionnaire de route valide avant tout accès DB :

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## Protection IDOR

Les utilisateurs peuvent uniquement lire leurs propres notifications. Un 404 (pas 403) est retourné en cas d'accès non autorisé pour prévenir l'énumération des IDs utilisateur :

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

Le marquage comme lu vérifie aussi la propriété avant d'agir :

```php
// Gestionnaire POST /notifications/{id}/read
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR : seul le propriétaire ou l'admin peut marquer comme lu
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## Admin à défaut fermé

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // à défaut fermé : pas d'admin si la clé n'est pas configurée
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## Pagination

`limit` et `offset` sont limités dans le repository — jamais approuvés bruts depuis le client :

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

La liaison entière PDO prévient l'injection SQL dans LIMIT / OFFSET :

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## Idempotence du marquage comme lu

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

Le gestionnaire de route retourne 200 pour `ok` et `already_read` — rendant l'endpoint sûr à appeler plusieurs fois sans effets secondaires.

## Patterns de sécurité

| Pattern | Implémentation |
|---------|----------------|
| **Liste blanche de types** | `in_array($type, ALLOWED_TYPES, true)` — correspondance stricte |
| **IDOR → 404** | Retourner 404 (pas 403) pour cacher l'existence utilisateur/notification |
| **Vérification de propriété** | Récupérer la notification, vérifier `user_id` avant de marquer comme lu |
| **Admin à défaut fermé** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | Validation de l'ID du paramètre de chemin — sûr contre le ReDoS |
| **Limitation de pagination** | `max(1, min(100, $limit))` + liaison `PDO::PARAM_INT` |
| **`is_int()` + `> 0`** | Vérification stricte user_id — rejette les floats, chaînes, négatifs |

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Accepter des chaînes `type` en forme libre | Les types non validés polluent la boîte de réception ; impossible de filtrer par catégories significatives |
| Retourner 403 sur l'accès non autorisé à une notification | Révèle si la notification ou l'utilisateur existe — fuite d'information IDOR |
| Retourner 404 lors du marquage comme lu avant la vérification de propriété | Un attaquant apprend que la notification existe et appartient à quelqu'un |
| Permettre à `adminKey` vide de signifier "admin autorisé" | À défaut ouvert ; toute requête devient admin si aucune clé n'est configurée |
| Faire confiance au `limit` brut de la chaîne de requête | Une requête avec `limit=999999` cause un scan de table complet |
| Utiliser l'interpolation de chaîne dans LIMIT/OFFSET | `"LIMIT {$limit}"` avec entrée non validée permet l'injection SQL |
