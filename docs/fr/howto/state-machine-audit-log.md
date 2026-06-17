# How-to : Machine à états avec journal d'audit

> **Référence FT** : FT237 (`NENE2-FT/statemachinelog`) — Machine à états avec journal d'audit
> **VULN** : FT237 — audit de sécurité / vulnérabilité (V-01 à V-10)

Démontre une API de machine à états où chaque transition est enregistrée dans une table de journal
d'audit immuable. Le statut actuel vit sur la commande ; l'historique complet vit dans une
table `order_transitions` séparée. `InvalidTransitionException` fournit des réponses 409 structurées
avec le contexte `from` et `to`.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/orders` | Créer une commande (démarre comme `draft`) |
| `GET` | `/orders/{id}` | Obtenir l'état actuel de la commande |
| `POST` | `/orders/{id}/transitions` | Appliquer une transition d'état |
| `GET` | `/orders/{id}/transitions` | Lister l'historique complet des transitions |

---

## Machine à états : transitions autorisées

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

Les états terminaux (`approved`, `rejected`, `cancelled`) retournent une liste vide — ils
ne peuvent plus transitionner.

---

## InvalidTransitionException → 409 avec contexte

Quand un appelant demande une transition illégale, l'exception porte les états from et to
comme données structurées pour la réponse d'erreur :

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from->value, $to->value)
        );
    }
}
```

Le contrôleur inclut `from` et `to` dans l'extension Problem Details :

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

Réponse :
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transition from \"approved\" to \"submitted\" is not allowed.",
  "from": "approved",
  "to": "submitted"
}
```

`from` et `to` permettent à l'appelant de comprendre exactement quelle transition a été rejetée sans
analyser la chaîne `detail`.

---

## Journal d'audit de transition : pattern double-écriture

Chaque transition réussie met à jour le statut de la commande ET insère un enregistrement de journal de manière atomique :

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // Mettre à jour le statut actuel
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // Ajouter au journal d'audit
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **Note sur l'atomicité** : Sans transaction enveloppante, une défaillance entre le UPDATE et
> l'INSERT laisse la commande dans le nouvel état sans enregistrement de journal. Envelopper les deux instructions dans une
> transaction pour une vraie atomicité. Le mode WAL de SQLite rend cela sûr sous accès concurrent.

---

## Schéma : état de commande + historique des transitions

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` est en append-only par conception — aucun endpoint UPDATE ou DELETE n'existe pour
elle. L'historique complet des transitions est préservé pour l'audit.

---

## Réponse d'historique des transitions

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

La liste est ordonnée par `id ASC` donc l'historique est chronologique.

---

## VULN — Audit de sécurité (FT237)

### V-01 — Pas d'authentification sur aucun endpoint

**Attaque** : Créer des commandes et appliquer des transitions sans credentials.

```bash
curl -s -X POST http://localhost:8200/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**Observé** : `200 OK` — aucun token requis. N'importe qui peut approuver ou annuler n'importe quelle commande.

**Verdict** : **EXPOSED** (par conception pour la démo FT237). Ajouter l'authentification et
l'autorisation : protéger les transitions derrière un rôle (soumetteur vs réviseur), et restreindre
chaque commande à son propriétaire.

---

### V-02 — Valeur de statut invalide

**Attaque** : Envoyer une chaîne de statut inconnue.

```json
{"status": "hacked"}
{"status": ""}
```

**Observé** : `OrderStatus::tryFrom('hacked')` = `null` → `422` avec une erreur listant
tous les statuts valides.

**Verdict** : **BLOCKED** — `tryFrom()` d'enum backed rejette les valeurs inconnues.

---

### V-03 — Transition illégale (état terminal → actif)

**Attaque** : Essayer de transitionner depuis `approved` ou `cancelled` vers un autre statut.

```json
{"status": "submitted"}   // depuis approved
{"status": "draft"}       // depuis cancelled
```

**Observé** : `canTransitionTo()` retourne `false` → `InvalidTransitionException` →
`409 Conflict` avec contexte `from`/`to` dans le corps de la réponse.

**Verdict** : **BLOCKED** — la machine à états impose toutes les règles de transition au niveau du domaine.

---

### V-04 — ID de commande non numérique

**Attaque** : Passer une chaîne ou un flottant comme `{id}`.

```
GET /orders/abc
GET /orders/1.5
```

**Observé** : `(int) 'abc'` = 0, `(int) '1.5'` = 1. Pour `abc`, `findById(0)` retourne
`null` → `404 Not Found`. Pour `1.5`, si la commande 1 existe elle est retournée — troncature silencieuse.

**Verdict** : **PARTIALLY BLOCKED** — les chaînes non numériques se résolvent en 404. Les flottants sont
silencieusement tronqués. Ajouter une garde `ctype_digit()` pour la validation stricte.

---

### V-05 — Historique de transitions non scopé à l'appelant

**Attaque** : Lire l'historique de transitions d'un autre utilisateur.

```
GET /orders/1/transitions
```

**Observé** : `200 OK` — historique complet retourné sans vérification de propriété ou d'authentification.
L'historique révèle qui a soumis, approuvé ou annulé la commande (via
les timestamps, bien qu'aucun acteur ne soit enregistré).

**Verdict** : **EXPOSED** — pas de modèle de propriété. Ajouter un champ `created_by` aux commandes et
restreindre les lectures d'historique au propriétaire ou aux réviseurs autorisés.

---

### V-06 — Injection SQL via le champ `status` du body

**Attaque** : Intégrer des métacaractères SQL dans la valeur `status`.

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**Observé** :
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → `422` avant tout SQL.
2. Même si la vérification était contournée, le statut est passé comme valeur paramétrée `?`.

**Verdict** : **BLOCKED** — double couche : liste blanche d'enum + requêtes paramétrées.

---

### V-07 — Transition vers le même statut (idempotence)

**Attaque** : Envoyer une transition vers le statut actuel.

```json
// La commande est déjà 'submitted'
{"status": "submitted"}
```

**Observé** : `allowedTransitions()` pour `submitted` est `[approved, rejected, cancelled]`
— `submitted` n'est pas dans la liste. `canTransitionTo(submitted)` retourne `false` →
`409 Conflict`.

**Verdict** : **BLOCKED** — les auto-transitions sont implicitement rejetées par la machine à états.

---

### V-08 — Transitions concurrentes sur la même commande

**Attaque** : Envoyer deux requêtes de transition simultanées pour la même commande.

```
POST /orders/1/transitions {"status":"approved"}  // requête concurrente A
POST /orders/1/transitions {"status":"rejected"}  // requête concurrente B
```

**Observé** : Les deux requêtes récupèrent la commande (statut = `submitted`) avant qu'aucun
UPDATE ne s'exécute. Les deux voient `canTransitionTo()` = true. Les deux UPDATE — le second UPDATE
écrase le premier. Un enregistrement de journal de transition par requête est inséré, mais la commande
se termine dans le statut qui s'est exécuté en dernier. L'historique montre les deux transitions, ce qui est
incohérent (ex. `submitted → approved`, puis `submitted → rejected`).

**Verdict** : **EXPOSED** — envelopper la séquence `findById` + `canTransitionTo` + `UPDATE` +
`INSERT` dans une seule transaction pour prévenir les conditions de course.

---

### V-09 — Titre composé uniquement d'espaces

**Attaque** : Créer une commande avec un titre vide.

```json
{"title": "   "}
```

**Observé** : `trim($body['title'])` réduit à `""` → la vérification `title === ''` se déclenche →
`422 Unprocessable Entity`.

**Verdict** : **BLOCKED** — `trim()` avant la vérification de chaîne vide gère les entrées composées uniquement d'espaces.

---

### V-10 — Longueur de titre non bornée

**Attaque** : Créer une commande avec un titre très long.

```json
{"title": "A".repeat(100_000)}
```

**Observé** : Aucune limite de longueur n'est imposée — les titres très longs sont stockés dans la colonne `TEXT`
sans restriction.

**Verdict** : **EXPOSED** — ajouter une garde de longueur :
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## Résumé VULN

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| V-01 | Pas d'authentification | EXPOSED |
| V-02 | Valeur de statut invalide | BLOCKED |
| V-03 | Transition illégale depuis un état terminal | BLOCKED |
| V-04 | ID de commande non numérique | PARTIALLY BLOCKED |
| V-05 | Historique de transitions non scopé à l'appelant | EXPOSED |
| V-06 | Injection SQL via le body status | BLOCKED |
| V-07 | Auto-transition (même statut) | BLOCKED |
| V-08 | Condition de course sur les transitions concurrentes | EXPOSED |
| V-09 | Titre composé uniquement d'espaces | BLOCKED |
| V-10 | Longueur de titre non bornée | EXPOSED |

**Vulnérabilités réelles à corriger avant la production** :
1. **V-01 / V-05** — Ajouter l'authentification et l'autorisation (scopage de propriété)
2. **V-08** — Envelopper la transition dans une transaction
3. **V-10** — Ajouter une limite de longueur pour le titre
4. **V-04** — Ajouter une garde `ctype_digit()` pour les paramètres ID

---

## Howtos connexes

- [`approval-workflow.md`](approval-workflow.md) — Machine à états basée sur enum avec endpoints d'action séparés
- [`audit-trail.md`](audit-trail.md) — Patterns de journal d'audit append-only
- [`transactions.md`](transactions.md) — Envelopper les séquences multi-écriture dans une transaction
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — Prévention IDOR
