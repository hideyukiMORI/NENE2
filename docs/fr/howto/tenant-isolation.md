# How-to : Isolation de tenant et prévention IDOR cross-tenant

**FT179 — isolationlog**

Prévenir les fuites de données cross-tenant dans les APIs multi-tenant —
requêtes SQL scopées, identité basée sur les en-têtes, et prévention d'injection dans le body.

---

## La menace : IDOR cross-tenant

Dans un système multi-tenant, chaque ressource appartient à un tenant.
Un attaquant qui contrôle un compte tenant sonde les IDs d'autres tenants :

```
GET /notes/42          X-Tenant-Id: 2   ← l'attaquant est le tenant 2
                                         la note 42 appartient au tenant 1
```

Si le serveur retourne la note, l'attaquant a lu les données d'un autre tenant —
une **Insecure Direct Object Reference (IDOR)** à la frontière tenant.

---

## Le pattern d'isolation

### 1. Scoper toutes les lectures au niveau SQL

Ne jamais interroger par ID seul. Toujours ajouter `AND tenant_id = ?` :

```php
// ❌ INCORRECT — ID seul, lisible cross-tenant
'SELECT * FROM notes WHERE id = ?'

// ✅ CORRECT — ID + tenant imposé en SQL
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

Cela retourne `null` pour l'accès cross-tenant, qui devient un 404.
L'attaquant n'apprend rien sur la note 42 — pas même si elle existe.

### 2. Les requêtes de liste sont toujours scopées

```php
// ❌ INCORRECT — pourrait être augmenté par injection ?tenant_id=...
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ CORRECT — WHERE tenant_id = ? n'est jamais optionnel
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. DELETE utilise le même pattern

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

`rowCount()` retourne 0 si la note n'appartient pas au tenant → 404.

---

## Identité de tenant basée sur les en-têtes

Utiliser les en-têtes `X-Tenant-Id` + `X-User-Id` pour les endpoints scopés au tenant.
Valider les deux avec `V::userId()` (ctype_digit + garde de débordement + > 0) :

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` rejette :
- La chaîne vide (`ctype_digit('') === false`)
- Zéro (`id <= 0`)
- Les négatifs (`'-'` échoue `ctype_digit`)
- Les chaînes flottantes (`'1.5'` échoue `ctype_digit`)
- Le débordement de 20+ chiffres (garde strlen > 18)
- Les tentatives d'injection SQL (`'1 OR 1=1'` échoue `ctype_digit`)

---

## Prévention de l'injection dans le body

Les attaquants peuvent inclure `tenant_id` dans le body POST pour tenter
d'assigner une ressource à un tenant différent :

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injection", "tenant_id": 99 }
```

**Ne jamais lire `tenant_id` depuis le body.** Toujours utiliser l'en-tête validé par le serveur :

```php
// ATK-04 : body['tenant_id'] n'est jamais lu — toujours utiliser $tenantId depuis l'en-tête
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            depuis V::userId(X-Tenant-Id), pas depuis $body
```

---

## Vérification d'existence du tenant à l'écriture

Avant de créer une ressource, vérifier que le tenant existe :

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant not found.'], 422);
}
```

Sans cette vérification, des notes seraient créées pour des IDs de tenant fantômes qui n'existent pas
dans la table tenants, rompant l'intégrité référentielle.

---

## Liste de vérification d'attaques (ATK-01 à ATK-12)

| # | Test | Attente |
|---|------|---------|
| ATK-01 | Pas d'en-têtes auth | 401 |
| ATK-02 | GET cross-tenant (IDOR) | 404 — la note existe mais pas pour ce tenant |
| ATK-03 | X-Tenant-Id : `"1"`, `1.5`, `+1`, `1 OR 1=1` | 401 — V::userId rejette |
| ATK-04 | Body POST contient `tenant_id: 99` | 201 — tenant_id du body ignoré |
| ATK-05 | DELETE cross-tenant | 404 — note non supprimée |
| ATK-06 | X-Tenant-Id : `0`, `-1` | 401 |
| ATK-07 | X-Tenant-Id : débordement 20 chiffres | 401 |
| ATK-08 | Création de tenant sans X-Admin-Key | 401 |
| ATK-09 | Mauvais X-Admin-Key | 401 |
| ATK-10 | Note pour un ID de tenant inexistant | 422 |
| ATK-11 | Liste : T1 voit uniquement les notes T1, pas T2 | Imposé par SQL WHERE tenant_id |
| ATK-12 | `?limit=-1`, `?limit=10.5`, limit 20 chiffres | 422 — gardes V::queryInt |

---

## Stratégie de réponse : 404 et non 403

Quand un IDOR cross-tenant est détecté, retourner **404** — pas 403 Forbidden.

- `403` fait fuiter l'existence : "la ressource existe mais vous ne pouvez pas y accéder"
- `404` ne révèle rien : "pas de telle ressource pour ce tenant"

Cela prévient les attaques d'énumération de tenant.
