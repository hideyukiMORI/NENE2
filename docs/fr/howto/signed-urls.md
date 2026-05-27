# URLs signées

Les URLs signées fournissent un accès temporaire à une ressource spécifique sans nécessiter que l'appelant s'authentifie avec un compte. Ce pattern est utilisé pour les téléchargements de fichiers, les slots d'upload pré-signés, et tout cas où vous devez partager un accès temporaire avec un tiers.

## Concept de base

Une URL signée contient tout ce qui est nécessaire pour autoriser l'accès : l'ID de ressource, le délai d'expiration, et une signature HMAC qui prouve que l'URL a été générée par un serveur de confiance. Le serveur n'a besoin que de sa clé secrète pour vérifier — aucune recherche en base de données n'est requise.

## Format du token

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

Le HMAC couvre `resource_id|expires_at` ensemble. Modifier l'une ou l'autre partie invalide la signature. Cela lie le token à exactement une ressource et une fenêtre d'expiration.

## Implémentation du Signer

```php
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {}

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $expectedMac = hash_hmac(self::ALGO, $resourceId . '|' . $expiresAt, $this->secret);

        // hash_equals() est obligatoire — utiliser === fuit des informations temporelles
        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }
}
```

`hash_equals()` est non négociable. Une comparaison par égalité de chaîne sort dès la première non-correspondance, fuitant combien de caractères du HMAC correspondent. Un attaquant peut exploiter cela pour forger des signatures octet par octet. `hash_equals()` compare toujours tous les caractères.

## 410 Gone vs 401 Unauthorized pour les tokens expirés

Les utilisateurs bénéficient de savoir si leur lien a expiré (et ils devraient en demander un nouveau) versus si le lien n'a jamais été valide. Le signer vérifie le HMAC en premier, puis l'expiration. Pour les distinguer dans la réponse HTTP :

```php
$resourceId = $this->signer->verify($token, $now);

if ($resourceId === null) {
    // Extraire l'expiration sans vérification HMAC
    $expiresAt = $this->signer->extractExpiresAt($token);
    if ($expiresAt !== null && $expiresAt < $now) {
        return $problems->create($request, 'gone', 'This link has expired.', 410, '');
    }
    return $problems->create($request, 'unauthorized', 'Invalid or expired token.', 401, '');
}
```

`extractExpiresAt()` ne décode que le base64 et divise sur `|` — elle ne vérifie PAS le HMAC. C'est sûr car :
1. L'expiration n'est pas un secret (elle est visible dans l'URL signée de toute façon).
2. Un attaquant ne peut pas forger un token valide avec une expiration manipulée car `verify()` le rejettera.
3. La réponse 410 ne fournit aucune information qui aide à forger des tokens.

Ne PAS exposer des messages d'erreur différents pour "HMAC non correspondant" vs "expiration dépassée" — cela permettrait à un attaquant de construire d'abord des signatures valides pour des valeurs d'expiration arbitraires, puis de les utiliser pour sonder le timing.

## Générer des URLs signées

```php
// POST /files/{id}/sign
$expiresAt = (new \DateTimeImmutable())
    ->add(new \DateInterval("PT{$ttlSeconds}S"))
    ->format('Y-m-d H:i:s');

$token = $this->signer->sign($file->id, $expiresAt);

return $json->create([
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttlSeconds,
    'url'         => '/download?token=' . urlencode($token),
]);
```

Toujours `urlencode()` le token avant de l'insérer dans les URL — les caractères base64url sont sûrs pour les URL mais le rembourrage `=` (si présent) ne l'est pas, et le séparateur `|` dans le payload décodé ne doit pas apparaître dans la forme encodée.

## Gestion de la clé secrète

- Injecter le secret depuis une variable d'environnement — ne jamais le coder en dur.
- Utiliser au moins 32 octets de données aléatoires (`random_bytes(32)` → hex ou base64).
- Pour la rotation des secrets, prendre en charge la vérification contre plusieurs secrets simultanément (essayer chacun jusqu'à ce qu'un réussisse), puis abandonner progressivement l'ancien secret.

```php
// Support multi-secret pendant la rotation
public function verifyWithRotation(string $token, string $now, array $secrets): ?int
{
    foreach ($secrets as $secret) {
        $signer = new HmacSigner($secret);
        $id = $signer->verify($token, $now);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}
```

## URLs signées sans état vs avec état

Ce pattern est **sans état** — le serveur ne suit pas les tokens émis. C'est le principal avantage (pas de recherche DB à chaque téléchargement), mais cela signifie :

- Vous ne pouvez pas révoquer une URL signée avant qu'elle expire.
- Si le secret est pivoté, tous les tokens précédemment émis sont immédiatement invalidés.

Pour les tokens révocables, maintenir une table de liste de blocage (`revoked_tokens`) et la vérifier lors de la vérification. Cela échange le bénéfice sans état contre la révocabilité.

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Utiliser `===` ou `strcmp()` pour la comparaison HMAC | Attaque temporelle — permet de forger des signatures |
| Signer uniquement `resource_id` sans expiration | Les tokens sont permanents — ne peuvent pas expirer |
| Signer uniquement `expires_at` sans resource_id | Un token accorde l'accès à toutes les ressources |
| Utiliser l'expiration pour distinguer "altéré" vs "expiré" | Permet une attaque oracle sur le HMAC |
| Intégrer la clé brute dans le token | Annule le but — le token doit être opaque |
| TTL longs (jours/semaines) | Augmente la fenêtre d'exposition si le token est fuité |
