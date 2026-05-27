# How-to : Négociation de contenu — API JSON

> **Référence FT** : FT301 (`NENE2-FT/contentlog`) — Négociation de contenu pour API JSON : retourne toujours `application/json` quel que soit l'en-tête `Accept`, `application/problem+json` pour les erreurs (404/422/405), 415 pour les POST avec un `Content-Type` non-JSON, 16 tests / 28 assertions PASS.

Ce guide couvre la façon dont le runtime de NENE2 gère la négociation de contenu HTTP pour les APIs JSON — quelles valeurs d'en-tête `Accept` sont acceptées, quand `Content-Type` est important, et comment les réponses d'erreur utilisent `application/problem+json`.

## Toujours JSON — Ignorer l'en-tête Accept

Les APIs JSON NENE2 retournent `application/json` pour les réponses de succès quel que soit l'en-tête `Accept` envoyé par le client :

| En-tête Accept envoyé | Content-Type de réponse |
|---|---|
| _(aucun)_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

C'est intentionnel pour les services API purs : le serveur est un endpoint API-only, pas un serveur multi-format négociant le contenu. Les clients qui envoient `Accept: text/html` reçoivent quand même du JSON.

## Réponses d'erreur — application/problem+json

Les réponses d'erreur utilisent `application/problem+json` (RFC 9457) quel que soit l'en-tête `Accept` :

| Scénario | Statut | Content-Type |
|---|---|---|
| Route non trouvée | 404 | `application/problem+json` |
| Méthode non autorisée | 405 | `application/problem+json` |
| Échec de validation | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory produit toujours application/problem+json
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

Les clients peuvent détecter les erreurs soit par le code de statut HTTP, soit en vérifiant `Content-Type: application/problem+json`.

## Content-Type de la requête — Corps POST

Pour les requêtes `POST` avec un corps JSON, NENE2 utilise `JsonRequestBodyParser::parse()` :

```php
$body = JsonRequestBodyParser::parse($request);
```

Si la requête a un `Content-Type: text/plain` ou un type non-JSON explicite, le parser peut retourner un tableau vide. Cependant, si le corps est du JSON valide sans en-tête `Content-Type` du tout, le parser l'accepte :

```
POST /articles (pas de Content-Type, corps JSON) → 201 Created ✅
POST /articles (Content-Type: text/plain) → 415 Unsupported Media Type ✅
```

## Validation — Champs requis

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

Après `trim()`, une chaîne vide est traitée de la même façon qu'un champ manquant. L'erreur de validation retourne un tableau `errors` structuré avec les clés `field`, `code` et `message` — extension standard RFC 9457.

## Forme de la réponse

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## Enregistrement des routes

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles` (liste) est enregistré avant `GET /articles/{id}` (unique) — bien que dans ce cas les deux soient des GET avec des chemins différents, donc l'ordre ne crée pas de conflit de capture. La route de liste utilise un chemin statique ; la route unique utilise la capture dynamique `{id}`.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Retourner 406 pour les en-têtes `Accept` non supportés | Les services API-only devraient servir du JSON à tous les clients, pas refuser |
| Utiliser `text/json` au lieu de `application/json` | Type MIME non standard ; certains clients ne le reconnaîtront pas |
| Retourner du `application/json` ordinaire pour les réponses d'erreur | Les clients ne peuvent pas distinguer erreurs et succès par Content-Type ; utiliser `application/problem+json` |
| Omettre le tableau `errors` dans les erreurs de validation | Les clients ne peuvent pas afficher des messages d'erreur par champ aux utilisateurs |
| Accepter `Content-Type: text/plain` pour les corps JSON | Entrée ambiguë ; être explicite sur les types de contenu acceptés |
| Trim après la validation | `trim()` doit précéder la vérification de la chaîne vide ; `" "` passerait si l'on vérifie avant de trimmer |
