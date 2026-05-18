# Ajouter la limitation de débit

Ce guide montre comment protéger votre application NENE2 avec la limitation de débit des requêtes
en utilisant `ThrottleMiddleware` et `RateLimitStorageInterface`.

**Prérequis** : Vous avez une application NENE2 fonctionnelle. Sinon, commencez par le [Tutoriel](../tutorial/first-api.md).

---

## Démarrage rapide

Ajoutez `ThrottleMiddleware` à `RuntimeApplicationFactory`. Le `InMemoryRateLimitStorage` intégré
convient au développement local et aux déploiements mono-processus.

```php
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17    = new Psr17Factory();
$problems = new ProblemDetailsResponseFactory($psr17, $psr17);
$storage  = new InMemoryRateLimitStorage();

$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          60,   // requêtes autorisées par fenêtre
    windowSeconds:  60,   // durée de la fenêtre en secondes
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` occupe la position 8 dans la pile de middlewares — après l'authentification,
ce qui vous permet de limiter par utilisateur authentifié si vous le souhaitez (voir Extracteur de clé personnalisé ci-dessous).

---

## Fonctionnement

Pour chaque requête, le middleware :

1. Calcule une clé pour le client (par défaut : `REMOTE_ADDR`).
2. Incrémente le compteur dans le backend de stockage.
3. Si le compteur est **inférieur ou égal** à la limite — laisse passer la requête et ajoute les en-têtes de limitation.
4. Si le compteur **dépasse** la limite — retourne `429 Too Many Requests` avec les Problem Details.

### En-têtes de réponse

Chaque réponse (y compris 429) contient ces en-têtes :

| En-tête | Valeur |
|---|---|
| `X-RateLimit-Limit` | Limite configurée par fenêtre |
| `X-RateLimit-Remaining` | Requêtes restantes dans la fenêtre courante |
| `X-RateLimit-Reset` | Horodatage Unix de réinitialisation de la fenêtre |
| `Retry-After` | Secondes jusqu'à la réinitialisation de la fenêtre (429 uniquement) |

### Corps de réponse 429

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit exceeded. Try again in 42 seconds.",
  "instance": "/examples/notes"
}
```

---

## Extracteur de clé personnalisé

Par défaut, la clé est l'adresse IP du client (`REMOTE_ADDR`). Passez une `Closure` pour limiter
par utilisateur authentifié, clé API ou toute autre dimension.

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          1000,
    windowSeconds:  3600,
    keyExtractor:   static function (ServerRequestInterface $request): string {
        return $request->getAttribute('user_id', 'anonymous');
    },
);
```

---

## Changer le backend de stockage

`InMemoryRateLimitStorage` stocke les compteurs en mémoire du processus PHP. Ceux-ci sont réinitialisés
à chaque requête dans les déploiements FPM et **ne sont pas partagés entre les processus**. En production,
vous avez besoin d'un stockage partagé tel que Redis.

Implémentez `RateLimitStorageInterface` :

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    /** @return array{count: int, reset_at: int} */
    public function hit(string $key, int $windowSeconds): array
    {
        $redisKey = "rate:{$key}";
        $count    = (int) $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }
        $ttl     = max(0, (int) $this->redis->ttl($redisKey));
        $resetAt = time() + $ttl;
        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

---

## Décisions de conception

Consultez [ADR 0010](/adr/0010-rate-limiting) pour la justification de :
- Choix de l'algorithme à fenêtre fixe
- Clé par défaut basée sur l'IP
- Conventions d'en-têtes (`X-RateLimit-*`, `Retry-After`)
- Frontière d'abstraction `RateLimitStorageInterface`

---

## Étape suivante

Consultez [Types Problem Details](../reference/problem-details-types.md) pour la forme complète de l'erreur `429`,
ou [Ajouter un health check](./add-health-check.md) pour la fonctionnalité d'observabilité complémentaire.
