# ETag et requêtes conditionnelles

> **Référence FT** : FT307 (`NENE2-FT/etaglog`) — Requêtes conditionnelles ETag : `If-None-Match`→304, `If-Modified-Since`→304, `If-Match`→412 périmé / 428 absent, wildcard `If-Match: *` passe, ETag change après chaque mise à jour, 15 tests PASS.

Les ETags permettent aux clients d'éviter de re-télécharger du contenu inchangé et de détecter l'état périmé avant d'écrire. NENE2 fournit deux helpers pour les patterns les plus courants.

| Scénario | En-tête | Helper | En cas de correspondance |
|---|---|---|---|
| GET conditionnel | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| Écriture conditionnelle | `If-Match` | `ConditionalWriteHelper` | l'écriture se poursuit |
| Écriture sans en-tête | — | `ConditionalWriteHelper` | 428 Precondition Required |
| ETag d'écriture périmé | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## Génération d'ETag

Générer un ETag fort depuis le contenu de la ressource sous forme de MD5 entre guillemets doubles :

```php
final readonly class Article
{
    public function etag(): string
    {
        // Les guillemets doubles sont requis par la RFC 9110 — sans eux, la comparaison If-None-Match échoue toujours
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

Garder la génération d'ETag en un seul endroit (une méthode sur l'entité) pour que changer l'algorithme (ex. vers SHA-256) soit une seule modification.

## GET conditionnel — 304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // Retourne une réponse 304 quand If-None-Match correspond à l'ETag courant.
    // Retourne null quand une réponse 200 complète doit être envoyée.
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` évalue deux en-têtes :
- `If-None-Match` : correspondance exacte d'ETag → 304
- `If-Modified-Since` : comparaison de chaîne `$ifModifiedSince >= $lastModified` → 304

Toujours inclure la même valeur `$etag` dans l'appel `check()` et dans l'appel `withHeader('ETag', $etag)`. Les générer séparément risque une dérive.

### Format Last-Modified

La vérification `If-Modified-Since` est une **comparaison de chaîne**, pas une comparaison de date parsée. Utiliser un format qui trie lexicographiquement — ISO 8601 est recommandé :

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

Le format standard HTTP `Sat, 21 May 2026 12:00:00 GMT` trie incorrectement — ne pas l'utiliser avec ce helper.

### 304 n'a pas de corps

La RFC 9110 interdit un corps dans les réponses 304. `ConditionalGetHelper` retourne un `createResponse(304)` vide, donc cela est géré correctement tant que vous retournez directement la réponse du helper.

## Écriture conditionnelle — If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // Doit appeler AVANT l'écriture — vérifier après est sans signification.
    // Retourne 428 quand If-Match est absent ; 412 quand If-Match est présent mais incorrect.
    // Retourne null quand la précondition passe.
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * wildcard

Un client peut envoyer `If-Match: *` pour dire "procéder si la ressource existe". `ConditionalWriteHelper` passe cela inconditionnellement. **L'appelant est responsable de retourner 404 quand la ressource n'existe pas** — récupérer l'enregistrement en premier et protéger avec un 404.

### Rendre If-Match optionnel

Par défaut (`$require = true`), `If-Match` manquant retourne 428. Pour autoriser les écritures sans en-tête de précondition :

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

Assouplir cela uniquement quand le verrouillage optimiste est véritablement optionnel pour la ressource.

## Flux client

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304 (pas de corps)
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Updated" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       (périmé — le contenu a changé, ETag est maintenant "def456")

PATCH /articles/1         → 428 Precondition Required
  (pas d'en-tête If-Match)

PATCH /articles/1         → 200 { ... }
  If-Match: *              (wildcard — toute version existante)
```

## Toujours inclure ETag dans chaque réponse

Retourner `ETag` (et `Last-Modified`) sur les réponses POST, GET et PATCH pour que le client ait toujours une valeur fraîche sans aller-retour supplémentaire :

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag vs Champ de version

| | ETag (en-tête HTTP) | Champ de version (corps) |
|---|---|---|
| Où vérifié | En-tête HTTP | Corps de requête |
| Granularité | Hash de contenu | Compteur entier |
| Le client doit suivre | Valeur ETag | Numéro de version |
| Idéal pour | Cache HTTP + verrouillage optimiste | Détection de conflit au niveau API |

Ils peuvent être utilisés ensemble : ETag pour la mise en cache HTTP, version pour la détection de conflit au niveau DB (voir [optimistic-locking.md](optimistic-locking.md)).

## Liste de contrôle de révision de code

- [ ] La chaîne ETag inclut les guillemets doubles entourants (`'"' . md5(...) . '"'`)
- [ ] La génération d'ETag est en un seul endroit (méthode d'entité), pas dupliquée entre les gestionnaires
- [ ] `ConditionalGetHelper::check()` est appelé avant de construire la réponse 200
- [ ] La même valeur `$etag` est passée à `check()` et à `withHeader('ETag', $etag)`
- [ ] `ConditionalWriteHelper::check()` est appelé avant l'écriture
- [ ] Le corps de réponse 304 est vide (utiliser directement la réponse du helper)
- [ ] Les valeurs `Last-Modified` utilisent le format ISO 8601 (ordre lexicographique requis)
- [ ] Chaque réponse (201, 200) inclut `ETag` pour que le client ait toujours une valeur fraîche
- [ ] Les tests couvrent : 200 sans `If-None-Match`, 304 en correspondance, 200 sur ETag périmé, 428 sans `If-Match`, 412 sur `If-Match` périmé, 200 sur `If-Match` correct, `If-Match: *`
