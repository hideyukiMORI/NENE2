# HOWTO : Journal d'audit — Enregistrer qui a changé quoi

> **Référence FT** : FT268 (`NENE2-FT/auditlog`) — journal d'audit en ajout seul : extraction d'acteur JWT, snapshots de payload avant/après, table d'audit immuable, lacune de lecture d'audit non authentifiée
>
> **Évaluation ATK** : ATK-01 à ATK-12 inclus à la fin de ce document.

Ce guide montre comment implémenter un journal d'audit en ajout seul dans une application NENE2.
Un journal d'audit enregistre chaque opération de création, mise à jour et suppression avec l'acteur (depuis les claims JWT),
la ressource et un snapshot du payload. Ces enregistrements sont immuables : l'API n'expose jamais d'endpoints UPDATE ou DELETE
pour la table d'audit.

---

## Schéma de base de données

```sql
-- Pas de FK sur actor_id ou resource_id :
-- les enregistrements d'audit doivent survivre à la suppression des sujets qu'ils décrivent.
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- ex. 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- Ajouter des index pour les patterns de requête les plus courants
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

Choix de conception clés :
- **Pas de contraintes FK** — les enregistrements d'audit survivent à leurs sujets. Si une tâche est supprimée, son historique d'audit doit rester.
- **Immuable par conception** — ne jamais ajouter de chemins SQL UPDATE ou DELETE pour cette table.
- **`action` comme verbe typé** — utiliser des verbes au passé (`created`, `updated`, `deleted`) pour rendre les entrées de log auto-descriptives.

---

## DTO AuditEntry et AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC, pas occurred_at DESC : les horodatages à précision seconde entrent en collision
            // quand deux opérations se produisent dans la même seconde.
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **`ORDER BY id DESC` et non `occurred_at DESC` :** `occurred_at` a une précision à la seconde.
> Deux opérations dans la même seconde obtiennent des horodatages identiques, rendant l'ordre de tri imprévisible.
> L'`id` auto-incrémenté préserve l'ordre d'insertion de façon fiable.

---

## Enregistrement des audits dans le gestionnaire

Enregistrer les événements d'audit dans le gestionnaire (équivalent UseCase), pas dans le Repository.
Enregistrer dans le Repository perd le contexte métier ("quelle opération a déclenché ceci ?").

### Création — enregistrer le snapshot initial

```php
$task = $this->tasks->create($title, $body, $actorId);

// Audit : ne PAS inclure actor_id dans le payload — il est déjà dans l'enregistrement d'audit lui-même.
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### Mise à jour — enregistrer avant/après pour la visibilité des différences

```php
$before = $this->tasks->findById($id);
// ... vérification de propriété, validation ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### Suppression — snapshot avant suppression

```php
$task = $this->tasks->findById($id);
// ... vérification de propriété ...
$this->tasks->delete($id);

// Enregistrer APRÈS la suppression — la ligne de tâche est partie, mais l'audit reste.
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## Acteur depuis les claims JWT

Toujours dériver l'acteur du JWT vérifié, jamais du corps de la requête.

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` est défini par `BearerTokenMiddleware` après validation du token.
Un client ne peut pas fournir un faux `actor_id` dans le corps de la requête et l'avoir enregistré.

---

## Exclusion des champs sensibles

**Ne jamais mettre des mots de passe, tokens ou IDs internes dans le payload.**

```php
// ❌ Divulgue des données sensibles et est redondant
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // NE JAMAIS inclure
    'actor_id'      => $actorId,              // redondant
]);

// ✅ Seulement les attributs visibles par le métier
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## API d'audit immuable — pas d'endpoints d'écriture

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST, PUT, DELETE sont intentionnellement absents
}
```

---

## Vérification de propriété avant chaque écriture (et avant l'audit)

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Retourner 404 au lieu de 403 pour éviter de confirmer l'existence de la ressource aux acteurs non autorisés.
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Seulement maintenant : modifier + auditer
```

---

## Interroger le journal d'audit

```php
// Historique d'une ressource spécifique
GET /audit/task/42

// Tous les événements d'un acteur
GET /audit?actor_id=7

// Toutes les suppressions entre les types de ressources
GET /audit?action=deleted

// Pagine en toute sécurité
GET /audit?limit=20&offset=40
```

---

## Considérations de sécurité

| Risque | Atténuation |
|---|---|
| Suppression du journal d'audit | Pas d'endpoint DELETE. Au niveau table : refuser la permission DELETE à l'utilisateur DB de l'application si possible |
| Usurpation d'acteur | L'acteur vient toujours de `nene2.auth.claims`, jamais du corps de la requête |
| Payload sensible | Exclure explicitement les mots de passe, tokens et clés internes du payload |
| IDOR (lectures d'audit cross-utilisateur) | Restreindre `GET /audit` aux rôles admin (combiner avec RBAC) ; ou filtrer par actor_id du requérant |
| Attaque par timing / énumération d'utilisateurs | Utiliser un vrai hash Argon2id pré-calculé comme factice, pas une chaîne malformée |
| DoS `LIMIT -1` | Limiter : `max(1, min((int) $limit, 100))` |

---

## Le hash factice doit être un vrai hash Argon2id

Un hash factice malformé cause le retour immédiat de `false` par `password_verify()` (sans exécuter le KDF),
créant une différence de timing ~20 000× qui permet à un attaquant d'énumérer les adresses email valides.

```php
// ❌ Malformé — le KDF est ignoré, retourne false en ~0.001ms
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// ✅ Vrai hash pré-calculé — le KDF s'exécute à plein coût (~180ms)
// Générer une fois : password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> Ce pattern de hash factice a été documenté pour la première fois dans [password-hashing.md](password-hashing.md).
> **Le même principe s'applique partout où `password_verify()` est appelé sur un utilisateur potentiellement absent.**

---

## Évaluation ATK (FT268)

Test d'attaque par esprit de cracker contre `NENE2-FT/auditlog`. La surface : CRUD de tâches authentifié par JWT + lecture de journal d'audit non authentifiée.

### ATK-01 — Attaque JWT Algorithme None 🚫 BLOQUÉ

**Attaque** : Forger un JWT avec `"alg":"none"` et pas de signature, claim `sub` arbitraire.
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signature: (vide)
```
**Résultat** : `LocalBearerTokenVerifier` valide en utilisant HMAC-HS256 contre le secret configuré. Les tokens sans signature valide sont rejetés — `alg:none` n'est pas accepté. → **401 Unauthorized**

---

### ATK-02 — Falsification de signature JWT 🚫 BLOQUÉ

**Attaque** : Prendre un JWT valide, modifier le champ `sub` vers l'ID d'un autre utilisateur (ex. `1` → `2`), ré-encoder sans re-signer.
**Résultat** : La signature HMAC-HS256 ne correspond plus au payload modifié. `LocalBearerTokenVerifier` rejette le token. → **401 Unauthorized**

---

### ATK-03 — Rejeu de token JWT expiré 🚫 BLOQUÉ

**Attaque** : Rejouer un JWT capturé après que son horodatage `exp` soit passé.
**Résultat** : `BearerTokenMiddleware` / `LocalBearerTokenVerifier` vérifie `exp`. Les tokens au-delà de l'expiration sont rejetés. → **401 Unauthorized**

---

### ATK-04 — IDOR : Accéder à la tâche d'un autre utilisateur via ID ✅ BLOQUÉ

**Attaque** : S'authentifier en tant qu'Utilisateur A (sub=1), puis appeler `PUT /tasks/3` où la tâche 3 appartient à l'Utilisateur B (sub=2).
**Résultat** : Le gestionnaire de route de tâche lit `task->actorId` et compare avec `actorId` depuis les claims JWT. L'incompatibilité retourne → **404 Not Found** (l'existence de la ressource n'est pas confirmée à l'attaquant).

---

### ATK-05 — IDOR : Supprimer la tâche d'un autre utilisateur ✅ BLOQUÉ

**Attaque** : S'authentifier en tant qu'Utilisateur A, appeler `DELETE /tasks/7` où la tâche 7 appartient à l'Utilisateur B.
**Résultat** : Même garde de propriété qu'ATK-04. `task->actorId !== $actorId` → **404 Not Found**.

---

### ATK-06 — Injection d'ID d'acteur via le corps de la requête ✅ BLOQUÉ

**Attaque** : `POST /tasks` avec le corps `{"title":"Injected","actor_id":999}`.
**Résultat** : Le contrôleur ignore complètement `body['actor_id']`. L'enregistrement d'audit utilise `actorId` depuis `nene2.auth.claims['sub']` (JWT). La tâche est créée sous l'acteur authentifié — `actor_id:999` n'a aucun effet.

---

### ATK-07 — Lecture de journal d'audit non authentifiée ⚠️ EXPOSÉ

**Attaque** : `GET /audit` sans en-tête Authorization.
**Résultat** : Les endpoints de lecture du journal d'audit (`GET /audit`, `GET /audit/{type}/{id}`) **ne sont pas protégés par `BearerTokenMiddleware`**. Le middleware exclut seulement `/auth/login` ; cependant, le registrar de routes d'audit attache des routes sans nécessiter d'authentification. N'importe quel appelant non authentifié peut lire l'historique complet de tous les acteurs et toutes les ressources.

**Impact** : Divulgation complète de : qui a fait quoi, quand, à quelle ressource, incluant les snapshots de payload avant/après. Pour une application multi-tenant, c'est une divulgation d'information critique.

**Recommandation** : Restreindre les endpoints d'audit aux JWT scopés admin (ex. `claims['role'] === 'admin'`), ou au minimum nécessiter tout JWT valide. Ajouter le préfixe audit aux routes protégées par `BearerTokenMiddleware`.

---

### ATK-08 — Énumération cross-acteur du journal d'audit via ?actor_id ⚠️ EXPOSÉ

**Attaque** : `GET /audit?actor_id=2` (ou énumérer 1..N) — lit toutes les entrées d'audit pour n'importe quel actor_id.
**Résultat** : Pas de vérification d'autorisation sur le filtre `actor_id`. L'attaquant énumère tous les IDs utilisateur et récupère leur historique d'audit complet. Chaîné depuis ATK-07 (accès non authentifié).
**Recommandation** : Si l'audit est restreint aux utilisateurs authentifiés seulement (pas admin), filtrer par le `sub` de l'utilisateur authentifié — les appelants ne peuvent pas interroger les logs d'autres acteurs. Les admins voient tout.

---

### ATK-09 — Injection SQL dans les paramètres de recherche d'audit 🚫 BLOQUÉ

**Attaque** : `GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**Résultat** : `$action` et `$resourceType` sont liés comme paramètres `?` dans la requête SQL. Pas d'interpolation de chaîne. SQLite reçoit `WHERE action = ?` avec la chaîne injectée littérale comme valeur — ce qui retourne simplement 0 lignes. La table est sûre. → **200 OK (vide)**

---

### ATK-10 — Limite -1 / Grande limite DoS ✅ BLOQUÉ

**Attaque** : `GET /audit?limit=-1` ou `GET /audit?limit=99999`.
**Résultat** : `max(1, min((int) ($q['limit'] ?? 50), 100))` limite à `[1, 100]`. Les limites négatives et surdimensionnées sont silencieusement limitées. → **200 OK (max 100 entrées)**

---

### ATK-11 — Brute-force de connexion (pas de limitation de débit) ⚠️ EXPOSÉ

**Attaque** : Tentatives `POST /auth/login` séquentielles rapides avec le même email et différents mots de passe.
**Résultat** : Pas de limitation de débit, pas de blocage, pas de CAPTCHA. Un attaquant peut itérer des mots de passe indéfiniment. Le KDF Argon2id ralentit chaque tentative à ~180ms, rendant le brute-force impraticable pour les mots de passe forts mais toujours faisable pour les faibles.
**Recommandation** : Ajouter `ThrottleMiddleware` sur `/auth/login` (ex. 5 tentatives / 15 min par IP). Logger les tentatives échouées avec request_id pour la surveillance.

---

### ATK-12 — Injection de valeur de statut arbitraire ⚠️ EXPOSÉ

**Attaque** : `PUT /tasks/1` avec le corps `{"status":"<script>alert(1)</script>"}` ou `{"status":"admin_override"}`.
**Résultat** : Le gestionnaire accepte n'importe quelle chaîne non vide comme `status`. Le repository l'écrit verbatim. La tâche est mise à jour avec `status="<script>alert(1)</script>"`. Pas de validation d'enum, pas d'allowlist.
**Impact** : XSS stocké si le statut est rendu dans un navigateur sans échappement. Modèle de domaine corrompu si la logique métier suppose que le statut est dans `{open, closed, in_progress}`.
**Recommandation** : Valider le statut contre une allowlist ou un BackedEnum PHP :
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|--------|--------|
| ATK-01 | JWT `alg:none` | 🚫 BLOQUÉ |
| ATK-02 | Falsification de signature JWT | 🚫 BLOQUÉ |
| ATK-03 | Rejeu de JWT expiré | 🚫 BLOQUÉ |
| ATK-04 | IDOR : accès à la tâche d'un autre utilisateur | ✅ BLOQUÉ |
| ATK-05 | IDOR : suppression de la tâche d'un autre utilisateur | ✅ BLOQUÉ |
| ATK-06 | Injection d'ID d'acteur via le corps | ✅ BLOQUÉ |
| ATK-07 | Lecture de journal d'audit non authentifiée | ⚠️ EXPOSÉ |
| ATK-08 | Énumération d'audit cross-acteur | ⚠️ EXPOSÉ |
| ATK-09 | Injection SQL dans la recherche d'audit | 🚫 BLOQUÉ |
| ATK-10 | Limite -1 / limite surdimensionnée DoS | ✅ BLOQUÉ |
| ATK-11 | Brute-force de connexion (pas de limitation de débit) | ⚠️ EXPOSÉ |
| ATK-12 | Injection de valeur de statut arbitraire | ⚠️ EXPOSÉ |

**9 BLOQUÉS / SÛRS, 4 EXPOSÉS** (ATK-07, 08 chaînés depuis la même lacune de lecture d'audit non authentifiée).

Le résultat critique est **ATK-07** : les endpoints du journal d'audit n'ont aucune garde d'authentification, exposant l'historique complet de l'activité des acteurs à tout appelant non authentifié. ATK-12 (allowlist de statut) et ATK-11 (limitation de débit) sont des lacunes de renforcement standard. Aucun vecteur d'injection SQL ou de falsification JWT n'a été trouvé.
