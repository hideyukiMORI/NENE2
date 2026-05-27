# Comment ajouter la déduplication de requêtes

Prévenir le double traitement causé par les réessais réseau ou les doubles clics avec un en-tête `Idempotency-Key`. Le serveur met en cache les réponses par clé et les rejoue lors des requêtes identiques ultérieures.

## Schéma

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/payments` | Traiter un paiement (idempotency-key requis) |
| `POST` | `/orders` | Créer une commande (idempotency-key requis) |

## Pattern de handler

Chaque endpoint mutant qui doit être idempotent suit le même pattern en trois étapes :

```php
// 1. Exiger l'en-tête Idempotency-Key
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. Retourner la réponse en cache si la clé est déjà utilisée
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. Traiter et mettre en cache
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

Le champ `replayed: true` signale aux clients que la réponse a été servie depuis le cache.

## Validation stricte des montants

Rejeter les entrées non entières à la frontière — le cast `(int)` de PHP tronque silencieusement des chaînes comme `"100; DROP TABLE …"` à `100`. Utiliser une vérification de type explicite :

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL et expiration

Les clés expirent après 24 heures (86400 secondes). Les entrées expirées sont traitées comme nouvelles — la même clé peut être réutilisée après expiration :

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## Propriétés de sécurité

- **Injection SQL via l'en-tête de clé** : les requêtes paramétrées stockent les clés malveillantes comme littéraux.
- **Flood de rejeu** : 10 requêtes identiques créent exactement 1 enregistrement dans la table métier.
- **Clé composée uniquement d'espaces** : `trim()` avant la vérification vide prévient `"   "` comme clé valide.
- **Injection de type dans les champs numériques** : la vérification `ctype_digit()` rejette les chaînes partiellement entières.
- **Pas de fuites internes** : les réponses 400/422 ne contiennent que les champs `error` ou `errors` — pas de chemins, traces de pile ou détails du moteur.
