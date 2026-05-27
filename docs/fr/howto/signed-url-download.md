# How-to : URL signée pour les téléchargements sécurisés

> **Référence FT** : FT338 (`NENE2-FT/signedlog`) — Génération d'URL signée HMAC-SHA256 avec TTL, détection d'altération (401), expiration (410 Gone), tokens liés à la ressource, et rejet de mauvais secret, 16 tests / 40+ assertions PASS.

Ce guide montre comment générer des URL signées à durée limitée qui permettent le téléchargement non authentifié de fichiers privés — sans exposer des informations d'identification à longue durée de vie.

## Schéma

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/files` | Enregistrer un enregistrement de fichier |
| `POST` | `/files/{id}/sign` | Générer une URL de téléchargement signée |
| `GET` | `/download?token=...` | Télécharger en utilisant le token signé |

## Enregistrer un fichier

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// MIME personnalisé
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// Validation
POST /files  {"owner_id": 1}     → 422  // name required
POST /files  {"name": "f.pdf"}   → 422  // owner_id required
```

## Générer une URL signée

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// TTL par défaut = 3600 (1 heure) si omis
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// Fichier inconnu
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## Télécharger avec un token

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// Token manquant
GET /download
→ 401

// Token altéré (4 derniers caractères modifiés)
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// Token expiré (expires_at dans le passé)
GET /download?token=1|2020-01-01+00:00:00|...valid_hmac...
→ 410 Gone

// Déchets aléatoires
GET /download?token=totally-invalid-garbage
→ 401
```

**410 Gone** (pas 401) pour les tokens expirés : l'URL existait et était valide — elle a simplement expiré. Cela permet aux clients de distinguer "jamais valide" de "autrefois valide, maintenant périmé."

## Format du token — HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // Comparaison à temps constant
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // altéré ou mauvais secret
        }

        // Vérifier l'expiration APRÈS avoir vérifié le HMAC
        if ($expiresAt < $now) {
            return -1;  // expiré — l'appelant retourne 410
        }

        return $fileId;
    }
}
```

**Ordre critique** : toujours vérifier le HMAC avant de contrôler l'expiration. Contrôler l'expiration en premier avec un token invalide permet aux attaquants de sonder le comportement d'expiration.

### Liaison à la ressource

Chaque token encode le `file_id`. Les tokens pour différents fichiers produisent des digest HMAC différents :

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — impossible de réutiliser le token du fichier 1 pour accéder au fichier 2
```

### Mauvais secret

Un token signé avec un secret différent retourne null sur `verify()` :

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC non correspondant
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Utiliser `===` au lieu de `hash_equals()` pour la comparaison HMAC | L'attaque temporelle fuite le HMAC octet par octet |
| Vérifier l'expiration avant de vérifier le HMAC | L'attaquant sonde l'expiration sur des tokens forgés pour apprendre l'horloge serveur |
| Inclure uniquement user_id dans le payload du token, pas file_id | Le token pour l'utilisateur 1 fichier 1 réutilisable pour accéder à l'utilisateur 1 fichier 2 |
| Utiliser `md5()` ou `sha1()` au lieu de HMAC-SHA256 | Hash avec clé requis ; le hash sans clé est trivialement falsifiable |
| Retourner 401 pour les tokens expirés | 410 dit au client "le token était réel mais périmé" ; permet le bon flux de re-signature |
| Journaliser la valeur du token dans les logs d'accès | Le token accorde l'accès — le traiter comme un mot de passe ; masquer ou omettre dans les logs |
| Utiliser un secret faible ou prévisible | La clé doit avoir au moins 32 octets aléatoires ; ne jamais dériver du timestamp ou du nom d'hôte |
