# Système d'invitation d'utilisateurs

Invitez de nouveaux utilisateurs par email, appliquez l'expiration, et prévenez les abus avec des invitations basées sur des tokens.

## Vue d'ensemble

Un système d'invitation permet aux utilisateurs existants de parrainer la création de nouveaux comptes. Les invariants clés sont :

- Les tokens sont cryptographiquement aléatoires et non devinables.
- L'expiration est vérifiée à la fois en lecture et en écriture.
- Seul l'invitant original peut annuler une invitation.
- Les tokens acceptés et annulés ne peuvent pas être réutilisés.

## Schéma de base de données

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## Génération du token

Toujours utiliser `bin2hex(random_bytes(32))` — 64 caractères hex, 256 bits d'entropie :

```php
$token = bin2hex(random_bytes(32));
```

Ne jamais utiliser des IDs séquentiels, des UUIDs, ou des chaînes courtes comme tokens d'invitation. Un token devinable permet à un attaquant d'accepter n'importe quelle invitation en attente.

## Envoyer une invitation

Avant de créer l'invitation, vérifier que l'email cible n'est pas déjà enregistré :

```php
// Empêcher l'invitation d'utilisateurs déjà enregistrés
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

Retourner 409 lors de l'invitation d'un email enregistré révèle le statut d'enregistrement à l'invitant. C'est acceptable dans les systèmes sur invitation où les invitants sont des utilisateurs de confiance. Dans les systèmes entièrement publics, envisager d'unifier la réponse à 202.

## Accepter une invitation

Vérifier l'expiration **avant** de vérifier le statut — une invitation en attente mais expirée doit retourner 410, pas 409 :

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` compare directement les chaînes de timestamp actuelles — les chaînes datetime SQLite se trient lexicographiquement quand elles sont stockées comme `Y-m-d H:i:s` :

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## Annuler une invitation

La propriété est appliquée en utilisant `inviter_id` depuis le corps de la requête (car il n'y a pas de middleware de session/JWT dans cet exemple minimal). En production, dériver l'acteur depuis un token authentifié à la place :

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

Retourner 403 (pas 404) quand la vérification de propriété échoue — masquer l'existence de l'invitation cacherait le fait que l'attaquant a trouvé un vrai token, mais 403 est la sémantique correcte ici puisque la ressource a été trouvée mais l'action est interdite.

## Machine d'états

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

Une fois qu'une invitation quitte `pending`, aucune transition ultérieure n'est autorisée. Tenter d'accepter une invitation `accepted` ou `cancelled` retourne 409.

## Propriétés de sécurité

| Propriété | Implémentation |
|-----------|----------------|
| Entropie du token | `bin2hex(random_bytes(32))` — 256 bits |
| Unicité du token | Contrainte UNIQUE sur `invitations.token` |
| Expiration à la lecture | Vérifiée dans le handler avant tout écriture |
| Prévention de réutilisation | Garde `isPending()` avant accept/cancel |
| Application du propriétaire | Vérification d'égalité `inviter_id` → 403 |
| Pas de fuite de PII email | Le corps 409 n'expose pas l'email invité |
| Injection SQL | Requêtes PDO paramétrées partout |

## Résumé des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Créer un compte utilisateur |
| `POST` | `/users/{id}/invitations` | Envoyer une invitation |
| `GET` | `/invitations/{token}` | Consulter une invitation |
| `POST` | `/invitations/{token}/accept` | Accepter une invitation |
| `DELETE` | `/invitations/{token}` | Annuler une invitation |
