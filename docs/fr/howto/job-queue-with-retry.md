# How-to : File de travaux en arrière-plan avec relance et idempotence

> **Référence FT** : FT255 (`NENE2-FT/queuelog`) — File de travaux en arrière-plan avec relance et idempotence
> **VULN** : FT255 — évaluation des vulnérabilités (V-01 à V-10)

Démontre une file de travaux persistante s'appuyant sur SQLite. Les travaux ont des niveaux de priorité, transitent à travers une machine à états `pending → running → completed|failed`, et supportent la relance automatique sur échec avec une limite de relance configurable. Une clé d'idempotence prévient la création de travaux dupliqués. Inclut une évaluation complète des vulnérabilités.

---

## Routes

| Méthode | Chemin                    | Description                                         |
|---------|---------------------------|-----------------------------------------------------|
| `POST`  | `/jobs`                   | Mettre en file (clé d'idempotence optionnelle)      |
| `GET`   | `/jobs`                   | Lister les travaux (filtrable par statut)           |
| `GET`   | `/jobs/{id}`              | Obtenir un travail unique                           |
| `POST`  | `/jobs/claim`             | Le worker réclame le prochain travail pending       |
| `POST`  | `/jobs/{id}/complete`     | Le worker marque un travail comme terminé           |
| `POST`  | `/jobs/{id}/fail`         | Le worker marque un travail comme échoué (avec relance) |

> **Ordre des routes** : `/jobs/claim` doit être enregistré avant `/jobs/{id}` pour que le segment littéral `claim` ne soit pas capturé comme paramètre de chemin.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key TEXT UNIQUE` applique l'unicité au niveau DB. `claimed_at`, `worker_id` et `error` sont nullable — définis seulement quand un travail entre dans l'état `running` ou `failed`.

---

## Priorité : enum numérique pour le tri SQL

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

Les valeurs numériques permettent un tri direct `ORDER BY priority DESC`. Un enum chaîne nécessiterait une expression `CASE` ou une table de lookup de priorité. Les écarts entre valeurs (0, 10, 20, 30) permettent d'insérer des niveaux de priorité futurs sans renumérotation.

---

## Claim : FIFO haute-priorité

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` choisit le travail de plus haute priorité, et parmi les travaux de priorité égale, le plus ancien (FIFO). `LIMIT 1` assure qu'un seul travail est sélectionné.

Ce claim est **non-atomique** (voir V-06). Pour une configuration mono-worker c'est acceptable. Pour des workers concurrents, utiliser `BEGIN IMMEDIATE` de SQLite + `SELECT … LIMIT 1 FOR UPDATE` (MySQL) ou un UPDATE conditionnel `status = 'pending' AND id = ?` avec vérification de `changes()`.

---

## Logique de relance : remettre en file vs échouer définitivement

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // Remettre en file : réinitialiser à pending avec retry_count incrémenté
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // Épuisé : échec permanent
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` vérifie si le travail a des relances restantes. Si oui, le travail retourne à `pending` (avec `claimed_at`/`worker_id` effacés) et peut être réclamé à nouveau. Si épuisé, il transite vers l'état terminal `failed`.

---

## Clé d'idempotence : déduplication à la création

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

Si un travail avec la même `idempotency_key` existe déjà, le travail existant est retourné avec `200 OK` au lieu de créer un doublon. Un nouveau travail retourne `201 Created`. La contrainte `UNIQUE` sur `idempotency_key` fournit une garde de second niveau contre les conditions de course.

---

## Machine à états

```
pending ──(claim)──→ running ──(complete)──→ completed (terminal)
                        │
                        └──(fail, relances restantes)──→ pending
                        │
                        └──(fail, relances épuisées)──→ failed (terminal)
```

`complete()` et `fail()` vérifient tous deux `status = Running` avant d'appliquer la transition. Un retour `null` de l'un ou l'autre indique que le travail n'a pas été trouvé ou n'est pas dans l'état correct, mappé à `409 Conflict` par le contrôleur.

---

## VULN — Évaluation des vulnérabilités (FT255)

### V-01 — Pas d'authentification : tout appelant peut mettre en file, réclamer ou terminer n'importe quel travail

**Risque** : Tous les endpoints sont non authentifiés.

**Impact** : Un attaquant peut mettre en file des travaux arbitraires avec n'importe quel type et payload, réclamer des travaux légitimes pour empêcher les vrais workers de les traiter, et marquer des travaux comme terminés ou échoués sans exécuter le vrai travail.

**Verdict** : **EXPOSED** — ajouter de l'authentification. Les endpoints worker (`/jobs/claim`, `/jobs/{id}/complete`, `/jobs/{id}/fail`) devraient nécessiter une clé API worker ou JWT. La mise en file devrait être restreinte aux producteurs authentifiés.

---

### V-02 — Le type de travail est n'importe quelle chaîne : aucune allowlist appliquée

**Risque** : `type` accepte n'importe quelle chaîne non vide. Un attaquant peut mettre en file des travaux de types que le système ne gère pas (ex: `"DROP TABLE"`, `"shutdown"`, `"admin_task"`).

**Impact** : Si le worker dispatche basé sur `type` (ex: `match($job->type) { ... }`), les types inconnus sont silencieusement ignorés ou déclenchent des gestionnaires par défaut inattendus.

**Verdict** : **EXPOSED** — valider `type` contre une allowlist de types de travaux connus. Retourner `422` pour les types inconnus.

---

### V-03 — Manipulation de priorité : l'attaquant définit la priorité `critical`

**Attaque** : Mettre en file un travail avec `"priority": "critical"` pour préempter tous les travaux existants.

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**Observé** : La requête réussit avec `201`. Le travail spam est maintenant en tête de file et est réclamé avant tout travail légitime haute priorité.

**Verdict** : **EXPOSED** — restreindre qui peut définir des niveaux de priorité élevés. Les producteurs sans confiance élevée devraient être limités à `low` ou `medium`. Rejeter `critical` des appelants non authentifiés.

---

### V-04 — Usurpation du worker_id : n'importe qui peut réclamer avec n'importe quel worker_id

**Attaque** : Soumettre un claim avec `"worker_id": "legitimate-worker-1"`.

**Observé** : Le claim réussit — le travail est assigné au worker_id usurpé. Le worker légitime ne peut pas distinguer cela de ses propres claims.

**Verdict** : **EXPOSED** — `worker_id` devrait être dérivé d'une identité authentifiée (clé API → nom du worker), pas fourni par l'appelant. Ne jamais faire confiance aux worker_ids fournis par l'appelant.

---

### V-05 — Prise de contrôle de l'état du travail : tout appelant peut terminer/échouer n'importe quel travail en cours

**Attaque** : Terminer ou échouer un travail qu'un autre worker a réclamé.

**Observé** : `complete()` ne vérifie que `status = Running`. Aucune vérification de propriété ne vérifie que l'appelant est le worker qui a réclamé le travail.

**Verdict** : **EXPOSED** — ajouter une condition `WHERE worker_id = $requestWorkerId` à `complete()` et `fail()`. Retourner `409` si le worker ne possède pas le travail.

---

### V-06 — Condition de course sur le claim : SELECT + UPDATE non-atomique

**Risque** : `claim()` effectue `SELECT … LIMIT 1` puis `UPDATE … WHERE id = ?`. Deux workers concurrents pourraient sélectionner le même travail avant que l'un des deux le mette à jour.

**Attaque** : Deux workers voient tous deux le travail 1 comme `pending`, les deux le mettent à jour à `running`, les deux exécutent le travail. La seconde mise à jour gagne la colonne `worker_id`, mais le travail s'exécute deux fois.

**Verdict** : **EXPOSED** — utiliser un pattern de claim atomique :
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
Puis vérifier `changes() = 1`. Sur SQLite, envelopper dans `BEGIN IMMEDIATE` empêche les lectures concurrentes de voir la même ligne pending.

---

### V-07 — Taille du payload : pas de limite sur le payload du travail

**Risque** : `payload` accepte n'importe quel objet JSON sans validation de taille.

**Impact** : Un payload de plusieurs mégaoctets consomme du stockage et de la mémoire quand le travail est récupéré par les workers ou listé dans la file.

**Verdict** : **EXPOSED** — ajouter une vérification de taille de payload (ex: `strlen($json) > 65536 → 422`). S'appuyer sur le middleware de taille de requête comme limite externe.

---

### V-08 — Injection SQL via type ou payload 🚫 BLOCKED

**Attaque** : Intégrer des métacaractères SQL dans les champs `type` ou `payload`.

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**Observé** : Les valeurs sont liées comme placeholders paramétrés `?`. L'injection est stockée comme texte littéral dans la base de données ; le SQL n'est jamais exécuté.

**Verdict** : **BLOCKED** — les requêtes paramétrées préviennent l'injection SQL.

---

### V-09 — Collision de clé d'idempotence : l'attaquant devine une clé légitime

**Attaque** : Deviner ou énumérer la clé d'idempotence d'un appelant légitime et soumettre le même travail avec un payload différent.

**Observé** : Le travail existant est retourné inchangé. La requête de l'attaquant ne crée PAS un nouveau travail — la contrainte `UNIQUE` et la vérification au niveau application le préviennent tous deux. L'attaquant apprend que le travail existe (via le `200` retourné) mais ne peut pas le modifier.

**Verdict** : **PARTIELLEMENT BLOCKED** — la création de doublon est bloquée. Cependant, l'attaquant peut énumérer l'existence des travaux en sondant les clés d'idempotence. Utiliser des clés aléatoires longues (ex: UUID v4) pour rendre l'énumération infaisable. La réponse à une clé correspondante divulgue que le travail existe et son statut.

---

### V-10 — Divulgation de message d'erreur dans les travaux échoués

**Risque** : Les messages d'erreur worker de `POST /jobs/{id}/fail` sont stockés dans la colonne `error` et retournés dans toutes les réponses liste/get.

**Impact** : Les messages d'erreur internes (traces de pile, chaînes de connexion DB, chemins de fichiers internes) soumis par les workers sont visibles pour tout appelant de `GET /jobs`.

**Verdict** : **EXPOSED** — assainir les messages d'erreur avant stockage (supprimer les détails sensibles). Limiter la visibilité du champ `error` aux rôles admin dans les réponses liste/get.

---

## Résumé VULN

| # | Vulnérabilité | Verdict |
|---|---------------|---------|
| V-01 | Pas d'authentification sur aucun endpoint | EXPOSED |
| V-02 | Type de travail : pas d'allowlist | EXPOSED |
| V-03 | Manipulation de priorité (travaux critiques) | EXPOSED |
| V-04 | Usurpation du worker ID | EXPOSED |
| V-05 | Prise de contrôle de l'état du travail (pas de vérification de propriété) | EXPOSED |
| V-06 | Condition de course sur le claim (non-atomique) | EXPOSED |
| V-07 | Taille du payload : pas de limite | EXPOSED |
| V-08 | Injection SQL via type/payload | BLOCKED |
| V-09 | Collision / énumération de clé d'idempotence | PARTIELLEMENT BLOCKED |
| V-10 | Divulgation de message d'erreur dans la liste | EXPOSED |

**Corrections critiques avant production** :
1. **V-01** — Ajouter de l'authentification pour les producteurs et les workers (niveaux d'auth séparés)
2. **V-02** — Valider `type` contre une allowlist connue
3. **V-03 / V-04 / V-05** — Dériver l'identité du worker de la session authentifiée ; ajouter une vérification de propriété `worker_id`
4. **V-06** — Utiliser le claim atomique (`UPDATE … WHERE … AND status='pending'` + `changes() = 1`)
5. **V-10** — Assainir les messages d'erreur worker avant stockage ; restreindre la visibilité

---

## How-tos associés

- [`notification-queue.md`](notification-queue.md) — API de file de notifications (notiflog FT214)
- [`idempotency.md`](idempotency.md) — pattern de clé d'idempotence pour les requêtes POST
- [`dead-letter-queue.md`](dead-letter-queue.md) — file de lettres mortes avec relance (deadletterlog FT72)
- [`transactions.md`](transactions.md) — envelopper les opérations de file dans des transactions
