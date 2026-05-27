# How-to : Limiteur de débit à fenêtre fixe

> **Référence FT** : FT251 (`NENE2-FT/ratelimitlog`) — Limitation de débit à fenêtre fixe avec upsert SQLite

Démontre un limiteur de débit à fenêtre fixe stocké dans SQLite. Chaque paire `(key, window_start)` accumule un compteur de requêtes. Quand le compteur dépasse la limite configurée, la requête est rejetée avec `429 Too Many Requests` et un en-tête `Retry-After`.

---

## Routes

| Méthode | Chemin    | Description                                              |
|---------|-----------|----------------------------------------------------------|
| `GET`   | `/ping`   | Endpoint à débit limité (lit `X-Client-Key`)            |
| `GET`   | `/status` | Compteur en lecture seule pour une clé (`?key=`)        |

---

## Schéma : clé primaire composite comme stockage du compteur de limite de débit

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- horodatage ISO 8601 tronqué à la limite de fenêtre
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` identifie de manière unique un compteur pour chaque paire `(client, fenêtre)`. L'index rend la recherche de l'upsert rapide. Une table de journal `api_calls` distincte enregistre chaque requête réussie à des fins d'audit.

---

## Pattern upsert : `INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

La première requête pour une paire `(key, windowStart)` insère `count = 1`. Les requêtes suivantes dans la même fenêtre s'incrémentent atomiquement via `DO UPDATE SET count = count + 1`. Pas de `SELECT` avant l'`INSERT` nécessaire — l'upsert est atomique dans SQLite.

Après l'upsert, le compteur est lu pour détecter si la limite a été dépassée :

```php
$row   = $this->executor->fetchOne(
    'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
    [$key, $windowStart],
);
$count = (int) ($row['count'] ?? 0);

if ($count > $this->limit) {
    $retryAfter = (int) (strtotime($windowEnd) - strtotime($now));
    throw new RateLimitExceededException($key, $this->limit, $this->windowSeconds, max(0, $retryAfter));
}
```

La vérification se déclenche **après** l'incrément. Cela signifie que la (limite+1)ème requête est comptée avant d'être rejetée — le compteur atteint `limite + 1` pour les requêtes au-delà de la limite. C'est intentionnel : le compteur reflète fidèlement le total des tentatives, pas seulement celles autorisées.

---

## Troncature de fenêtre : limite fixe

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` est le décalage dans la fenêtre courante. En soustrayant cela, on obtient l'horodatage de début de fenêtre. Pour une fenêtre de 60 secondes à `2026-01-01T00:00:45Z` :

```
ts     = 1751328045  (horodatage Unix)
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

Toutes les requêtes de `:00` à `:59` partagent le même `window_start = 2026-01-01T00:00:00Z`. À `:60`, une nouvelle fenêtre commence et le compteur se réinitialise.

**Compromis fenêtre fixe vs fenêtre glissante** :

| Propriété | Fenêtre fixe | Fenêtre glissante |
|---|---|---|
| Implémentation | Un seul upsert par requête | Lectures/écritures multiples sur plusieurs buckets |
| Mémoire | 1 ligne par (key, fenêtre) | N lignes par key (sous-buckets) |
| Burst à la limite | Oui — 2× la limite possible à la limite de fenêtre | Non — limite uniformément répartie dans le temps |
| Usage courant | APIs simples, outils internes | APIs publiques, équité stricte |

---

## `429 Too Many Requests` avec `Retry-After`

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,
    ) {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds}s exceeded for key '{$key}'.");
    }
}
```

L'exception porte `retryAfter` (secondes jusqu'à l'expiration de la fenêtre courante). Le gestionnaire mappe cela vers une réponse Problem Details `429` avec un en-tête `Retry-After` :

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);

    $response = $this->probs->create(
        request: $request,
        type: 'rate-limit-exceeded',
        title: 'Too Many Requests',
        status: 429,
        detail: $exception->getMessage(),
    );

    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`Retry-After` est le nombre de secondes que le client doit attendre avant de réessayer. Il est calculé comme `windowEnd - now`, limité à `>= 0`.

---

## Clé par client via l'en-tête `X-Client-Key`

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

Chaque client est identifié par son en-tête `X-Client-Key`. L'en-tête manquant utilise `'127.0.0.1'` comme repli — tous les clients non authentifiés partagent un compteur. En production :

- Utiliser un ID utilisateur vérifié ou une clé API extraite d'une session authentifiée — pas un en-tête que le client peut falsifier.
- Utiliser `$_SERVER['REMOTE_ADDR']` (après suppression du proxy) pour la limitation par IP.
- Ne jamais utiliser `X-Forwarded-For` directement — un client peut l'usurper pour contourner les limites.

---

## Endpoint de statut en lecture seule

```php
public function currentCount(string $key, string $now): int
{
    $windowStart = $this->truncateToWindow($now);
    $row = $this->executor->fetchOne(
        'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
        [$key, $windowStart],
    );

    return (int) ($row['count'] ?? 0);
}
```

`GET /status?key=xxx` retourne le compteur courant sans l'incrémenter. Utilisé pour les tableaux de bord de monitoring ou la logique de backoff côté client.

---

## Élagage d'expiration des fenêtres

```php
public function pruneExpired(string $now): int
{
    $cutoff = $this->subtractSeconds($now, $this->windowSeconds * 2);

    return $this->executor->execute(
        'DELETE FROM rate_limit_windows WHERE window_start < ?',
        [$cutoff],
    );
}
```

Les anciennes fenêtres s'accumulent avec le temps. `pruneExpired()` supprime les lignes plus anciennes que deux durées de fenêtre (la fenêtre courante + la précédente sont conservées ; les plus anciennes sont supprimées).

Exécuter `pruneExpired()` depuis une tâche en arrière-plan ou après chaque requête (avec échantillonnage — ex. `rand(0, 99) === 0` pour l'exécuter sur ~1% des requêtes) :

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## Injection de configuration

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` et `windowSeconds` sont injectés à la construction. Différents endpoints peuvent utiliser différentes instances de limiteur avec des configurations différentes :

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## Guides associés

- [`rate-limiting.md`](rate-limiting.md) — `ThrottleMiddleware` pour la limitation de débit par route
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — fenêtre glissante avec sous-buckets (ratelog FT200)
- [`add-rate-limiting.md`](add-rate-limiting.md) — ajouter la limitation de débit à une route existante
- [`quota-management.md`](quota-management.md) — quotas à plus long horizon (quotidien, mensuel)
- [`api-usage-metering.md`](api-usage-metering.md) — suivi d'utilisation par utilisateur avec vérification de quota
