# How-to : Isolation multi-tenant

Ce guide couvre la construction d'une API multi-tenant avec NENE2 où les données de chaque tenant sont strictement isolées. Sauter n'importe quelle étape crée un IDOR silencieux (Insecure Direct Object Reference) qui expose les données de tous les tenants.

---

## La règle fondamentale : filtre `tenant_id` dans chaque requête

Omettre le filtre tenant d'une seule requête retourne silencieusement les données de tous les tenants :

```sql
-- ❌ Pas de filtre tenant — retourne les enregistrements de tous les tenants
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ Toujours inclure le filtre tenant
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

Nommer les méthodes de repository avec un suffixe `ForTenant` pour rendre le contrat visible :

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

Le suffixe `ForTenant` oblige les appelants à fournir l'ID de tenant. Il rend aussi la revue de code directe : toute méthode sans ce suffixe est candidate à une revue IDOR.

---

## Incorporer `tenant_id` dans le JWT

Résoudre l'appartenance au tenant une seule fois à la connexion et l'incorporer dans le token. Cela évite un aller-retour DB à chaque requête et garde le contexte tenant à l'abri des falsifications (la signature JWT le couvre).

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // doit être int
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

Extraire et valider le claim dans les handlers. Utiliser `is_int()` — `is_string()` seul n'est pas sûr ; MySQL/PostgreSQL peut rejeter silencieusement les comparaisons chaîne-vers-int :

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // déclencher 401
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` stocke les claims vérifiés dans `nene2.auth.claims`. Le middleware rejette les tokens expirés, les signatures falsifiées, et les attaques `alg: none` avant que le handler ne s'exécute.

---

## Retourner 404 pour l'accès cross-tenant (pas 403)

Retourner 403 Forbidden révèle que la ressource existe mais que l'appelant n'a pas la permission — information qui franchit les frontières tenant. Toujours retourner 404 :

```php
// ❌ 403 fuit des informations cross-tenant
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ Filtre tenant en SQL — les enregistrements cross-tenant retournent simplement null
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

Quand `WHERE id = ? AND tenant_id = ?` ne correspond à rien, le repository retourne `null` et le handler retourne 404 — pas de vérification cross-tenant explicite nécessaire.

---

## Exclure `tenant_id` des réponses

`tenant_id` est un identifiant d'infrastructure. L'exposer dans les réponses permet aux attaquants d'énumérer tous les IDs de tenant et sert de point de départ pour des attaques ciblées :

```php
// ❌ tenant_id fuit dans la réponse
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // supprimer ceci
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ Uniquement les champs dont le client a besoin
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan : `assertIsList()` pour les types de retour `list<>`

`json_decode()` retourne `mixed`. Après `assertIsArray()`, PHPStan réduit le type à `array<mixed>`, mais cela ne satisfait pas `list<array<string, mixed>>`. Ajouter `assertIsList()` pour réduire davantage :

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // réduit array<mixed> → list<mixed>

    return $data;
}
```

`assertIsList()` de PHPUnit valide aussi à l'exécution que le tableau a des clés entières séquentielles commençant à 0 — vérification d'exactitude utile pour les réponses de liste API.

---

## Design du schéma

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

Chaque table scopée par tenant porte une clé étrangère `tenant_id NOT NULL`. Cela est appliqué au niveau DB en plus des filtres au niveau application.

---

## Checklist de revue de code

Lors de la revue de code multi-tenant, vérifier :

1. Chaque `SELECT`, `UPDATE` et `DELETE` inclut `WHERE tenant_id = ?`
2. `tenant_id` est issu du claim JWT, pas d'un paramètre URL ou du corps de requête
3. L'accès cross-tenant retourne 404, pas 403
4. Les réponses n'incluent pas `tenant_id`
5. Aucun `JOIN` ne franchit les frontières tenant sans filtre tenant
6. La vérification de type `is_int($claims['tenant_id'])` est présente

---

## Tester l'isolation

Les tests unitaires sont insuffisants — écrire des tests d'intégration cross-tenant qui tentent réellement d'accéder aux données d'un autre tenant :

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob tente d'accéder à la note d'Alice
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // Doit être 404 — PAS 403
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

Les tests happy-path vérifient uniquement que les données de votre propre tenant fonctionnent. Les tests cross-tenant sont le seul moyen de détecter les échecs d'isolation.

---

## Voir aussi

- `docs/howto/jwt-authentication.md` — émission et vérification JWT
- `docs/howto/rbac.md` — contrôle d'accès basé sur les rôles sur JWT
- `docs/howto/enforce-resource-ownership.md` — vérifications de propriété par utilisateur
- `docs/field-trials/2026-05-field-trial-112.md` — field trial d'isolation multi-tenant
