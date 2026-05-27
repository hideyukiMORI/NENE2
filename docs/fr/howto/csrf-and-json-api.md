# CSRF et APIs JSON

## CORS ≠ Protection CSRF

C'est l'une des idées fausses de sécurité les plus courantes dans le développement d'API web.

**CORS** (Cross-Origin Resource Sharing) contrôle si un navigateur laissera JavaScript sur une origine *lire la réponse* d'une autre origine. Le serveur ajoute les en-têtes `Access-Control-Allow-Origin` ; le navigateur applique la politique.

**CSRF** (Cross-Site Request Forgery) est une attaque où une page malveillante trompe le navigateur de la victime pour qu'il envoie une requête changeant l'état vers un site de confiance — en utilisant les cookies de session de la victime.

Le `CorsMiddleware` de NENE2 gère CORS. Il ne **bloque pas** les requêtes provenant d'origines inconnues. Une requête avec `Origin: https://evil.example.com` passe et atteint votre gestionnaire sans modification — c'est le comportement attendu. CORS est une protection du navigateur qui limite ce que *JavaScript peut lire*, pas ce que *le serveur accepte*.

```
# Ces requêtes atteignent toutes votre gestionnaire — CorsMiddleware ne les BLOQUE PAS
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# (pas d'en-tête Origin — ex. appels serveur-à-serveur)
```

## Pourquoi les APIs JSON sont plus résistantes au CSRF que les APIs basées sur des formulaires

Les exploits CSRF classiques utilisent des formulaires HTML (`<form method="POST">`). Les navigateurs envoient les soumissions de formulaires avec `Content-Type: application/x-www-form-urlencoded` ou `multipart/form-data` — le navigateur inclut automatiquement les cookies de session.

Une requête avec `Content-Type: application/json` **n'est pas une "simple requête"** selon la spécification CORS. Le navigateur envoie d'abord un preflight `OPTIONS`. Si votre configuration CORS ne liste pas l'origine de l'attaquant, le navigateur bloque le preflight — la requête réelle n'arrive jamais.

Cependant, **cela ne protège que contre les attaques basées sur le navigateur**. Un serveur ou un appel `fetch()` avec des en-têtes explicites peut envoyer `Content-Type: application/json` à votre API sans restriction. Les preflights CORS sont appliqués par les navigateurs, pas par les serveurs.

## La vraie protection : Bearer JWT

L'authentification standard de NENE2 utilise des Bearer JWT dans l'en-tête `Authorization` :

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

Les attaques CSRF fonctionnent en abusant des cookies — le navigateur les attache automatiquement aux requêtes cross-site. L'en-tête `Authorization` **n'est jamais** envoyé automatiquement. Une page malveillante ne peut pas inclure le JWT d'une victime car JavaScript sur `https://evil.example.com` ne peut pas lire le token depuis `https://app.example.com`.

Si vous utilisez l'authentification Bearer JWT de NENE2 et ne mettez jamais de tokens dans des cookies, vous n'êtes pas vulnérable au CSRF par conception. Aucun token CSRF supplémentaire ni attribut `SameSite` n'est nécessaire.

## Si vous utilisez des sessions basées sur des cookies

Si votre application utilise `Set-Cookie` pour la gestion de session (au lieu de Bearer JWT), vous avez besoin d'une protection CSRF explicite :

### Option 1 : Cookies SameSite (la plus simple)

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` empêche le navigateur d'inclure le cookie sur les requêtes cross-site. `SameSite=Lax` est aussi un défaut raisonnable qui bloque quand même les `POST` cross-site.

### Option 2 : Middleware de validation de l'en-tête Origin

Rejeter les requêtes dont l'`Origin` ne correspond pas à votre liste d'autorisation :

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Les appels non-navigateur (curl, serveur-à-serveur) n'ont pas d'Origin — les autoriser
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // Retourner une réponse Problem Details 403
            // ...
        }

        return $handler->handle($request);
    }
}
```

L'enregistrer après CORS dans la pile de middleware (voir section 5 de CLAUDE.md pour l'ordre).

### Option 3 : Token CSRF

Générer un token par session, le stocker côté serveur, l'inclure dans les formulaires comme champ caché et le vérifier sur chaque requête changeant l'état. C'est l'approche traditionnelle mais elle ajoute de la complexité.

## Résumé

| Scénario | Risque CSRF | Atténuation recommandée |
|---|---|---|
| Bearer JWT dans l'en-tête `Authorization` | Aucun — l'en-tête n'est pas envoyé automatiquement | Aucune action nécessaire |
| Session cookie, SameSite=Strict | Très faible | Garder `SameSite=Strict` |
| Session cookie, pas de SameSite | Élevé | Ajouter `SameSite` ou validation d'Origin |
| Clé API dans un en-tête personnalisé | Aucun — les en-têtes personnalisés ne sont pas envoyés automatiquement | Aucune action nécessaire |

La voie la plus simple : utiliser l'authentification Bearer JWT intégrée de NENE2 et éviter les sessions basées sur des cookies pour les endpoints API.
