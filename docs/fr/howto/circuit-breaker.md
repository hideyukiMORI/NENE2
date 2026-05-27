# How-to : Disjoncteur (Circuit Breaker)

> **Référence FT** : FT298 (`NENE2-FT/circuitlog`) — Pattern disjoncteur : machine à trois états closed/open/half_open, seuil d'échec configurable, transition automatique vers half_open basée sur le timeout, 503 Service Unavailable sur circuit ouvert, vérification en lecture seule `isCallAllowed()`, 15 tests / 28 assertions PASS.

Le pattern disjoncteur empêche les défaillances en cascade lors des appels à des services externes. Au lieu de laisser les appels lents ou échoués s'accumuler, le disjoncteur s'ouvre et rejette immédiatement les appels jusqu'à la récupération de la dépendance.

## Trois états

```
Closed ──(N échecs consécutifs)──▶ Open ──(timeout écoulé)──▶ Half-Open
  ▲                                                                 │
  └───────────────────(succès)────────────────────────────────────┘
  Half-Open ──(échec)──▶ Open
```

| État | Comportement |
|---|---|
| **Closed** | Normal — les appels passent. Le compteur d'échecs s'incrémente à chaque erreur. |
| **Open** | Les appels sont immédiatement rejetés avec 503. S'ouvre pour `timeout_seconds` après `failure_threshold` échecs consécutifs. |
| **Half-Open** | Un seul appel sonde autorisé. Succès → Closed (réinitialisation). Échec → Open à nouveau. |

## Schéma

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

Le nom du circuit est typiquement l'identifiant du service externe (ex. `payment-gateway`, `email-svc`). Plusieurs circuits indépendants peuvent coexister.

## Enregistrement des résultats

```php
// Après un appel réussi au service externe :
$this->repo->recordSuccess($circuitName, $now);

// Après un appel échoué :
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` décide la transition :
- Si `failure_count + 1 >= failure_threshold` → définir l'état sur `open`, calculer `open_until = now + timeout`.
- Si encore en dessous du seuil → incrémenter `failure_count`, rester `closed`.
- Si en état `half_open` → tout échec rouvre immédiatement.

## Vérification si un appel est autorisé

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // Retourner 503 immédiatement — ne pas appeler le service externe
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// Tenter l'appel...
```

Appeler `maybeTransitionToHalfOpen()` avant la vérification `isCallAllowed()` à chaque requête. Cela effectue la transition `Open → Half-Open` une fois que `open_until` est passé, permettant à l'appel sonde de passer.

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## Timing Half-Open

La transition `Open → Half-Open` est paresseuse : elle se produit la prochaine fois que `maybeTransitionToHalfOpen()` est appelé après que `open_until` soit écoulé. C'est intentionnel — cela évite les minuteries en arrière-plan et garde les changements d'état liés aux requêtes entrantes.

## Réglage du seuil d'échec et du timeout

| Type de dépendance | Seuil recommandé | Timeout recommandé |
|---|---|---|
| Base de données (critique) | 3–5 | 10–30s |
| API externe | 5–10 | 30–60s |
| Service non critique | 10–20 | 60–120s |

Des seuils plus élevés réduisent les faux positifs (perturbations temporaires). Des timeouts plus longs donnent plus de temps de récupération aux dépendances mais prolongent la dégradation visible par les clients.

## Plusieurs circuits par service

Utiliser des noms de circuit distincts pour des domaines de défaillance distincts :

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

Cela empêche une défaillance dans l'endpoint de remboursement de bloquer les tentatives de débit.

## Réponse quand le circuit est Ouvert

Retourner `503 Service Unavailable` avec un en-tête `Retry-After` pointant vers `open_until` :

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

Les clients et équilibreurs de charge qui respectent `503` peuvent arrêter le routage vers cette instance pendant que le circuit est ouvert.

## Décisions de conception

**Pourquoi un état stocké en DB plutôt qu'en mémoire ?** L'état en mémoire est perdu au redémarrage et n'est pas partagé entre les workers PHP-FPM. L'état DB est cohérent entre tous les workers et survive aux redémarrages, au coût d'une requête DB supplémentaire par appel protégé. Pour les chemins à haut débit, envisager Redis avec des opérations d'incrément atomique.

**Pourquoi une transition Half-Open paresseuse ?** Les transitions en arrière-plan proactives nécessitent un planificateur ou un daemon. Les transitions paresseuses sont plus simples, sans état du point de vue du planificateur, et suffisantes pour la plupart des API web où le volume de requêtes assure que la vérification s'exécute rapidement.

**Pourquoi `failure_count` se réinitialise-t-il sur n'importe quel succès ?** C'est la sémantique des "échecs consécutifs". Une alternative est le "taux d'échec sur une fenêtre glissante" (ex. >50% d'échecs dans les 60 dernières secondes). La fenêtre glissante est plus précise pour les services avec un trafic faible mais régulier ; les échecs consécutifs sont plus simples et suffisants pour les services qui sont soit opérationnels soit hors service.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de contrainte `UNIQUE(name)` | Des créations concurrentes produisent plusieurs lignes pour le même circuit |
| Pas de timeout sur le circuit ouvert | Le circuit reste ouvert pour toujours après le dépassement du seuil |
| Pas d'état half_open | Le circuit passe directement ouvert → fermé ; pas de sonde-puis-vérification |
| Retourner 200 quand le circuit est ouvert | Les appelants pensent que l'appel a réussi ; les erreurs en aval sont cachées |
| Pas de `open_until` dans la réponse 503 | Les appelants réessaient immédiatement (troupeau tonnerreux) ; inclure le timing de reprise |
| Accepter la chaîne `"true"` comme succès | Confusion de type JSON ; utiliser `is_bool()` strictement |
| Vérifier `isCallAllowed()` sans `maybeTransitionToHalfOpen()` d'abord | Le circuit ouvert ne devient jamais half_open ; bloqué en permanence |
| État en mémoire uniquement | État perdu au redémarrage du worker ; pas de partage entre les workers PHP-FPM |
