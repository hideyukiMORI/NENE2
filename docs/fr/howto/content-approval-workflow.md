# How-to : Workflow d'approbation de contenu

> **Référence FT** : FT248 (`NENE2-FT/flowlog`) — API de workflow d'approbation de contenu
> **ATK** : FT248 — test d'attaque cracker-mindset (ATK-01 à ATK-12)

Montre un cycle de vie de publication de posts où un `BackedEnum` `PostStatus` possède le graphe de transition via `canTransitionTo()`, les transitions invalides lancent `InvalidTransitionException → 409`, et le rejet comporte une raison optionnelle. Inclut une évaluation complète des attaques cracker-mindset.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/posts` | Créer un post (commence toujours en `draft`) |
| `GET` | `/posts` | Lister les posts (paginés, filtrables par statut) |
| `GET` | `/posts/{id}` | Obtenir un post spécifique |
| `POST` | `/posts/{id}/submit` | Transition : `draft → submitted` |
| `POST` | `/posts/{id}/approve` | Transition : `submitted → approved` |
| `POST` | `/posts/{id}/reject` | Transition : `submitted → rejected` (raison optionnelle) |

> **Routes d'action statiques avant paramétrées** : `/posts/{id}/submit`, `/approve`, `/reject` sont enregistrées avant `/posts/{id}` pour que les sous-chemins littéraux ne soient pas capturés par le segment paramétré.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` a une contrainte `CHECK` au niveau DB en filet de sécurité ; l'application valide via `PostStatus::canTransitionTo()` avant toute écriture. `reject_reason` est nullable — uniquement défini lors d'un rejet.

---

## `PostStatus` BackedEnum avec `canTransitionTo()`

Le graphe de transition d'état est possédé par l'enum elle-même :

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // états terminaux
        };
    }
}
```

Le graphe de transition :
```
draft → submitted → approved (terminal)
                 → rejected  (terminal)
```

`Approved` et `Rejected` sont des états terminaux — aucune autre transition n'est autorisée. Tenter d'approuver un post déjà approuvé lance `InvalidTransitionException`.

---

## Méthode de transition dans le repository

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

La méthode `transition()` est partagée par submit, approve et reject — chaque gestionnaire l'appelle avec un `$targetStatus` différent. `reject_reason` est `null` pour approve/submit, et optionnellement fourni pour reject.

---

## Filtre de statut avec `PostStatus::tryFrom()`

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` retourne `null` pour les valeurs de chaîne inconnues plutôt que de lancer une exception. La vérification explicite de `null` produit un `422` structuré avec un message d'erreur lisible listant les valeurs valides.

---

## Rejet avec raison optionnelle

`POST /posts/{id}/reject` accepte un champ `reason` optionnel :

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

Un corps vide `{}` ou un champ `reason` manquant résultent tous deux en `null`. Une chaîne de raison composée uniquement d'espaces est également normalisée en `null` via `trim()`. La raison est stockée dans la colonne nullable `reject_reason`.

---

## ATK — Test d'attaque cracker-mindset (FT248)

### ATK-01 — Pas d'authentification : n'importe qui peut approuver ou rejeter n'importe quel post

**Attaque** : Approuver ou rejeter un post sans aucune accréditation.

```bash
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/reject
```

**Observé** : Les deux réussissent avec `200 OK`. N'importe quel appelant peut pousser n'importe quel post dans n'importe quelle transition autorisée.

**Verdict** : **EXPOSÉ** — ajouter une authentification et une autorisation basée sur les rôles. Seuls les reviewers désignés devraient pouvoir approuver/rejeter. La soumission devrait nécessiter l'authentification de l'auteur du post.

---

### ATK-02 — Transition d'état invalide : approuver un draft

**Attaque** : Tenter d'approuver un post encore en statut `draft`.

```bash
curl -X POST http://localhost:8080/posts/1/approve
# le post 1 est en draft
```

**Observé** : `canTransitionTo(Approved)` retourne `false` pour `Draft` → `InvalidTransitionException` → `409 Conflict` avec le contexte from/to dans la réponse.

**Verdict** : **BLOQUÉ** — le graphe de transition possédé par l'enum empêche les sauts d'état illégaux.

---

### ATK-03 — Double approbation : approuver un post déjà approuvé

**Attaque** : Approuver un post une deuxième fois.

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/approve  # deuxième approbation
```

**Observé** : Troisième requête : `canTransitionTo(Approved)` depuis `Approved` → `false` → `409 Conflict`. Le post reste dans l'état `Approved`.

**Verdict** : **BLOQUÉ** — `Approved` est un état terminal ; l'enum retourne explicitement `false` pour toutes les transitions depuis les états terminaux.

---

### ATK-04 — Injection SQL via le titre ou le corps

**Attaque** : Incorporer des métacaractères SQL.

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**Observé** : Les valeurs sont liées via des placeholders `?` paramétrés. Le payload d'injection est stocké comme texte littéral.

**Verdict** : **BLOQUÉ** — les requêtes paramétrées empêchent l'injection SQL.

---

### ATK-05 — Valeur de filtre de statut invalide

**Attaque** : Passer un statut inconnu à l'endpoint de liste.

```
GET /posts?status=hacked
GET /posts?status=published
```

**Observé** : `PostStatus::tryFrom('hacked')` retourne `null` → `ValidationException` → `422 Unprocessable Entity` avec la liste des statuts valides.

**Verdict** : **BLOQUÉ** — `BackedEnum::tryFrom()` + vérification explicite de null rejette les valeurs de statut inconnues.

---

### ATK-06 — Usurpation d'auteur

**Attaque** : Créer un post en se réclamant d'un auteur privilégié.

```json
{"title": "Official announcement", "author": "admin"}
```

**Observé** : `201 Created` — le champ `author` est pris mot pour mot depuis le corps de la requête sans vérification. N'importe quelle chaîne est acceptée.

**Verdict** : **EXPOSÉ** — `author` est fourni par l'utilisateur sans liaison cryptographique. En production, dériver `author` depuis la session/token authentifié, jamais depuis le corps de la requête.

---

### ATK-07 — Affectation massive : injecter `status` à la création

**Attaque** : Définir `status` sur `approved` directement pendant la création.

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**Observé** : `createPost()` ignore tout champ `status` dans le corps — il insère toujours `PostStatus::Draft->value`. La clé supplémentaire est silencieusement ignorée.

**Verdict** : **BLOQUÉ** — le contrôleur construit l'INSERT avec une valeur `PostStatus::Draft->value` codée en dur ; aucun champ du corps ne peut la surpasser.

---

### ATK-08 — Payload XSS dans le titre, le corps ou l'auteur

**Attaque** : Stocker une balise script.

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**Observé** : Le contenu est stocké tel quel et retourné mot pour mot en JSON. L'API n'encode pas la sortie HTML.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — les APIs JSON retournent du contenu brut. La couche de rendu doit sanitiser avant d'insérer dans le HTML.

---

### ATK-09 — ID de post non numérique

**Attaque** : Utiliser une chaîne ou un float comme `{id}`.

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**Observé** : `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → aucune ligne → `PostNotFoundException` → `404 Not Found`.
- `1.5` → `findById(1)` → si le post 1 existe, sa transition est déclenchée.

**Verdict** : **PARTIELLEMENT BLOQUÉ** — les chaînes non numériques mappent sur 404. Les chaînes de float sont silencieusement tronquées. Ajouter `ctype_digit()` pour une validation stricte des IDs.

---

### ATK-10 — Titre vide ou auteur vide

**Attaque** : Soumettre avec des champs vides.

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**Observé** : Les vérifications `trim($body['title']) === ''` et `trim($body['author']) === ''` se déclenchent → `ValidationException` → `422`.

**Verdict** : **BLOQUÉ** — trim + vérifications de chaîne vide couvrent les valeurs vides et celles composées uniquement d'espaces.

---

### ATK-11 — Rejet sans raison fournie

**Attaque** : Rejeter avec un corps vide ou sans champ `reason`.

```bash
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject -d '{}'
curl -X POST http://localhost:8080/posts/1/reject -d '{"reason": ""}'
```

**Observé** : Les trois cas produisent `null` pour `reject_reason`. Le rejet sans raison est accepté — la colonne est nullable.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — `reject_reason` est optionnel. Pour les workflows de production nécessitant une raison de rejet obligatoire, ajouter `if ($reason === null) → 422`.

---

### ATK-12 — Rejet d'un post déjà rejeté (double rejet)

**Attaque** : Tenter de rejeter un post déjà rejeté.

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject  # deuxième rejet
```

**Observé** : `canTransitionTo(Rejected)` depuis `Rejected` → `false` → `409 Conflict`.

**Verdict** : **BLOQUÉ** — `Rejected` est un état terminal ; l'enum retourne explicitement `false` pour toutes les transitions depuis les états terminaux.

---

## Récapitulatif ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Pas d'authentification sur approve/reject | EXPOSÉ |
| ATK-02 | Transition invalide (approuver un draft) | BLOQUÉ |
| ATK-03 | Double approbation | BLOQUÉ |
| ATK-04 | Injection SQL via titre/corps | BLOQUÉ |
| ATK-05 | Valeur de filtre de statut invalide | BLOQUÉ |
| ATK-06 | Usurpation d'auteur | EXPOSÉ |
| ATK-07 | Affectation massive du statut à la création | BLOQUÉ |
| ATK-08 | Payload XSS dans le contenu | ACCEPTÉ PAR CONCEPTION |
| ATK-09 | ID de post non numérique | PARTIELLEMENT BLOQUÉ |
| ATK-10 | Titre ou auteur vide | BLOQUÉ |
| ATK-11 | Rejet sans raison (optionnel) | ACCEPTÉ PAR CONCEPTION |
| ATK-12 | Double rejet | BLOQUÉ |

**Vraies vulnérabilités à corriger avant la production** :
1. **ATK-01** — Ajouter authentification et autorisation basée sur les rôles (rôle reviewer pour approve/reject)
2. **ATK-06** — Dériver `author` depuis l'identité vérifiée, jamais depuis le corps de la requête
3. **ATK-09** — Ajouter une garde `ctype_digit()` pour les paramètres de chemin ID

---

## Guides associés

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — transition d'état avec historique d'audit et InvalidTransitionException
- [`approval-workflow.md`](approval-workflow.md) — demande d'approbation avec plusieurs approbateurs
- [`step-workflow-approval.md`](step-workflow-approval.md) — workflow multi-étapes avec étapes ordonnées
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — patterns de cycle de vie draft/publication
