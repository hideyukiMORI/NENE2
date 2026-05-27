# How-to : Contrôle de concurrence optimiste (champ version)

> **Référence FT** : FT323 (`NENE2-FT/optimisticlog`) — API de document avec champ version dans le corps PUT, 409 sur version périmée, prévention des mises à jour perdues, 18 tests / 34 assertions PASS.

Ce guide montre comment implémenter le contrôle de concurrence optimiste en passant un champ `version` dans le corps de la requête, comme alternative aux en-têtes HTTP ETag/If-Match.

## Schéma

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/documents` | Créer (version commence à 1) |
| `GET` | `/documents` | Lister |
| `GET` | `/documents/{id}` | Obtenir avec version |
| `PUT` | `/documents/{id}` | Mettre à jour (version requise dans le corps) |

## Création

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## Mise à jour avec version

Le client lit la `version` courante et l'inclut dans le corps PUT :

```php
// Lecture
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// Mise à jour avec version correcte
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

La version s'incrémente à chaque mise à jour réussie.

## Version périmée — 409 Conflict

```php
// Alice et Bob lisent tous les deux la version 1
// Alice met à jour en premier → la version devient 2
// Bob essaie de mettre à jour avec la version 1 → rejeté
PUT /documents/1
{"title": "Bob's edit", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob relit, obtient la version 2, réessaie
PUT /documents/1
{"title": "Bob's edit", "version": 2}
→ 200  {"version": 3}
```

## Implémentation

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

La clause `WHERE version = ?` dans la requête UPDATE est le garde atomique contre les écritures concurrentes.

## Version vs ETag

| Aspect | Champ version (ce guide) | ETag / If-Match (voir `optimistic-locking-etag.md`) |
|--------|--------------------------|------------------------------------------------------|
| Protocole | Champ de corps | En-tête HTTP |
| UX client | `"version": N` explicite en JSON | En-tête `If-Match: "vN"` |
| Payload 409 | Peut retourner `current_version` | 412 — pas de corps standard |
| Vérification manquante | 422 (`version` manquant) | 428 (`If-Match` manquant) |

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Accepter PUT sans champ `version` | Mises à jour perdues : dernier qui écrit gagne silencieusement |
| Retourner 200 sur version périmée | Écrasement silencieux des changements concurrents |
| Vérifier la version uniquement dans le code applicatif (pas dans la clause WHERE) | Condition de course entre lecture et écriture |
| Ne pas inclure `current_version` dans la réponse 409 | Le client doit re-GET pour récupérer ; l'inclure pour une réessai plus rapide |
