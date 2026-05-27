# How-to : Isolation multi-tenant JWT

> **Référence FT** : FT342 (`NENE2-FT/tenantlog`) — API de notes multi-tenant avec authentification Bearer JWT, tenant_id intégré dans les claims du token, scoping strict des requêtes par tenant, IDOR inter-tenant bloqué avec 404, tenant_id jamais exposé dans les réponses, 13 tests / 30+ assertions PASS.

Ce guide montre comment utiliser les tokens JWT pour transporter `tenant_id` comme claim, scoper toutes les requêtes au tenant authentifié et prévenir l'accès aux données inter-tenant.

## Schéma

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## Authentification

```
POST /auth/login  →  token Bearer (JWT)
Tous les autres endpoints → Authorization: Bearer <token>
```

### Login

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// Mauvaises credentials ou email inconnu
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// Les deux échecs retournent le même message (prévention d'énumération d'utilisateurs)
```

### Claims JWT

```php
// Payload du token (décodé)
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // tenant auquel appartient l'utilisateur
  "exp": 1748427600
}
```

La claim `tenant_id` est la source faisant autorité pour l'identité de tenant — ne jamais faire confiance à `tenant_id` du corps de requête ou des en-têtes.

### Vérification

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] est la portée de tenant de confiance
```

Un token altéré (signature invalide) → 401.

## Endpoints scopés par tenant

Toutes les opérations sur les notes nécessitent un token Bearer valide. Le `tenant_id` est extrait des claims JWT vérifiées.

### Créer une note

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id n'est PAS retourné — jamais divulgué au client
}

// Pas de token → 401
// Token invalide → 401
```

**`tenant_id` est toujours pris depuis la claim JWT, pas du corps de requête.**

### Lister les notes

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Le token de Bob ne voit que les notes de Bob — les notes d'Alice n'apparaissent jamais
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id lié depuis les claims JWT, jamais depuis la requête
```

### Obtenir une note (Prévention IDOR)

```php
// Note d'Alice
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob essaie d'accéder à la note d'Alice (note id 1 appartient au tenant 1)
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // PAS 403 — prévient l'énumération d'existence inter-tenant
```

**Retourner 404, pas 403, pour l'accès inter-tenant.** Un 403 révèle que la ressource existe dans un autre tenant.

### Supprimer une note

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// Suppression inter-tenant
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // note intacte ; le token de Bob ne peut pas l'atteindre
```

## Pattern d'implémentation

```php
// Le middleware extrait et vérifie le JWT
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// Le contrôleur lit depuis les attributs de requête (jamais depuis le corps)
$tenantId = (int) $request->getAttribute('tenant_id');

// Le repository scope toujours au tenant
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// Retour null → réponse 404 (jamais 403)
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## Rejet d'altération de token

```php
// L'attaquant crée manuellement un token avec un tenant_id différent
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // la vérification de signature échoue
```

Le serveur rejette tout token dont la signature HMAC-SHA256 ne correspond pas au secret du serveur.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Lire `tenant_id` du corps de requête ou des paramètres de requête | L'attaquant définit `tenant_id=2` pour accéder aux données d'un autre tenant |
| Retourner 403 pour l'accès inter-tenant | Confirme que la ressource existe dans un autre tenant — fuite d'information |
| Inclure `tenant_id` dans les réponses de note | Expose la topologie interne des tenants ; inutile pour le client |
| Sauter `AND tenant_id = ?` dans les requêtes | Fuite inter-tenant — l'attaquant avec token valide voit les données de tous les tenants |
| Stocker le secret JWT dans la config à côté des données | La compromission du secret permet de falsifier des tokens pour n'importe quel tenant |
| Faire confiance à `tenant_id` de l'en-tête `X-Tenant-Id` | L'en-tête peut être défini par n'importe quel client ; ne faire confiance qu'aux claims JWT vérifiées |
