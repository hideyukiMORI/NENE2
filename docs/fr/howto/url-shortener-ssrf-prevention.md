# How-to : Raccourcisseur d'URL avec prévention SSRF

> **Référence FT** : FT337 (`NENE2-FT/shortlog`) — Raccourcisseur d'URL avec blocage SSRF (IPs privées, loopback, link-local, schémas dangereux), validation de slug, prévention d'assignation de masse, validation de date ISO 8601, parsing de limite sécurisé contre ReDoS, 50+ tests PASS.

Ce guide montre comment construire un raccourcisseur d'URL qui accepte uniquement les URLs publiques sûres, valide les slugs, prévient l'assignation de masse, et protège contre le Server-Side Request Forgery (SSRF).

## Schéma

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601, nullable
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/links` | Créer un lien court |
| `GET`  | `/links` | Lister ses propres liens |
| `GET`  | `/links/{slug}` | Obtenir un lien par slug |
| `DELETE` | `/links/{slug}` | Supprimer son propre lien |

## Créer un lien court

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` est optionnel — auto-généré (`[a-z0-9_-]+`) si omis.

### Authentification manquante

```php
POST /links  (sans en-tête X-User-Id)
→ 401
```

### Slug dupliqué

```php
POST /links  {"slug": "my-link"}  // existe déjà
→ 409
```

## Validation du slug

```
Valide : lettres minuscules, chiffres, tirets, tirets bas
Longueur : 3–20 caractères

Exemples valides : "abc", "my-link", "link123", "test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // trop court (min 3)
POST /links  {"slug": "a".repeat(21)} → 422  // trop long (max 20)
POST /links  {"slug": "MySlug"}       → 422  // majuscules non autorisées
POST /links  {"slug": "sl@g!"}        → 422  // caractères spéciaux
POST /links  {"slug": "my slug"}      → 422  // espace non autorisé
POST /links  {"slug": 42}             → 422  // le type doit être chaîne (VULN-B)
```

## Validation d'URL

```php
POST /links  {"original_url": ""}              → 422  // vide
POST /links  {}                                → 422  // manquant
POST /links  {"original_url": 42}              → 422  // pas une chaîne (VULN-B)
POST /links  {"original_url": true}            → 422  // booléen (VULN-B)
POST /links  {"original_url": null}            → 422  // null (VULN-B)
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // trop long
```

## Prévention SSRF

Bloquer les URLs qui feraient appeler l'infrastructure interne par le serveur :

### Schémas bloqués

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

Seuls `http://` et `https://` sont autorisés.

### Plages d'IP bloquées

```php
// Loopback
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// Plages privées RFC 1918
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// Link-local (métadonnées AWS, etc.)
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// IP publique — acceptée
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### Prévention du DNS rebinding

Les noms d'hôtes qui se résolvent en IPs privées sont également bloqués :

```php
// "private.internal" se résout en 10.0.0.1 → bloqué
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" se résout en 93.184.216.34 → autorisé
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### Implémentation

```php
private const BLOCKED_RANGES = [
    '127.',          // loopback
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // link-local
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // Bloquer *.localhost
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Résoudre le nom d'hôte en IP
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## Prévention d'assignation de masse

```php
// L'attaquant essaie de définir click_count ou created_at
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // champs ignorés
```

Mettre uniquement `original_url`, `slug`, `expires_at` en liste blanche depuis le corps de la requête. Ne jamais lire `click_count`, `created_at` ou `user_id` depuis le corps.

## Validation de date ISO 8601

```php
// Dates calendaires invalides
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // 30 fév
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // mois 13
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // décalage +25:00

// Valide
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

Pattern de validation : analyser avec `DateTimeImmutable::createFromFormat()` et vérifier l'aller-retour :

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// La vérification aller-retour capture "2024-02-30" que PHP normalise en "2024-03-01"
return $dt->format(DATE_RFC3339) === $value;
```

## Validation de limite sécurisée contre ReDoS

```php
// ctype_digit pour O(n) — immunisé contre ReDoS
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // dépasse MAX_LIMIT
GET /links?limit=9...9 (19 chiffres)  → 422  // garde contre le débordement
GET /links?limit=111...1x (51 chars avec x)  → 422, <100ms  // payload ReDoS
```

## Prévention IDOR

```php
// L'utilisateur 2 essaie de supprimer le lien de l'utilisateur 1
DELETE /links/user1-link
X-User-Id: 2
→ 404  // PAS 403 — prévient l'énumération
```

Le lien existe mais la recherche est scopée à `WHERE slug = ? AND user_id = ?`. Une non-correspondance retourne 404 comme si le lien n'existait pas.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Autoriser `http://localhost` ou `http://127.0.0.1` | Le serveur récupère son propre endpoint d'administration via le lien court |
| Ignorer la vérification de résolution DNS | L'attaquant enregistre `evil.example.com` → enregistrement A `10.0.0.1` pour contourner la vérification d'IP littérale |
| Autoriser le schéma `javascript:` | XSS via shortlink dans tout navigateur qui ouvre la redirection |
| Autoriser le schéma `file://` | Le serveur lit `/etc/passwd` si le raccourcisseur récupère l'URL à la création |
| Accepter `click_count` depuis le corps de la requête | L'attaquant gonfle les métriques de clics |
| Pas de restriction de longueur/charset sur le slug | `slug = "' OR 1=1--"` passe la validation, atteint SQL |
| Utiliser regex `/^\d+$/` pour la validation de limite | ReDoS sur de longs payloads chiffres-mixtes |
| Retourner `created_at` depuis le corps de la requête | L'usurpation de temps corrompt la piste d'audit |
