# How-to : API d'invitation / parrainage

Ce guide montre comment construire un système d'invitation basé sur les tokens avec expiry et utilisation unique avec NENE2.
Pattern démontré par le field trial **invitelog** (FT221).

## Fonctionnalités

- Générer des tokens d'invitation (`bin2hex(random_bytes(16))` = 32 caractères hex minuscules)
- Définir une date d'expiry par invitation (ISO 8601)
- Accepter/utiliser l'invitation (utilisation unique, suivi de l'invité)
- Liste d'invitations scoped par utilisateur (IDOR : seul soi-même peut voir)
- Cycle de vie du statut : `pending → used` (expired détecté lors de l'utilisation)

## Schéma

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/invitations` | Utilisateur | Créer une invitation (retourne le token) |
| `GET` | `/invitations/{token}` | Public (token = secret) | Obtenir le statut de l'invitation |
| `POST` | `/invitations/{token}/use` | Utilisateur | Accepter l'invitation |
| `GET` | `/users/{userId}/invitations` | Utilisateur (soi-même uniquement) | Lister ses propres invitations |

## Génération de token

```php
$token = bin2hex(random_bytes(16)); // 32 caractères hex minuscules, cryptographiquement sécurisé
```

Pattern de token validé dans les paramètres de chemin :

```php
/** Token : 32 caractères hex minuscules (16 octets aléatoires) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## Logique d'utilisation unique

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // Marquer comme utilisé + enregistrer l'invité
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## Protection IDOR

L'endpoint de liste d'invitations applique un accès réservé à soi-même :

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

L'endpoint `GET /invitations/{token}` utilise le token lui-même comme secret — connaître le token donne l'accès. C'est le pattern "token = capacité".

## Patterns de sécurité

- **`bin2hex(random_bytes(16))`** : Token cryptographiquement sécurisé, entropie 128 bits
- **Validation de pattern de token** : `/\A[0-9a-f]{32}\z/` — bloque l'injection SQL, les tokens surdimensionnés
- **`ctype_digit()`** : Validation d'entier sûre contre ReDoS pour les paramètres de chemin d'ID utilisateur
- **Validation d'expiry ISO 8601** : Pattern regex + comparaison lexicographique (UTC)
- **Expiry vérifiée lors de l'utilisation** : Pas pré-filtrée — la recherche de token retourne le résultat, puis l'expiry est vérifiée
