# Types Problem Details

NENE2 retourne `application/problem+json` pour toutes les réponses d'erreur, conformément à [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457).

## Catalogue des types

| `type` | Statut HTTP | `title` | Produit par |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | Route introuvable ; Note ou Tag avec l'id donné introuvable |
| `…/method-not-allowed` | 405 | Method Not Allowed | Mauvaise méthode HTTP pour une route connue |
| `…/validation-failed` | 422 | Validation Failed | Corps de requête invalide ou champs obligatoires manquants |
| `…/unauthorized` | 401 | Unauthorized | Token Bearer absent ou invalide |
| `…/payload-too-large` | 413 | Payload Too Large | Corps de requête dépassant la limite configurée |
| `…/internal-server-error` | 500 | Internal Server Error | Exception non gérée |

Préfixe URI de base : `https://nene2.dev/problems/`

## Ajouter un type personnalisé

1. Créez une classe d'exception de domaine (ex. `ProductNotFoundException`).
2. Implémentez `DomainExceptionHandlerInterface` en appelant `ProblemDetailsResponseFactory::create()`.
3. Enregistrez le handler dans `RuntimeServiceProvider`.

Consultez `NoteNotFoundExceptionHandler` et `TagNotFoundExceptionHandler` pour des exemples concrets.
