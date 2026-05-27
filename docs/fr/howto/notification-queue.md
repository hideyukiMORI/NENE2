# How-to : API de file de notifications

Ce guide démontre une file de notifications où les admins envoient des notifications ciblées aux utilisateurs, qui peuvent les lister, les lire et les supprimer.

## Vue d'ensemble du pattern

- Les admins envoient des notifications à des utilisateurs spécifiques via `POST /notifications` (admin uniquement).
- Les utilisateurs reçoivent et gèrent leurs propres notifications via `GET`, `POST /read`, `DELETE`.
- `unread_count` est retourné avec chaque réponse de liste.
- `?unread=1` filtre sur les notifications non lues uniquement.
- Le marquage comme lu est idempotent (les notifications déjà lues retournent 200, pas d'erreur).

## Schéma

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## Liste blanche de types

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

Les types inconnus retournent 422. Ne jamais utiliser un champ texte libre pour le type sans validation.

## Marquage comme lu idempotent

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // Déjà lu — idempotent, retourner succès
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## Filtre de non-lus

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

Le paramètre de requête `?unread=1` active ce chemin ; toute autre valeur liste tout.

## IDOR : Scoping par utilisateur

Toutes les opérations de lecture/suppression/liste vérifient `user_id` :

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

Les utilisateurs non-admin ne peuvent pas lire, marquer ou supprimer les notifications des autres utilisateurs.

## Envoi réservé aux admins

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

Le `user_id` cible est spécifié dans le corps de la requête, validé comme `is_int() && >= 1`.

## Résumé des validations

| Champ | Règle |
|-------|-------|
| `user_id` corps | Entier >= 1 (pas string/float) |
| `type` corps | Un de : info, warning, error, success |
| `title` corps | Non-vide, max 200 caractères |
| En-tête `X-User-Id` | Requis pour lecture/suppression ; `ctype_digit`, >0 |
| En-tête `X-Admin-Key` | Requis pour l'envoi ; à défaut fermé quand vide |

## Routes

```
POST   /notifications                  Envoyer une notification (admin uniquement)
GET    /users/{userId}/notifications   Lister les notifications (propriétaire ou admin)
POST   /notifications/{id}/read        Marquer comme lu (propriétaire uniquement)
DELETE /notifications/{id}             Supprimer la notification (propriétaire ou admin)
```

## Voir aussi

- Source FT214 : `../NENE2-FT/notiflog/`
- Connexe : `docs/howto/session-token-management.md` (FT208, pattern de clé admin)
