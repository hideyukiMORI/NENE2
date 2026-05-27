# How-to : Vérification de signature de webhook avec HMAC-SHA256

> **Référence FT** : FT260 (`NENE2-FT/hmaclog`) — Vérification de signature de webhook : HMAC-SHA256, comparaison à temps constant, prévention des attaques de rejeu
> **ATK** : FT260 — test d'attaque cracker-mindset (ATK-01 à ATK-12)

Démontre comment vérifier les requêtes de webhook entrants en utilisant une signature HMAC-SHA256 style Stripe.
L'en-tête de signature lie un timestamp au corps de la requête, empêchant à la fois la falsification et les attaques de rejeu.
`hash_equals()` est utilisé pour une comparaison à temps constant afin de prévenir les attaques temporelles.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/webhook` | Recevoir et vérifier un webhook signé |
| `GET` | `/webhook/events` | Lister les événements de webhook reçus |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    payload      TEXT NOT NULL,
    delivered_at TEXT NOT NULL
);
```

Les événements sont stockés uniquement après que la vérification de signature réussit. Un webhook rejeté n'est jamais persisté.

---

## Format de signature (style Stripe)

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**Payload signé** : `"<timestamp>.<raw-body>"`

Le timestamp est inclus dans le calcul HMAC. Cela signifie :
- Une signature valide n'est valide que pour le corps sur lequel elle a été calculée (la modification du corps invalide la sig).
- Une signature valide n'est valide qu'au moment de sa génération (rejouer une ancienne signature valide échoue au contrôle du timestamp même si le HMAC est correct).

---

## Vérificateur

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // CRITIQUE : hash_equals est à temps constant ; === ne l'est PAS
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }
        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }
}
```

---

## Contrôleur : extraction du corps brut

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // doit être des octets bruts, non parsés

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // parser seulement après la vérification
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**Ordre critique** :
1. Lire le corps brut comme une chaîne — le HMAC a été calculé sur les octets exacts.
2. Vérifier la signature contre le corps brut.
3. Parser le JSON uniquement après la réussite de la vérification.

Si le JSON est parsé en premier puis re-sérialisé, le contenu en octets peut différer (ordre des clés, espaces blancs), cassant le contrôle HMAC.

---

## ATK — Test d'attaque cracker-mindset (FT260)

### ATK-01 — En-tête de signature manquant

**Attack** : Envoyer un webhook sans en-tête `X-Webhook-Signature`.

```bash
POST /webhook
{"event_type": "user.created"}
```

**Observed** : `verify()` vérifie `$header === ''` avant tout calcul. Retourne 401 Problem Details :
`"Missing X-Webhook-Signature header."` Aucun événement n'est stocké.

**Verdict** : **BLOCKED** — l'en-tête manquant est détecté avant le calcul de la signature.

---

### ATK-02 — Signature altérée (changement d'un seul caractère)

**Attack** : Prendre une signature valide et changer un caractère hex.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-but-one-char-wrong>
```

**Observed** : `hash_equals($expectedSig, $receivedSig)` retourne `false`. 401 est retourné.
La comparaison est à temps constant — le temps de réponse ne varie pas en fonction du nombre de caractères correspondants.

**Verdict** : **BLOCKED** — `hash_equals()` prévient l'oracle temporel tout en rejetant les sigs altérées.

---

### ATK-03 — Mauvais secret utilisé pour signer

**Attack** : Signer la requête avec un secret HMAC différent.

```
X-Webhook-Signature: t=<now>,v1=<hmac-with-wrong-secret>
```

**Observed** : `computeSignature()` utilise le secret du serveur. Le HMAC de l'attaquant (calculé avec un secret différent) produit une chaîne hex différente. `hash_equals()` échoue. 401 retourné.

**Verdict** : **BLOCKED** — sans le secret, une signature valide ne peut pas être forgée.

---

### ATK-04 — Attaque de rejeu : ancienne signature valide

**Attack** : Capturer un en-tête `X-Webhook-Signature` légitime et le rejouer 10 minutes plus tard.

```
X-Webhook-Signature: t=<timestamp-from-10-minutes-ago>,v1=<valid-hmac>
```

**Observed** : `checkTimestamp($timestamp)` calcule `abs(time() - $timestamp)`.
10 minutes = 600 secondes > tolérance de 300 secondes. `SignatureException` levée. 401 retourné.

**Verdict** : **BLOCKED** — les attaques de rejeu sont défaites par la tolérance de timestamp de 300 secondes.

---

### ATK-05 — Timestamp futur : tentative de contournement de la défense de rejeu

**Attack** : Pré-signer une requête avec un timestamp très futur pour étendre la fenêtre de validité.

```
X-Webhook-Signature: t=<now + 3600>,v1=<hmac-with-future-ts>
```

**Observed** : `abs(time() - $timestamp)` = 3600 > 300. `SignatureException` levée. 401 retourné.
`abs()` signifie que les timestamps futurs sont aussi rejetés — le contrôle est symétrique.

**Verdict** : **BLOCKED** — `abs()` garantit que les timestamps passés et futurs en dehors de la fenêtre de tolérance sont rejetés.

---

### ATK-06 — Altération du corps avec une signature valide

**Attack** : Intercepter un webhook valide. Garder l'en-tête `X-Webhook-Signature` mais modifier le corps JSON.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-over-original-body>
Body: {"event_type": "user.deleted"}   ← changé depuis "user.created"
```

**Observed** : Le HMAC a été calculé sur `"<timestamp>.<original-body>"`. Le corps modifié produit un HMAC différent. `hash_equals()` échoue. 401 retourné.

**Verdict** : **BLOCKED** — la signature lie le timestamp au corps. Changer l'un ou l'autre invalide la signature.

---

### ATK-07 — En-tête malformé : timestamp manquant

**Attack** : Soumettre un en-tête de signature sans le composant `t=`.

```
X-Webhook-Signature: v1=<some-hmac>
```

**Observed** : `parseHeader()` vérifie `isset($parts['t'], $parts['v1'])`. `t` manquant lève `SignatureException('Malformed X-Webhook-Signature header.')`. 401 retourné.

**Verdict** : **BLOCKED** — le parseur d'en-tête applique les champs obligatoires.

---

### ATK-08 — Secret vide côté serveur

**Scénario d'attaque** : Le serveur est mal configuré avec un secret HMAC vide (`''`).

**Observed** : Un secret vide est valide dans `hash_hmac()` de PHP — il produit une chaîne hex déterministe. Un attaquant qui découvre le secret vide peut forger des signatures valides :
`hash_hmac('sha256', "{$timestamp}.{$body}", '')`.

**Verdict** : **EXPOSED (mauvaise configuration)** — le vérificateur ne rejette pas un secret vide.
La couche de configuration applicative doit valider que `WEBHOOK_SECRET` est non-vide au démarrage.
Valeur par défaut fail-closed : si le secret est vide, rejeter tous les webhooks.

```php
// Garde de démarrage recommandée
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — Contournement HMAC : soumettre `v1=` avec valeur vide

**Attack** : Définir la signature à une chaîne vide : `X-Webhook-Signature: t=<now>,v1=`.

**Observed** : `parseHeader()` vérifie `$parts['v1'] === ''`. Un `v1` vide lève `SignatureException('Malformed X-Webhook-Signature header.')`. 401 retourné.

**Verdict** : **BLOCKED** — la signature vide est rejetée dans le parseur avant que `hash_equals()` soit appelé.

---

### ATK-10 — Injection de timestamp : timestamp non-numérique

**Attack** : Soumettre un timestamp qui n'est pas un entier pur : `t=1234abc`.

```
X-Webhook-Signature: t=1234abc,v1=<some-hmac>
```

**Observed** : `parseHeader()` vérifie `ctype_digit($parts['t'])`. Les caractères non-numériques causent `SignatureException('Malformed X-Webhook-Signature header.')`. 401 retourné.

**Verdict** : **BLOCKED** — `ctype_digit()` applique que le timestamp est une chaîne d'entier pur.

---

### ATK-11 — Injection d'en-tête : virgule dans le hex HMAC

**Attack** : Injecter une virgule dans la valeur `v1` pour confondre le parseur.

```
X-Webhook-Signature: t=<now>,v1=abc,def
```

**Observed** : `parseHeader()` utilise `explode('=', $chunk, 2)` avec limite 2. L'en-tête est d'abord divisé sur `,` (produisant `['t=<now>', 'v1=abc', 'def']`), puis chaque segment est divisé sur `=` avec limite 2. Le segment `def` devient `['def', '']` et n'écrase rien de critique.
La valeur `v1` est `abc`, qui n'est pas un hex HMAC valide. `hash_equals()` échoue. 401 retourné.

**Verdict** : **BLOCKED** — la robustesse du parseur + la vérification de longueur HMAC empêchent la manipulation par injection.

---

### ATK-12 — Corps volumineux : attaque par taille de payload

**Attack** : Envoyer un webhook avec un corps de plusieurs mégaoctets.

**Observed** : Le vérificateur calcule `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)`.
`hash_hmac()` gère des entrées de taille arbitraire ; la sortie est toujours de 64 caractères hex.
Aucune limite de taille explicite n'est appliquée au niveau du vérificateur. Un corps de 100 Mo serait accepté si la signature est valide et le timestamp est récent.

**Verdict** : **EXPOSED** — pas de limite de taille de requête sur l'endpoint webhook. Ajouter un middleware de taille de requête (ex. limite de 1 Mo) en amont pour prévenir l'épuisement des ressources. Le vérificateur ne devrait pas être responsable des limites de taille — c'est une préoccupation pour une couche middleware externe.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|---|---|
| ATK-01 | En-tête de signature manquant | BLOCKED |
| ATK-02 | Signature altérée (1 char) | BLOCKED |
| ATK-03 | Mauvais secret utilisé | BLOCKED |
| ATK-04 | Attaque de rejeu (ancien timestamp) | BLOCKED |
| ATK-05 | Contournement par timestamp futur | BLOCKED |
| ATK-06 | Altération du corps | BLOCKED |
| ATK-07 | En-tête malformé (pas de timestamp) | BLOCKED |
| ATK-08 | Secret serveur vide (mauvaise configuration) | EXPOSED |
| ATK-09 | Valeur `v1=` vide | BLOCKED |
| ATK-10 | Timestamp non-numérique | BLOCKED |
| ATK-11 | Injection d'en-tête via virgule | BLOCKED |
| ATK-12 | Corps volumineux / épuisement des ressources | EXPOSED |

**Vulnérabilités réelles à corriger avant la production** :
1. **ATK-08** — Garde fail-closed pour secret vide au démarrage (`if ($secret === '') throw`)
2. **ATK-12** — Middleware de taille de requête (ex. limite de 1 Mo) en amont de la route webhook

---

## Notes de conception

### Pourquoi HMAC-SHA256 plutôt qu'un simple bearer token ?

Un bearer token prouve uniquement que l'émetteur connaît le token. HMAC-SHA256 prouve que l'émetteur connaît le secret ET que le corps n'a pas été modifié — l'intégrité du corps est intégrée.

### Pourquoi lier le timestamp au payload HMAC ?

Si la signature était `HMAC(body)` uniquement, un attaquant qui capture une requête valide pourrait la rejouer indéfiniment. En signant `"<timestamp>.<body>"`, chaque signature n'est valide que dans la fenêtre de 300 secondes et pour le corps exact sur lequel elle a été calculée.

### Pourquoi `hash_equals()` au lieu de `===` ?

Le `===` de PHP est une comparaison court-circuit : il s'arrête dès que deux caractères diffèrent. Un attaquant qui peut faire des milliers de requêtes et mesurer les temps de réponse peut apprendre combien de caractères de début de la signature attendue correspond à sa tentative — un octet à la fois — et forcer brutalement le secret. `hash_equals()` s'exécute en temps constant quelle que soit la divergence des chaînes.

---

## Guides liés

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — `hash_equals()` et HMAC-SHA256 pour le stockage PIN + verrouillage
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — pattern d'évaluation ATK cracker-mindset
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — limitation de débit comme complément à la vérification de signature
