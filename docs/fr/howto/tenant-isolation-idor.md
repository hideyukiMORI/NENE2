# How-to : Isolation de tenant et prévention IDOR

> **Référence FT** : FT318 (`NENE2-FT/isolationlog`) — Isolation des données multi-tenant, prévention IDOR cross-tenant, durcissement contre la confusion de type d'en-tête, prévention d'injection tenant_id dans le body, 34 tests / 133 assertions PASS.

Ce guide montre comment imposer une isolation stricte des données au niveau tenant pour qu'aucun tenant ne puisse lire, modifier, ou énumérer les données d'un autre tenant — même s'il manipule les en-têtes ou les bodies de requête.

## Schéma

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Modèle d'authentification

```
Endpoints admin  → X-Admin-Key: <server_secret>       (ex. env ADMIN_KEY)
Endpoints tenant → X-Tenant-Id: <int>  X-User-Id: <int>
```

### Règles de validation d'en-tête

`X-Tenant-Id` et `X-User-Id` doivent passer la validation **entier positif strict** :

| Entrée | Résultat |
|--------|---------|
| `"1"` (valide) | ✅ Accepté |
| `"0"` | ❌ 401 — doit être > 0 |
| `"-1"` | ❌ 401 — négatif rejeté |
| `"1.5"` | ❌ 401 — flottant rejeté |
| `"+1"` | ❌ 401 — préfixe signe rejeté |
| `"1 OR 1=1"` | ❌ 401 — tentative d'injection SQL rejetée |
| `""` (absent) | ❌ 401 — en-tête manquant |
| `"99999999999999999999"` (20 chiffres) | ❌ 401 — débordement rejeté |

```php
// Pattern de validation utilisant ctype_digit + vérification de plage
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Unauthorized'], 401);
}
```

## Endpoints admin

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// Pas de clé admin
POST /tenants  (pas de X-Admin-Key)   → 401
POST /tenants  X-Admin-Key: wrong     → 401
```

## Endpoints tenant — Prévention IDOR

### Créer une note (tenant assigné par le serveur)

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Hello"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Hello", ...}
```

**Le `tenant_id` dans le body de la requête est TOUJOURS ignoré.** Le serveur utilise uniquement la valeur de l'en-tête :

```php
// L'attaquant envoie X-Tenant-Id: 1 mais le body essaie d'injecter le tenant 2
POST /notes  X-Tenant-Id: 1
{"content": "Injection", "tenant_id": 2}  // ← ignoré

→ 201  {"tenant_id": 1, ...}   // assigné depuis l'en-tête, pas le body
```

### IDOR cross-tenant — Retourne 404

```php
// La note 5 appartient au Tenant 1
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR bloqué
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR bloqué

// Le propriétaire peut toujours accéder
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

Toutes les requêtes incluent `WHERE tenant_id = $tenantId`. Une ligne manquante retourne 404 — **pas 403** — pour prévenir l'énumération d'existence.

### Isolation de liste

```php
// T1 a 2 notes, T2 a 1 note
GET /notes  X-Tenant-Id: 1  → {"data": [note_A, note_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [note_X],         "tenant_id": 2}
// T2 ne voit jamais les notes de T1
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- Toujours filtrer par tenant_id depuis l'en-tête validé
```

### Validation des paramètres de requête

```php
GET /notes?limit=-1       → 422  // négatif
GET /notes?limit=10.5     → 422  // flottant
GET /notes?limit=999999   → 422  // dépasse le max (ex. 100)
GET /notes?limit=99999999999999999999  → 422  // débordement
GET /notes                → 200  // limit par défaut appliquée
```

## Création de note pour tenant inexistant

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // le tenant 9999 n'existe pas
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Faire confiance au `tenant_id` du body de la requête | L'attaquant assigne des notes à n'importe quel tenant |
| Retourner 403 au lieu de 404 sur IDOR | 403 révèle que la ressource existe ; 404 prévient l'énumération |
| Caster l'en-tête directement : `(int) $header` sans ctype_digit | `-1`, `+1`, `1.5`, le débordement produisent tous des entiers inattendus |
| Pas de `WHERE tenant_id = ?` dans les requêtes de liste | Fuite complète de données cross-tenant |
| Partager la clé admin dans les réponses client | La clé admin doit rester côté serveur uniquement |
| Autoriser `X-Tenant-Id: 0` | Zéro est souvent un état par défaut/non défini ; accepter uniquement les entiers positifs |
