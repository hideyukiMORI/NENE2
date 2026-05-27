# Négociation de contenu

NENE2 est un framework JSON-first. Il n'implémente pas la négociation de contenu — toutes les réponses utilisent `application/json` (ou `application/problem+json` pour les erreurs) quel que soit ce que le client envoie dans l'en-tête `Accept`.

## Ce que NENE2 fait

| Le client envoie | Le serveur retourne |
|---|---|
| Pas d'en-tête `Accept` | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 ne retourne jamais `406 Not Acceptable`.** La RFC 7231 §6.5.6 dit que le serveur DEVRAIT retourner 406 quand aucun type acceptable n'est disponible, mais c'est un DEVRAIT (pas un DOIT). Pour un serveur API JSON-only, retourner toujours du JSON est le choix le plus simple et le plus courant.

Les réponses d'erreur utilisent `application/problem+json` (RFC 9457) quel que soit `Accept` :

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## Content-Type du corps de la requête

`JsonRequestBodyParser::parse()` ne vérifie pas l'en-tête `Content-Type` de la requête entrante. Il tente de décoder le corps en JSON de façon inconditionnelle :

```php
// Les trois atteignent JsonRequestBodyParser::parse() de façon identique :
// Content-Type: application/json → fonctionne
// Content-Type: application/x-www-form-urlencoded → 400 (l'analyse JSON échoue sur le corps de formulaire)
// (pas de Content-Type) + corps JSON → fonctionne
```

Cela signifie :
- Un corps JSON valide sans `Content-Type` est accepté — politique d'entrée libérale.
- Un corps encodé en formulaire (`name=Alice&age=30`) résulte en 400 Bad Request (échec d'analyse JSON), pas 415 Unsupported Media Type.

## Si vous avez besoin de réponses 406 ou 415

Ajouter un middleware qui inspecte les en-têtes `Accept` et `Content-Type` avant le gestionnaire de route :

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Appliquer Accept JSON-only (optionnel — la plupart des clients envoient */* ou application/json)
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // Appliquer le Content-Type JSON pour les requêtes de changement d'état
        $method      = strtoupper($request->getMethod());
        $contentType = $request->getHeaderLine('Content-Type');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && $contentType !== ''
            && !str_contains($contentType, 'application/json')
        ) {
            return $this->problems->create($request, 'unsupported-media-type', 'Unsupported Media Type', 415,
                'This API only accepts application/json request bodies.');
        }

        return $handler->handle($request);
    }
}
```

Le câbler via `RuntimeApplicationFactory` :

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **Note :** `authMiddleware` est évalué avant le routage. Placer l'application du type de contenu ici si vous voulez qu'elle s'applique globalement.
