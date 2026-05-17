# Pourquoi RFC 9457 Problem Details ?

Les erreurs API de NENE2 utilisent le format RFC 9457 Problem Details. Cette page explique le choix.

## À quoi ressemble Problem Details

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## Pourquoi un standard plutôt qu'une forme personnalisée ?

### 1. Les clients peuvent gérer les erreurs de façon générique

Un client qui connaît RFC 9457 peut afficher `title` et `status` pour toute erreur de toute API RFC 9457, sans connaître l'application spécifique.

### 2. `Content-Type: application/problem+json` est lisible par machine

Quand une réponse porte `application/problem+json`, un client sait qu'il a reçu un objet d'erreur. Cette distinction importe pour les outils MCP et autres clients machine.

### 3. L'URI `type` donne aux erreurs une identité stable

Chaque type de problème a une URI comme `https://nene2.dev/problems/validation-failed`. Cette URI est stable, documentable et utilisable pour du pattern matching côté client.

### 4. C'est un standard publié

RFC 9457 (successeur de RFC 7807) est un standard IETF publié. L'utiliser signifie que le format d'erreur n'est pas une invention maison qui nécessite une documentation pour chaque consommateur d'API.

## Les URIs `nene2.dev`

Les URIs `type` de NENE2 utilisent actuellement `https://nene2.dev/problems/...` comme domaine de substitution. Avant la mise en production, le déployeur doit soit enregistrer ce domaine, soit remplacer l'URL de base dans `ProblemDetailsResponseFactory`.
