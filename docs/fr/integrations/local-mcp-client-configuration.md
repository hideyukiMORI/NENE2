# Configuration du client MCP local

Ce guide explique comment connecter un client MCP local au serveur MCP stdio de NENE2.

Réservé au développement local uniquement. Ne réutilisez pas cette configuration pour les déploiements MCP de production.

## Prérequis

Construire l'image PHP et démarrer l'API locale :

```bash
docker compose build app
docker compose up -d app
```

Vérifier que l'API est accessible :

```bash
curl -i http://localhost:8080/health
```

Le serveur MCP est un processus stdio. Ce n'est pas un serveur HTTP — il doit être lancé par le client MCP.

## Configuration stdio générique

Pour les clients MCP acceptant commande, arguments et variables d'environnement :

```json
{
  "mcpServers": {
    "nene2-local": {
      "command": "docker",
      "args": [
        "compose",
        "run",
        "--rm",
        "-e",
        "NENE2_LOCAL_API_BASE_URL=http://app",
        "app",
        "php",
        "tools/local-mcp-server.php"
      ]
    }
  }
}
```

Pourquoi utiliser `http://app` :

- Le processus serveur MCP fonctionne dans le conteneur `app` Docker Compose
- Le service web cible est accessible par nom de service Compose
- `localhost` dans ce conteneur référence le conteneur MCP ponctuel, pas le service web en cours d'exécution

Ne commitez pas de secrets dans les configurations de client MCP versionnées.

## Smoke check local

Utilisez le script helper de smoke pour exécuter une séquence JSON-RPC complète sans boilerplate.

Le service app doit d'abord être démarré :

```bash
docker compose up -d app
```

Puis exécutez le helper :

```bash
# initialize + tools/list uniquement
bash tools/mcp-smoke.sh

# Appeler un outil spécifique
bash tools/mcp-smoke.sh getHealth '{}'

# Appeler un outil avec paramètres de chemin (utilisez des nombres JSON pour les champs entiers)
bash tools/mcp-smoke.sh getExhibitionWorkByYearAndId '{"year":2026,"workId":20260101}'
```

Remplacez l'URL de base API si nécessaire :

```bash
NENE2_LOCAL_API_BASE_URL=http://my-api bash tools/mcp-smoke.sh getHealth '{}'
```

**Alternative manuelle** — pour plus de contrôle, pipez des lignes JSON-RPC brutes :

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Outils disponibles

Le premier serveur local charge des outils read-only depuis `docs/mcp/tools.json`.

Exemples actuels :

- `getFrameworkSmoke`
- `getHealth`

Pour valider le catalogue :

```bash
docker compose run --rm app composer mcp
```

### Types de paramètres de chemin

Les outils mappés à des chemins OpenAPI avec des paramètres entiers (ex. `{year}`, `{id}`) nécessitent des nombres JSON dans les arguments `tools/call`, pas des chaînes.

Correct :

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Incorrect (rejeté si le schéma spécifie `integer`) :

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

Consultez `inputSchema` de l'outil dans `docs/mcp/tools.json` pour les types attendus.

## Règles de sécurité

Opérations autorisées pour le client MCP local :

- Appeler l'API HTTP locale documentée
- Lire les métadonnées MCP versionnées via le serveur
- Utiliser des outils read-only correspondant aux opérations OpenAPI

Opérations interdites pour le client MCP local :

- Lire les secrets `.env`
- Appeler des APIs de production
- Exposer l'accès direct à la base de données ou au système de fichiers
- Ajouter des outils write, admin ou destructifs sans Issue et conception ciblées
- Commiter des configurations de client MCP spécifiques à l'utilisateur

## Documentation associée

- Guidance serveur MCP local : `docs/integrations/local-mcp-server.md`
- Politique des outils MCP : `docs/integrations/mcp-tools.md`
- Catalogue MCP : `docs/mcp/tools.json`
- Guide de démarrage projet client : `docs/development/client-project-start.md`
- Frontière d'authentification : `docs/development/authentication-boundary.md`
