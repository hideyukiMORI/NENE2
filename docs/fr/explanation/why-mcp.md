# Pourquoi MCP comme frontière d'intégration IA ?

NENE2 intègre les agents IA via le Model Context Protocol (MCP) plutôt que de leur donner un accès direct à la base de données ou au système de fichiers. Cette page explique la décision de conception.

## À quoi ressemble la frontière

```
Agent IA (Claude, Cursor, …)
    │  MCP stdio
    ▼
local-mcp-server.php          ← Serveur MCP de NENE2
    │  HTTP
    ▼
API NENE2 (PSR-7 / OpenAPI)   ← Mêmes endpoints que le navigateur
    │  PDO
    ▼
Base de données
```

L'agent IA n'atteint jamais directement la base de données. Toute opération passe par un endpoint HTTP documenté avec validation, authentification et réponses d'erreur structurées.

## Pourquoi ne pas laisser les agents interroger directement la base de données ?

### 1. Le contrat API est la source de vérité

Le document OpenAPI décrit quelles opérations existent, quelles entrées elles acceptent et quelles sorties elles retournent. Les requêtes SQL contournent ce contrat.

### 2. L'autorisation vit au niveau API

L'authentification par clé API, la politique CORS et les limites de taille de requête sont appliquées dans le middleware PSR-15. Une connexion directe à la base de données les ignore toutes.

### 3. Les erreurs structurées aident les agents à se rétablir

Quand un appel API échoue, l'agent reçoit une réponse Problem Details avec un `type` lisible par machine et des `errors` structurés.

### 4. Les mêmes endpoints servent tous les clients

Le serveur MCP appelle les mêmes routes qu'un navigateur, une suite de tests ou une commande curl.

## Niveaux de sécurité des outils

| Niveau | Exemples | Exigences |
|-------|----------|-----------|
| `read` | `getHealth`, `getNote` | Clé API uniquement |
| `write` | `createNote`, `updateNote` | Identique |
| `admin` | Modifications de rôles | Étape de confirmation |
| `destructive` | Suppressions en masse | Hors périmètre local |
