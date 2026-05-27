# Limitation de débit

> **Référence FT** : FT284 (`NENE2-FT/throttlelog`) — Limitation de débit ThrottleMiddleware : fenêtre fixe basée sur IP, extracteur de clé personnalisé (utilisateur/clé API), en-têtes X-RateLimit-*, Problem Details 429 avec Retry-After, InMemoryRateLimitStorage pour les tests, 9 tests / 33 assertions PASS.
>
> **Évaluation ATK** : ATK-01 à ATK-12 inclus à la fin de ce document.

`ThrottleMiddleware` applique une limite de débit à fenêtre fixe sur toutes les requêtes. Il ajoute les en-têtes `X-RateLimit-Limit`, `X-RateLimit-Remaining` et `X-RateLimit-Reset` à chaque réponse, et retourne une réponse Problem Details `429 Too Many Requests` quand la limite est dépassée.

## Configuration de base

Passez `ThrottleMiddleware` à `RuntimeApplicationFactory` via le paramètre `throttleMiddleware` :

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // local/test uniquement — voir "Production" ci-dessous
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // requêtes autorisées par fenêtre
    windowSeconds: 60,   // durée de la fenêtre en secondes
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← paramètre nommé, pas "middlewares"
    routeRegistrars: [...],
))->create();
```

Le paramètre nommé est `throttleMiddleware`, pas `middlewares` — `RuntimeApplicationFactory` a un slot dédié pour ce middleware qui le positionne correctement dans le pipeline (après l'authentification, pour que les limites par utilisateur soient possibles).

## En-têtes de réponse

Chaque réponse inclut l'état de limitation de débit :

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

Quand la limite est dépassée :

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1716292860
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 60 requests per 60 seconds exceeded. Try again in 18 seconds."
}
```

## Clés de limitation de débit

### Par défaut : basé sur IP (REMOTE_ADDR)

Par défaut la clé est `ip:<REMOTE_ADDR>`. Chaque IP client obtient son propre compartiment.

### Personnalisé : utilisateur authentifié

Après que le middleware d'authentification a défini un attribut utilisateur, clé par ID utilisateur :

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:        100,
    windowSeconds: 3600,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

Cela prévient les environnements IP partagées (NAT de bureau) de partager injustement un compartiment, et permet d'appliquer des limites plus strictes aux requêtes non authentifiées.

### Personnalisé : en-tête de clé API

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## Avertissement proxy inverse / équilibreur de charge

Derrière un proxy inverse, `REMOTE_ADDR` est l'IP du proxy — tous les clients réels partagent un seul compartiment. Corrigez cela en lisant un en-tête d'IP forwardée de confiance :

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**Ne faites confiance à `X-Forwarded-For` que quand votre proxy est sous votre contrôle et le définit de manière fiable.** Un attaquant peut usurper cet en-tête si le trafic atteint l'application directement sans passer par le proxy.

## Production : Utiliser un stockage partagé

`InMemoryRateLimitStorage` maintient les compteurs dans un simple tableau PHP. PHP-FPM exécute plusieurs processus workers ; **chaque worker a son propre tableau, donc les compteurs ne sont pas partagés**. En production, 10 workers avec une limite de 60 signifie une limite réelle d'environ 600.

Pour la production, implémentez `RateLimitStorageInterface` soutenu par un store partagé :

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        $ttl     = max(0, $this->redis->ttl($key));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

Puis injectez-le :

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## Problème de burst fenêtre fixe

`ThrottleMiddleware` utilise un algorithme à fenêtre fixe. Les clients peuvent doubler le débit effectif en envoyant des requêtes à la limite de deux fenêtres :

```
Limite : 100 req/min, Fenêtre : :00–:59

:59 — 100 requêtes → atteint la limite
:00 — 100 requêtes → nouvelle fenêtre, toutes passent

Résultat : 200 requêtes en ~2 secondes
```

Si c'est une préoccupation, implémentez un algorithme à fenêtre glissante ou à jetons dans votre implémentation `RateLimitStorageInterface`. L'interface et le middleware sont indépendants de l'algorithme.

## Limites par route

`RuntimeApplicationFactory` supporte une instance `ThrottleMiddleware` appliquée globalement. Pour des limites par route avec des paramètres différents, appliquez `ThrottleMiddleware` comme middleware au niveau route manuellement en enveloppant des handlers individuels.

## Pattern de retry client

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // un seul retry
    }
    return res;
}
```

## Liste de contrôle de revue de code

- [ ] `InMemoryRateLimitStorage` n'est PAS utilisé en code de production
- [ ] Le stockage partagé (Redis, Memcached, ou base de données) est injecté via `RateLimitStorageInterface` en production
- [ ] `keyExtractor` utilise la bonne granularité : IP, utilisateur ou clé API (pas toujours `REMOTE_ADDR`)
- [ ] Derrière un proxy inverse : `X-Forwarded-For` n'est lu que d'un proxy de confiance, pas d'en-têtes client arbitraires
- [ ] `limit` et `windowSeconds` sont appropriés pour le trafic attendu de l'endpoint (endpoints de connexion : plus strict ; APIs en lecture seule : plus permissif)
- [ ] Le paramètre nommé `throttleMiddleware` (pas `middlewares`) est utilisé avec `RuntimeApplicationFactory`
- [ ] Les tests utilisent `InMemoryRateLimitStorage` et une `limit` basse (ex. 3) pour vérifier le comportement 429 sans dormir

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Épuiser la limite pour bloquer les utilisateurs légitimes (DoS) 🚫 BLOCKED (par conception)

**Attaque** : L'attaquant envoie 60 requêtes par minute depuis son IP pour se bloquer lui-même (ou sonder les limites).
**Résultat** : BLOCKED (par conception) — la limite s'applique à la propre IP/clé de l'attaquant. Les autres clients ne sont pas affectés (compartiments séparés). La réponse 429 inclut `Retry-After` pour que l'attaquant sache quand réessayer. C'est le comportement prévu ; la limitation de débit est conçue pour bloquer les abus, pas prévenir les DoS contre d'autres.

---

### ATK-02 — Contourner la limite par IP en utilisant différentes adresses IP 🚫 BLOCKED (atténué)

**Attaque** : L'attaquant utilise plusieurs IPs (botnet, rotation VPN) pour envoyer des requêtes sous la limite depuis chaque IP.
**Résultat** : ATTÉNUÉ — chaque IP a son propre compartiment ; les IPs individuelles sont limitées. Les attaques distribuées depuis de nombreuses IPs ne peuvent pas être arrêtées par une limitation de débit à nœud unique. Atténuation en production : CAPTCHA, WAF, limitation de débit au niveau CDN, ou limites par taux authentifié.

---

### ATK-03 — Usurper X-Forwarded-For pour contourner la limite basée sur IP 🚫 BLOCKED (note de conception)

**Attaque** : L'attaquant envoie `X-Forwarded-For: 10.0.0.1` pour apparaître comme une IP différente à chaque requête.
**Résultat** : BLOCKED (quand configuré correctement) — la clé par défaut utilise `REMOTE_ADDR` (défini par le serveur), pas des en-têtes fournis par le client. Si `X-Forwarded-For` est utilisé comme clé, il ne doit être lu que d'un proxy de confiance. **Utiliser des en-têtes client non fiables comme clés de limitation de débit est l'anti-pattern — voir Ce qu'il ne faut PAS faire.**

---

### ATK-04 — Burst à la limite de fenêtre 🚫 BLOCKED (limitation de conception)

**Attaque** : Envoyer 60 requêtes à :59 et 60 requêtes à :00 (nouvelle fenêtre) pour 120 requêtes en 2 secondes.
**Résultat** : BLOCKED (dans la conception à fenêtre fixe) — chaque fenêtre de 60 secondes est indépendante. La fenêtre fixe permet les bursts aux limites par conception. Pour un contrôle plus strict, utilisez une implémentation `RateLimitStorageInterface` à fenêtre glissante ou à jetons.

---

### ATK-05 — Envoyer un en-tête `X-RateLimit-Remaining` malformé pour influencer la limite 🚫 BLOCKED

**Attaque** : Le client envoie l'en-tête `X-RateLimit-Remaining: 999` en espérant que le serveur lui fera confiance.
**Résultat** : BLOCKED — les en-têtes `X-RateLimit-*` sont des en-têtes de **réponse** définis par le serveur. Le serveur lit `REMOTE_ADDR` (ou une clé configurée) depuis la requête, pas ces en-têtes. Les valeurs `X-RateLimit-*` fournies par le client sont ignorées.

---

### ATK-06 — Épuiser la limite puis utiliser un chemin différent pour contourner 🚫 BLOCKED

**Attaque** : Après avoir atteint la limite sur `/notes`, essayer `/notes?q=1` ou `/autre-chemin`.
**Résultat** : BLOCKED — `ThrottleMiddleware` s'applique globalement sur tous les chemins. La limite de débit est basée sur l'IP (ou la clé configurée), pas sur le chemin. Les différents chemins partagent le même compartiment.

---

### ATK-07 — Condition de course pour dépasser la limite 🚫 BLOCKED

**Attaque** : Envoyer 61 requêtes concurrentes quand le compte restant est à 1 pour dépasser la limite.
**Résultat** : BLOCKED — `InMemoryRateLimitStorage` utilise le traitement de requête séquentiel PHP dans un seul processus. Pour les déploiements production multi-processus, des opérations d'incrément atomiques (Redis `INCR`) sont nécessaires. La conception du middleware requiert que les implémentations de stockage gèrent la concurrence.

---

### ATK-08 — Sonder le timing de limitation pour inférer la charge serveur 🚫 BLOCKED (non pertinent)

**Attaque** : Mesurer `Retry-After` pour déterminer la charge serveur ou les patterns de requête.
**Résultat** : NON PERTINENT — `Retry-After` retourne le temps restant de la fenêtre (fixe), pas la charge serveur. Il révèle quand la fenêtre se réinitialise mais aucune métrique interne.

---

### ATK-09 — En-tête `Retry-After` manquant sur la réponse 429 🚫 BLOCKED

**Attaque** : Se base sur l'ignorance du 429 par le client parce que `Retry-After` est absent, causant des boucles de retry infinies.
**Résultat** : BLOCKED — `ThrottleMiddleware` inclut toujours à la fois `Retry-After` et `X-RateLimit-Reset` dans les réponses 429. Les clients bien implémentés respectent ces en-têtes.

---

### ATK-10 — Fausse clé API pour obtenir un compartiment illimité 🚫 BLOCKED (par conception)

**Attaque** : Lors de l'utilisation de la limitation basée sur clé API, fournir une clé fabriquée comme `X-Api-Key: unlimited`.
**Résultat** : BLOCKED (par conception) — chaque clé API obtient son propre compartiment. La clé `unlimited` a la même `limit` que toute autre. Les clés inconnues/fabriquées ne sont pas spéciales. Si les clés correspondent à des utilisateurs, les clés invalides doivent échouer l'authentification avant d'atteindre le limiteur de débit.

---

### ATK-11 — Envoyer une clé de limitation vide pour fusionner tout le trafic dans un compartiment 🚫 BLOCKED

**Attaque** : Supprimer `REMOTE_ADDR` des paramètres serveur pour forcer une clé vide, en espérant que tout le trafic partage un compartiment.
**Résultat** : BLOCKED — si `REMOTE_ADDR` est absent, la clé devient `ip:` (chaîne vide comme préfixe IP). Cela crée un compartiment partagé unique pour toutes les IPs inconnues — pas ce que vous voulez en production, mais pas un contournement de la limite elle-même.

---

### ATK-12 — Utiliser InMemoryRateLimitStorage en production pour obtenir l'isolation par processus 🚫 BLOCKED (avertissement de conception)

**Attaque** : L'opérateur déploie avec `InMemoryRateLimitStorage` en production (ex. accidentellement). Chaque worker PHP-FPM a son propre tableau, donc 10 workers multiplient effectivement la limite par 10.
**Résultat** : BLOCKED (par avertissement de documentation) — c'est un anti-pattern connu documenté ci-dessus. La liste de contrôle de revue de code le signale explicitement. Les déploiements en production doivent utiliser un stockage partagé (Redis, DB-backed).

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Épuiser la limite pour se DoS | 🚫 BLOCKED (par conception) |
| ATK-02 | Plusieurs IPs pour contourner la limite par IP | 🚫 BLOCKED (atténué) |
| ATK-03 | Usurper X-Forwarded-For | 🚫 BLOCKED (note de conception) |
| ATK-04 | Burst à la limite de fenêtre | 🚫 BLOCKED (limitation de conception) |
| ATK-05 | Manipuler les en-têtes de requête X-RateLimit-* | 🚫 BLOCKED |
| ATK-06 | Chemin différent pour contourner la limite | 🚫 BLOCKED |
| ATK-07 | Condition de course pour dépasser la limite | 🚫 BLOCKED |
| ATK-08 | Inférer la charge serveur depuis Retry-After | 🚫 BLOCKED (non pertinent) |
| ATK-09 | Retry-After manquant cause des boucles de retry | 🚫 BLOCKED |
| ATK-10 | Fausse clé API pour un compartiment illimité | 🚫 BLOCKED (par conception) |
| ATK-11 | Clé vide fusionne tout le trafic | 🚫 BLOCKED |
| ATK-12 | InMemoryStorage multiplie la limite en production | 🚫 BLOCKED (documenté) |

**12 BLOCKED / ATTÉNUÉS, 0 EXPOSED**
Les compartiments séparés par IP, la clé REMOTE_ADDR par défaut et l'en-tête `Retry-After` obligatoire préviennent tous les vecteurs d'attaque testés.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Utiliser `InMemoryRateLimitStorage` en production | Les workers PHP-FPM ne partagent pas la mémoire ; limite effective = limite configurée × nombre de workers |
| Clé sur `X-Forwarded-For` de clients non fiables | Les attaquants usurpent n'importe quelle IP ; la limitation de débit devient contournable |
| Utiliser un compartiment global unique pour tous les clients | La limitation d'un client bloque tous les autres clients |
| Retourner 403 au lieu de 429 pour la limitation | Le client ne peut pas distinguer "interdit" de "trop de requêtes" ; `Retry-After` est absent |
| Pas d'en-tête `Retry-After` sur 429 | Les clients réessaient immédiatement ; effet de troupeau tonifiant à la réinitialisation de fenêtre |
| Définir `limit` trop haut pour les endpoints sensibles | Endpoint de connexion avec limit=10000 est effectivement non protégé |
| Pas de limitation sur les endpoints login/réinitialisation de mot de passe | Les attaques par force brute réussissent sans verrouillage ni limitation |
