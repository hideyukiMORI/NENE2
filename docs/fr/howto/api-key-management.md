# Gestion des clés API

> **Référence FT** : FT266 (`NENE2-FT/apikeylog`) — cycle de vie des clés API : génération, stockage du hash SHA-256, recherche basée sur le préfixe, application de la portée, rotation

Ce guide couvre l'implémentation de la gestion des clés API dans les applications NENE2 : génération de clés, stockage sécurisé, autorisation basée sur la portée, révocation et rotation.

## Principes de conception fondamentaux

1. **Ne jamais stocker les clés brutes** — uniquement les hashes SHA-256 en base de données.
2. **Retourner la clé brute une seule fois** — uniquement lors de la création, jamais après.
3. **Recherche basée sur le préfixe, vérification basée sur le hash** — le préfixe réduit la requête DB ; hash_equals() effectue l'authentification réelle.
4. **Hiérarchie de portée** — admin ⊃ write ⊃ read ; vérifiée par endpoint.
5. **Rotation sécurisée** — créer la nouvelle clé avant de révoquer l'ancienne pour éviter le verrouillage.

## Format de clé

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 chars of base64url(32 random bytes) -----^
|
type prefix (identifiable in logs)
```

`random_bytes(32)` donne 256 bits d'entropie. C'est computationnellement infaisable à attaquer par force brute quelle que soit la vitesse du hash, donc SHA-256 (rapide, à usage unique) est approprié — contrairement aux mots de passe, les clés API ne sont pas attaquables par dictionnaire.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- 16 premiers chars de la clé brute (index de recherche)
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

La colonne `prefix` stocke les **16 premiers caractères de la clé brute** (pas le préfixe de type `nk`). Cela donne ~78 bits de différenciation, rendant chaque préfixe effectivement unique et permettant une recherche d'index O(1).

**Critique** : N'utilisez PAS le préfixe de type (`nk`) comme préfixe de recherche DB. Toutes les clés partagent le même préfixe de type, donc `WHERE prefix = 'nk'` scannerait toute la table — recherche O(n) et canal temporel proportionnel au nombre de clés.

## Génération de clé

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // 16 premiers chars de la clé complète — unique par clé, sûr à indexer
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` est obligatoire. Utiliser `===` ou `==` pour la comparaison de hashes laisse filtrer des informations temporelles : une chaîne hexadécimale de 64 caractères comparée avec `===` se termine à la première différence, révélant combien de caractères de tête correspondent.

## Flux d'authentification

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

L'approche en deux étapes :
1. Recherche d'index par préfixe (requête DB rapide)
2. Vérification `hash_equals()` contre le hash stocké

Retournez le même `null` et `401` pour tous les cas d'échec (non trouvé, mauvais hash, expiré, révoqué) — les appelants ne doivent pas pouvoir les distinguer.

## Hiérarchie de portée

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

Appliquez la portée au niveau de l'endpoint :

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

Retournez `401` pour non authentifié, `403` pour authentifié mais portée insuffisante — ne jamais révéler si la clé existe.

## Filtrage des réponses

La méthode `toArray()` sur `ApiKey` **ne doit pas** inclure `key_hash`. La clé brute n'est disponible que via `ApiKeyCreateResult::toArray()` immédiatement après la création.

```php
// ApiKey::toArray() — sûr à retourner depuis n'importe quel endpoint
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash est intentionnellement absent
    ];
}

// ApiKeyCreateResult::toArray() — endpoint de création uniquement
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## Rotation de clé — ordre sécurisé

**Toujours créer la nouvelle clé avant de révoquer l'ancienne.**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // Créer en premier — si cela échoue, l'ancienne clé reste active (pas de verrouillage)
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // Révoquer après — si cela échoue, les deux clés existent temporairement (récupérable via liste)
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

Révoquer-puis-créer est dangereux : si CREATE échoue après REVOKE, le propriétaire est définitivement verrouillé. L'inverse (créer-puis-révoquer) signifie que le pire cas est deux clés actives temporairement — observable et récupérable.

## Expiration

Stockez `expires_at` comme une chaîne datetime ISO. Vérifiez dans `isActive()` :

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

Le flux d'authentification passe `$now` comme paramètre, rendant la logique testable avec des timestamps fixes.

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker la clé brute en DB | Exposition complète lors d'une violation de DB |
| Utiliser `===` pour la comparaison de hashes | L'attaque temporelle révèle la longueur du préfixe du hash |
| Utiliser le préfixe de type (`nk`) comme index de recherche DB | Scan complet de table O(n) ; canal temporel |
| Retourner `key_hash` dans les réponses de liste/détail | Attaque par dictionnaire hors ligne sur les hashes |
| Révoquer l'ancienne clé avant de créer la nouvelle lors de la rotation | Verrouillage du propriétaire en cas d'erreur DB |
| Retourner des erreurs différentes pour "clé non trouvée" vs "clé expirée" | Oracle pour l'existence de la clé |
| Logger l'en-tête `X-Api-Key` | Fuite de clé dans le stockage des logs |
