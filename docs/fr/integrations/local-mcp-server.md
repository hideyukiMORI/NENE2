# Intégration du serveur MCP local

L'intégration du serveur MCP local permet aux agents d'inspecter et valider NENE2 via des frontières documentées.

C'est une commodité de développement, pas une porte dérobée de production.

## Position

Le serveur MCP local peut exposer des outils d'inspection en lecture seule et des commandes de validation sûres sur le checkout NENE2 local du développeur.

Utilise :

- L'API HTTP publique locale
- La documentation versionnée
- `docs/mcp/tools.json`
- Les commandes locales sûres documentées

## Premier serveur local

NENE2 inclut un serveur MCP stdio local uniquement :

```bash
docker compose run --rm app php tools/local-mcp-server.php
```

Par défaut, il appelle l'API locale sur `http://localhost:8080`. Remplacez l'URL de base en dehors du dépôt si nécessaire :

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://localhost:8080 app php tools/local-mcp-server.php
```

Lors de l'exécution du serveur dans Docker contre le service `app` Compose :

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

### Prérequis DB pour les outils write

Les outils Read (`getHealth`, `listExampleNotes`, `getExampleNoteById`, etc.) nécessitent uniquement le conteneur `app`.

Les outils Write (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) appellent des endpoints qui persistent dans la base de données. Avant d'appeler les outils write, démarrez MySQL et appliquez les migrations :

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

Le serveur supporte les méthodes :

- `initialize`
- `tools/list`
- `tools/call`

Les outils sont chargés depuis `docs/mcp/tools.json`. Les outils OpenAPI-correspondants en lecture seule (`safety: read`) et en écriture (`safety: write`) sont exposés.

Les outils Read (`getHealth`, `getFrameworkSmoke`, `listExampleNotes`, `getExampleNoteById`) se mappent aux GET HTTP. Les arguments deviennent des paramètres de chemin ou de chaîne de requête.

Les outils Write (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) se mappent respectivement aux HTTP POST, PUT et DELETE.

## Ce qu'il ne faut pas utiliser

- Accès direct à la base de données de production
- Lecture brute des secrets `.env`
- Chemins de système de fichiers privés de l'utilisateur
- Comportement applicatif caché non testable via les frontières normales

## Opérations autorisées pour les outils locaux

- Lire le catalogue MCP versionné
- Appeler `http://localhost:8080/` et autres routes API locales documentées
- Retourner les métadonnées `X-Request-Id` des réponses HTTP
- Exécuter des commandes de validation documentées depuis `docs/integrations/local-ai-commands.md`

## Forme des outils

Les outils locaux doivent se mapper à des opérations existantes du catalogue ou OpenAPI lorsque c'est pratique.

Métadonnées recommandées :

- Nom de l'outil
- Niveau de sécurité (`read`, `write`, `admin`, `destructive`)
- Opération source ou commande
- Portées requises (s'il y en a)
- Si l'outil appelle HTTP
- Si l'outil retourne des métadonnées request id

Les outils `admin` et `destructive` sont hors scope du guidance serveur MCP local actuel.

### Paramètres de chemin entiers

Si un outil se mappe à un chemin OpenAPI avec des paramètres entiers comme `{year}` ou `{id}`, déclarez-les comme `"type": "integer"` dans `inputSchema` et passez-les comme nombres JSON dans les arguments `tools/call`.

## Comportement HTTP

Lorsqu'un outil MCP local appelle une API HTTP :

- Utiliser l'URL de base API locale configurée
- Envoyer `Accept: application/json` pour les API JSON
- Conserver les erreurs Problem Details sans réécriture
- Retourner ou journaliser l'en-tête de réponse `X-Request-Id` s'il existe
- Ne pas inclure de credentials dans les métadonnées retournées

## Commandes sûres

Les outils de commande locale doivent se limiter à des vérifications documentées :

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

Les commandes d'installation de dépendances, modification de base de données, tagging de releases, fusion de PR ou modification de l'historique git nécessitent une Issue ciblée et une intention explicite de l'utilisateur.

## Frontière de production

Les outils MCP de production doivent être conçus comme des fonctionnalités produit avec authentification, autorisation, audit et propriété opérationnelle.

Ne réutilisez pas la configuration du serveur MCP local comme configuration de production.
