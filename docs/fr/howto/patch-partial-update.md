# How-to : Mise à jour partielle PATCH (JSON Merge Patch)

> **Référence FT** : FT326 (`NENE2-FT/patchlog`) — Mise à jour partielle JSON Merge Patch (RFC 7396) : réinitialisation de champ null, rejet de champ immuable, ETag/If-Match, mutation propriétaire uniquement, 42 tests / 141 assertions PASS.

Ce guide montre comment implémenter un endpoint `PATCH` suivant la sémantique JSON Merge Patch : seuls les champs fournis sont mis à jour, `null` réinitialise à la valeur par défaut, et les champs immuables sont rejetés.

## Schéma

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST`  | `/documents` | Créer (nécessite `X-User-Id`) |
| `GET`   | `/documents` | Lister |
| `GET`   | `/documents/{id}` | Obtenir avec en-tête ETag |
| `PATCH` | `/documents/{id}` | Mise à jour partielle (nécessite `X-User-Id`) |
| `DELETE`| `/documents/{id}` | Supprimer (propriétaire uniquement) |

## Création

```php
POST /documents  X-User-Id: 1
{"title": "Mon Doc", "body": "Contenu"}
→ 201  {"id": 1, "owner_id": 1, "title": "Mon Doc", "status": "draft", "version": 1}

// Pas de X-User-Id → 401
// title manquant → 422
// title vide  → 422
// body est optionnel → par défaut ""
```

## GET avec ETag

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "Mon Doc", "version": 1, ...}
```

Format ETag : `"doc-{id}-{version}"`.

## PATCH — Sémantique JSON Merge Patch

```php
// Mettre à jour uniquement title — body inchangé
PATCH /documents/1  X-User-Id: 1
{"title": "Mis à jour"}
→ 200  {"title": "Mis à jour", "body": "Contenu", ...}

// Mettre à jour body uniquement
PATCH /documents/1  X-User-Id: 1
{"body": "Nouveau contenu"}
→ 200  {"title": "Mis à jour", "body": "Nouveau contenu", ...}

// {} vide — sans effet (valide selon RFC 7396 §3)
PATCH /documents/1  X-User-Id: 1
{}
→ 200  (document inchangé)

// null réinitialise le champ à sa valeur par défaut
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // réinitialisé à la valeur par défaut
```

## Champs immuables — Rejetés

Certains champs ne doivent jamais être modifiés via PATCH :

```php
PATCH /documents/1  {"id": 999}         → 422  // immuable
PATCH /documents/1  {"owner_id": 99}    → 422  // immuable
PATCH /documents/1  {"version": 999}    → 422  // immuable
PATCH /documents/1  {"created_at": "…"} → 422  // immuable
```

## Autorisation propriétaire uniquement

```php
// L'utilisateur 2 tente de modifier le document de l'utilisateur 1 → 404 (pas 403, pour prévenir l'énumération)
PATCH /documents/1  X-User-Id: 2  {"title": "Volé"}  → 404

// Le propriétaire peut toujours modifier le sien
PATCH /documents/1  X-User-Id: 1  {"title": "Le mien"}    → 200
```

## ETag / If-Match

```php
// PATCH conditionnel — 412 si la version a changé
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Mis à jour"}
→ 200  // si la version est toujours 1

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Périmé"}
→ 412  // si la version est maintenant 2
```

## Validation des types

```php
PATCH /documents/1  {"title": 123}   → 422  // int au lieu de string
PATCH /documents/1  {"body": [1,2]}  → 422  // array au lieu de string
```

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Traiter le champ manquant de la même façon que `null` | L'appelant ne peut pas effacer un champ ; `undefined` ≠ `null` dans Merge Patch |
| Permettre la modification de `owner_id` | Transfert de propriété via API sans flux d'autorisation |
| Retourner 403 pour accès inter-propriétaires | Révèle l'existence du document ; retourner 404 à la place |
| Remplacer le document entier sur PATCH | Écrase les champs que le client n'avait pas l'intention de changer |
| Accepter silencieusement les champs immuables (sans effet) | Le client croit avoir changé `id` ; l'échec silencieux cause de la confusion |
