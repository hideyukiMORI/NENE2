# How-to : Système d'invitation

> **Référence FT** : FT283 (`NENE2-FT/invitelog`) — Système de codes d'invitation : token hex 32 caractères (entropie 128 bits), validation datetime ISO 8601, cycle de vie statut pending→used, mapping de statut avec expression match, liste d'invitations protégée IDOR, 23 tests / 47 assertions PASS.

Ce guide montre comment construire un système d'invitation sécurisé — générer des tokens à usage unique qui accordent l'accès lors du rachat.

## Cas d'usage

Un utilisateur crée un lien d'invitation (token) et le partage. Le destinataire rachète le token pour rejoindre. Chaque token est à usage unique et limité dans le temps.

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

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

Points clés :
- `token TEXT UNIQUE` — applique un token par ligne au niveau DB
- `invitee_id` est `NULL` jusqu'au rachat
- `status` — `'pending'` | `'used'`
- `used_at` — défini lors du rachat, fournit un horodatage d'audit

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/invitations` | `X-User-Id` | Créer une invitation |
| `GET` | `/invitations/{token}` | Aucune | Rechercher une invitation par token |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | Racheter une invitation |
| `GET` | `/users/{userId}/invitations` | `X-User-Id` (soi-même seulement) | Lister les invitations de l'utilisateur |

## Génération de token

```php
/** Token : 32 caractères hex minuscules (16 octets aléatoires = entropie 128 bits) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` génère des données aléatoires cryptographiquement sécurisées de 128 bits. La représentation hex fait 32 caractères. C'est le même niveau d'entropie que UUID v4 (122 bits utilisables).

## Validation de expires_at

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

Le regex valide uniquement le format. La gestion du fuseau horaire (UTC vs local) est la responsabilité de l'application — utiliser un fuseau horaire cohérent (ex: UTC) évite les cas limites.

## Cycle de vie du statut

```
pending → used (unidirectionnel, irréversible)
```

Seules les invitations `pending` peuvent être rachetées. Une fois utilisée, l'invitation est définitivement consommée.

## Rachat avec expression match

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` est exhaustif (contrairement à `switch`) : pas de fall-through, doit gérer tous les cas. Le repository retourne un type de résultat chaîne ; le gestionnaire le mappe proprement aux réponses HTTP.

## Repository — Rachat atomique

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // Vérifier l'expiry
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // Marquer comme utilisé
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

La séquence vérification-puis-mise-à-jour est une possible condition de course TOCTOU pour les rachats concurrents du même token. Pour la production, utiliser une transaction DB ou `UPDATE WHERE status = 'pending'` et vérifier les lignes affectées.

## IDOR — Liste d'invitations

Seul l'inviteur peut voir ses propres invitations :

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

Retourne 404 (pas 403) pour masquer si l'utilisateur cible existe.

## Validation de l'en-tête X-User-Id

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

`ctype_digit()` est sûr contre ReDoS ; `strlen > 18` prévient le débordement d'entier PHP sur 64 bits ; `> 0` rejette l'ID utilisateur 0.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Utiliser un token court/séquentiel (PIN à 4 chiffres) | Brute-forceable en millisecondes ; utiliser ≥128 bits aléatoires |
| Stocker le token sans contrainte `UNIQUE` | La collision de token dupliqué cause une confusion de rachat |
| Vérifier `status === 'pending'` avec comparaison laxiste | PHP `'0' == false` ; toujours utiliser `===` strict |
| Pas de validation d'expiry lors du rachat | Les invitations expirées restent rachetables indéfiniment |
| Retourner 403 sur la vérification IDOR de liste d'invitations | Révèle que l'utilisateur cible existe ; utiliser 404 pour masquer l'énumération |
| Rachat atomique sans transaction | Les requêtes concurrentes peuvent toutes deux voir `pending` et toutes deux réussir — double rachat |
| Soft delete (`deleted_at`) au lieu de colonne de statut | La colonne de statut est auto-documentée ; `pending`/`used` est plus clair que null/non-null |
| Accepter n'importe quelle chaîne comme `expires_at` | Injectable en SQL si non paramétrée ; utiliser une requête paramétrée + validation de format |
| Remettre le statut à `pending` pour un token expiré | Permet la réutilisation de tokens qui étaient légitimement expirés |
