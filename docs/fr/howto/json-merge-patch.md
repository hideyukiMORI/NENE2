# How-to : JSON Merge Patch et détection de conflits ETag

**FT178 — patchlog**

Implémentation de PATCH (RFC 7396 JSON Merge Patch) et sémantique PUT avec verrouillage optimiste via ETag, protection des champs immuables et intégration V.php.

---

## Le problème avec PUT

`PUT` remplace la ressource entière. Les clients doivent envoyer chaque champ, même ceux qu'ils n'ont pas changés. Cela crée :

- **Conditions de course** : des lecteurs concurrents voient tous deux la version 1, tous deux effectuent un PUT, le dernier gagne et supprime silencieusement les changements de l'autre.
- **Gaspillage de bande passante** : payload complet même pour un changement d'un seul champ.
- **Confusion de permissions** : écriture de champs que le client ne possède pas.

`PATCH` avec **JSON Merge Patch (RFC 7396)** résout les deux premiers ; `ETag` / `If-Match` résout la condition de course pour PATCH et PUT.

---

## Sémantique JSON Merge Patch (RFC 7396)

Le document de patch décrit les changements avec une règle simple :

| Valeur du patch | Signification |
|-----------------|---------------|
| `"nouvelle valeur"` | Définir le champ à cette valeur |
| `null` | Réinitialiser le champ (supprimer ou revenir au défaut) |
| *(clé absente)* | Laisser le champ inchangé |

```json
// Document avant PATCH :
{ "title": "Hello", "body": "World", "status": "draft" }

// Corps PATCH :
{ "title": "Goodbye", "status": null }

// Résultat :
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              inchangé  null → réinitialiser au défaut
```

### Champs immuables

Certains champs ne doivent jamais être modifiables via PATCH ou PUT :

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### PATCH vide est valide (no-op)

RFC 7396 §3 autorise explicitement un patch vide `{}` :

```php
// Pas de clés dans $patch → ignorer UPDATE, retourner le document actuel inchangé
if ($patch === []) {
    return $doc;  // no-op ; version NON incrémentée
}
```

---

## ETag et If-Match pour le verrouillage optimiste

### Format ETag

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // ex: "doc-42-7"
}
```

Retourner `ETag` sur chaque réponse GET/PATCH/PUT :

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### Détection de conflit

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match absent** : mise à jour optimiste sans vérification de conflit (dernier-écrit-gagne).
**If-Match présent et correspondant** : mise à jour concurrente sûre.
**If-Match présent mais périmé** : 412 — le client doit re-récupérer et retenter.

### Incrément de version en SQL

Utiliser la base de données pour incrémenter atomiquement la version :

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

La clause `WHERE version = ?` double-vérifie le verrou optimiste au niveau DB, empêchant une écriture concurrente de se glisser entre notre lecture et notre écriture.

---

## Intégration V.php

FT178 est le premier FT à utiliser `Nene2\Validation\V` comme utilitaire partagé :

```php
// Paramètres de requête
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// En-tête auth
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// Champs chaîne (avec limites de longueur explicites)
$title = V::str($body['title'] ?? null, 200);

// Validation d'enum
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### Le piège `?? ''` pour les champs de corps optionnels

```php
// ❌ INCORRECT — contourne le retour null de V::str pour les entrées trop longues
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ CORRECT — valider quand présent, utiliser le défaut quand absent
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` retourne `null` car `null` n'est pas une chaîne.
`V::str(chaîne_trop_longue, 10000)` retourne aussi `null`.
Utiliser `?? ''` effondre les deux cas en chaîne vide — acceptant silencieusement l'entrée trop longue.

---

## Extraction de paramètre de route

Le routeur NENE2 stocke les paramètres de chemin dans l'attribut `nene2.route.parameters`, pas comme attributs de requête individuels :

```php
// ❌ INCORRECT
$id = $request->getAttribute('id');  // toujours null pour les paramètres de chemin

// ✅ CORRECT
$id = Router::param($request, 'id');  // lit depuis nene2.route.parameters
```

---

## Checklist d'attaque (ATK-01 à ATK-12)

| # | Test | Attente |
|---|------|---------|
| ATK-01 | PATCH `{"id": 999}` | 422 — champ immuable |
| ATK-02 | PATCH `{"owner_id": 99}` | 422 — champ immuable |
| ATK-03 | PATCH `{"version": 999}` | 422 — champ immuable |
| ATK-04 | PATCH `{"title": 42}` (confusion de type) | 422 — V::str rejette les non-chaînes |
| ATK-05 | PATCH par non-propriétaire | 404 — protection IDOR |
| ATK-06 | If-Match ETag périmé | 412 — conflit de verrou optimiste |
| ATK-07 | PUT title requis manquant | 422 |
| ATK-08 | PATCH vide `{}` | 200 — no-op valide (RFC 7396 §3) |
| ATK-09 | PATCH `{"status": null}` | 200 — réinitialiser au défaut `draft` |
| ATK-10 | PATCH `{"status": 2}` (confusion de type) | 422 — V::enum rejette les non-chaînes |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200 — clé inconnue ignorée, pas de crash |
| ATK-12 | `?limit=999999`, `?page=-1`, débordement 20 chiffres | 422 — gardes V::queryInt |
