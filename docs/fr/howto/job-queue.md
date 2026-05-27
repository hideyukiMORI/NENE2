# File de travaux en arrière-plan avec relance et idempotence

Ce guide couvre l'implémentation d'une file de travaux en arrière-plan persistante dans les applications NENE2. Le pattern supporte les files de priorité, la relance automatique avec compteurs de backoff, et la création de travaux idempotente.

## Concepts fondamentaux

Une file de travaux découple le travail des cycles de requêtes HTTP. Le gestionnaire HTTP met en file un travail et retourne immédiatement ; un processus worker séparé réclame et exécute les travaux.

États clés : `pending` → `running` → `completed` ou `failed` (avec remise en file automatique quand des relances restent).

## Conception du schéma

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

`idempotency_key UNIQUE` est appliqué au niveau base de données, pas juste niveau application. Cela prévient les conditions de course où deux requêtes HTTP concurrentes passent toutes deux la vérification application et tentent toutes deux un INSERT.

## Cycle de vie du travail

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running (worker_id, claimed_at définis)
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) si des relances restent
                            → failed si retry_count >= max_retries
```

## Logique de relance

Quand un worker appelle `fail`, le repository décide de remettre en file ou d'échouer définitivement :

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

Le champ `error` stocke la raison du dernier échec même lors de la remise en file, donnant aux opérateurs une piste de diagnostic sur l'enregistrement du travail.

## Idempotence

Passer une `idempotency_key` lors de la création d'un travail pour rendre l'opération sûre à retenter depuis le client HTTP :

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- Premier appel : `201 Created` — travail créé.
- Appels suivants avec la même clé : `200 OK` — travail existant retourné, aucun doublon créé.

La contrainte `UNIQUE` de la base de données sur `idempotency_key` est le filet de sécurité. Vérifier au niveau application d'abord pour éviter de dépendre de la gestion d'exceptions comme chemin de code principal :

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## File de priorité

Les travaux sont réclamés par priorité DESC, puis created_at ASC (FIFO dans un niveau) :

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

Niveaux de priorité (valeurs entières stockées, libellés humains exposés) :

| Libellé  | Valeur |
|----------|--------|
| low      | 0      |
| medium   | 10     |
| high     | 20     |
| critical | 30     |

## Pattern worker

Les workers sont des processus sans état qui bouclent : réclamer → exécuter → terminer ou échouer.

```
boucle:
  job = POST /jobs/claim { worker_id: "worker-1" }
  si job est null → dormir, continuer

  essayer:
    exécuter(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  attraper erreur:
    POST /jobs/{job.id}/fail { error: erreur.message }
```

Les workers s'identifient avec `worker_id` pour que les opérateurs puissent voir quel worker détient un travail et diagnostiquer les workers bloqués.

## Détection de travaux bloqués

Les travaux en statut `running` avec un horodatage `claimed_at` plus vieux qu'un seuil sont bloqués (worker planté). Un processus de maintenance devrait les détecter et les remettre en file :

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- plus vieux que le seuil de timeout
  AND retry_count < max_retries
```

## max_retries=0 pour les travaux non relançables

Certains travaux ne doivent pas être relancés (ex: paiements, webhooks externes où le replay causerait des dommages). Définir `max_retries: 0` lors de la création :

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

Le premier appel `fail` transite immédiatement le travail à `failed`.

## Décisions de conception

**Pourquoi la logique de relance dans le repository, pas le worker ?** La décision de remettre en file est un invariant de couche données (retry_count < max_retries), pas de la logique métier. La placer dans le repository garde les workers simples et prévient l'incohérence des workers qui implémentent la vérification différemment.

**Pourquoi la contrainte UNIQUE sur idempotency_key au niveau DB ?** Les vérifications niveau application ont des conditions de course sous des requêtes concurrentes. La contrainte DB est la garde faisant autorité ; la vérification niveau application est une optimisation pour éviter de dépendre de la gestion d'exceptions.

**Pourquoi stocker la priorité comme entier ?** Permet d'ajouter des niveaux de priorité intermédiaires plus tard sans changements de schéma. Le libellé lisible par l'humain est dérivé, pas stocké.
