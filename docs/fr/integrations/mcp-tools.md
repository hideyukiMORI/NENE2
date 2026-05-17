# Politique d'intégration des outils MCP

Les outils MCP de NENE2 doivent exposer les fonctionnalités applicatives via des frontières documentées, pas des raccourcis cachés vers la base de données ou le système de fichiers.

## Position

L'intégration MCP est une couche d'intégration compatible API.

Direction par défaut :

- Dériver la forme des outils depuis OpenAPI lorsque c'est pratique
- Commencer par des outils d'inspection en lecture seule
- Séparer les outils de développement local des outils de production
- Exiger une autorisation et une politique d'audit explicites avant les outils de mutation
- Éviter l'accès direct à la base de données depuis les outils MCP par défaut

## Sources des outils

Sources recommandées pour les définitions d'outils :

- Opérations OpenAPI d'API JSON publiques
- Services applicatifs documentés pour les outils internes n'utilisant pas HTTP
- Commandes de maintenance explicites pour les workflows locaux uniquement

Évitez de créer des comportements MCP-only qui ne peuvent pas être exécutés et vérifiés via les frontières applicatives normales.

## Catalogue

Le premier catalogue d'outils MCP lisible par machine se trouve dans `docs/mcp/tools.json`.

Il contient des métadonnées d'outils read-only correspondant aux opérations OpenAPI livrées. Le catalogue est validé par :

```bash
docker compose run --rm app composer mcp
```

`composer check` inclut cette validation.

## Niveaux de sécurité

Chaque outil MCP doit être classifié avant implémentation :

- `read` : retourne sans modifier l'état applicatif
- `write` : modifie l'état applicatif
- `admin` : modifie la configuration, les permissions, la rétention de données ou l'état opérationnel
- `destructive` : supprime des données ou effectue des opérations irréversibles

Les premiers outils MCP doivent être des outils `read`.

Les outils `write`, `admin` et `destructive` nécessitent :

- Comportement d'authentification et autorisation documenté
- Champs d'audit/journalisation
- Propagation du request id
- Comportement de confirmation explicite pour les actions destructives
- Tests couvrant les échecs et les frontières de permissions

Les frontières de clés API et jetons sont définies dans `docs/development/authentication-boundary.md`.

## Outils de développement local

Les outils MCP locaux uniquement aident les agents à inspecter l'application de développement, mais leur portée doit être clairement limitée.

Opérations autorisées pour les outils locaux :

- Appeler l'API HTTP locale
- Lire la documentation versionnée
- Exécuter des commandes de validation sûres documentées

Opérations interdites pour les outils locaux :

- Lire les secrets `.env`
- Contourner l'autorisation applicative d'une manière qui ressemble au comportement de production
- Modifier la base de données en dehors des commandes de test ou migration documentées
- Dépendre de la disposition privée du système de fichiers du développeur

## Outils de production

Les outils MCP de production doivent être conçus comme des fonctionnalités produit, pas des raccourcis de débogage.

Avant d'activer un outil de production, documentez :

- Propriétaire et objectif
- Credentials ou portées requis
- Environnements autorisés
- Limites de débit ou mesures anti-abus
- Champs d'audit
- Chemin de rollback ou réparation pour les mutations échouées

## Alignement avec OpenAPI

Lorsqu'un outil se mappe à une opération API HTTP :

- Utiliser le summary et schéma de l'opération OpenAPI comme point de départ
- Faire correspondre les noms de paramètres au contrat API
- Conserver le comportement d'erreur Problem Details
- Inclure le request id dans les logs et métadonnées retournées lorsque c'est utile

Si un outil nécessite une forme qui ne correspond pas à l'API actuelle, mettez d'abord à jour le contrat API ou documentez pourquoi une frontière de service interne est meilleure.

### Types de paramètres de chemin

Si un paramètre de chemin OpenAPI est de type `integer` (ex. `{year}`, `{id}`), l'`inputSchema` de l'outil doit refléter ce type :

```json
"inputSchema": {
  "type": "object",
  "properties": {
    "year": { "type": "integer" }
  },
  "required": ["year"]
}
```

Les clients LLM doivent envoyer les paramètres de chemin entiers comme nombres JSON, pas comme chaînes :

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Envoyer une chaîne (`"2026"`) sera rejeté par la validation de l'adaptateur si le schéma spécifie `"type": "integer"`.

## Non-objectifs

- Fournir des outils de base de données de production directe comme premier milestone MCP.
- Comportement métier MCP-only contournant les tests HTTP/API.
- Stocker des credentials MCP dans le dépôt.
- Exposer des outils destructifs avant que les politiques d'authentification, autorisation et audit existent.
