# Flux de réinitialisation de mot de passe

Implémenter une réinitialisation de mot de passe sécurisée basée sur un token : demande → vérification → complétion.

## Vue d'ensemble

Un flux de réinitialisation de mot de passe comporte trois étapes :
1. L'utilisateur demande une réinitialisation — un token à durée limitée est généré et envoyé (ex. par email).
2. L'utilisateur vérifie que le token est encore valide avant de présenter le formulaire de réinitialisation.
3. L'utilisateur soumet un nouveau mot de passe — le token est consommé et le mot de passe mis à jour.

## Schéma de base de données

```sql
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash` stocke le hash SHA-256 du token brut. Le token brut n'est jamais stocké dans la base de données.

## Génération et stockage du token

Générer le token brut avec `random_bytes`, puis stocker uniquement le hash SHA-256 :

```php
$rawToken  = bin2hex(random_bytes(32)); // 256 bits d'entropie, 64 caractères hex
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// Retourner $rawToken à l'utilisateur (via email ou réponse API)
```

Lors de la vérification, hacher le token entrant de la même façon :

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Stocker un hash signifie qu'une fuite DB n'expose pas les tokens de réinitialisation utilisables — un attaquant devrait inverser SHA-256 sur une valeur aléatoire de 256 bits, ce qui est computationnellement infaisable.

## Prévention de l'énumération d'utilisateurs

`POST /password-reset` doit toujours retourner 202, même pour les adresses email inconnues :

```php
$user = $this->repo->findUserByEmail($email);

// Toujours 202 — ne pas révéler si l'email est enregistré
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... générer un token pour l'utilisateur réel
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Retourner 404 pour les emails inconnus permettrait à un attaquant d'énumérer les comptes enregistrés en sondant les adresses email.

## Usage unique

Définir `used_at` quand la réinitialisation se complète. Rejeter tout token qui a `used_at IS NOT NULL` :

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## Expiration

Appliquer l'expiration à la fois au GET (vérification de statut) et au POST (complétion). Toujours vérifier l'expiration avant de vérifier `isUsed()` :

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — distinct de "non trouvé" (404) et "utilisé" (409)
}
if ($reset->isUsed()) {
    return 409;
}
```

410 (Gone) distingue "expiré" de "utilisé" (409), donnant à l'utilisateur une information actionnable.

## Invalidation des anciens tokens

Quand un utilisateur demande une nouvelle réinitialisation, invalider tous les tokens non utilisés précédents pour cet utilisateur :

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

Sans cela, un utilisateur qui a perdu un email de réinitialisation et en demande un nouveau aurait deux tokens valides en circulation simultanément — les deux pourraient être utilisés pour réinitialiser le mot de passe.

## Assainissement de la réponse

`GET /password-reset/{token}` ne doit pas exposer `user_id` ni `token_hash` dans la réponse :

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

Exposer `user_id` lierait le token de réinitialisation à un ID de compte utilisateur, ce qui est inutile puisque le token lui-même est la credential d'autorisation.

## Propriétés de sécurité

| Propriété | Implémentation |
|-----------|---------------|
| Entropie du token | `bin2hex(random_bytes(32))` — 256 bits |
| Stockage du token | Hash SHA-256 uniquement — token brut jamais en DB |
| Énumération d'utilisateurs | Toujours 202 depuis `POST /password-reset` |
| Expiration | 1 heure ; vérifié au GET et au POST |
| Usage unique | `used_at` défini à la complétion ; 409 à la réutilisation |
| Invalidation des anciens tokens | Tokens non utilisés précédents définis comme utilisés à la nouvelle demande |
| Fuite de réponse | `user_id` et `token_hash` exclus de toutes les réponses |
| Hachage du mot de passe | Argon2id |

## Résumé des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/password-reset` | Demander une réinitialisation (toujours 202) |
| `GET` | `/password-reset/{token}` | Vérifier la validité du token |
| `POST` | `/password-reset/{token}` | Compléter la réinitialisation avec le nouveau mot de passe |
