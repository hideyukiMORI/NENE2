# Ajouter des outils MCP

Ce guide explique comment exposer les endpoints API d'une application NENE2 en tant qu'outils MCP,
permettant aux assistants IA (Claude, Cursor, etc.) d'appeler l'API via le Model Context Protocol.

**Prérequis** : Vous avez une application NENE2 fonctionnelle avec au moins une route et un fichier `docs/openapi/openapi.yaml`. Sinon, commencez par [Ajouter une route personnalisée](./add-custom-route.md).

---

## Vue d'ensemble

NENE2 fournit un serveur MCP local (`LocalMcpServer`) qui traduit les messages JSON-RPC MCP en appels HTTP vers l'API. Le catalogue d'outils (`docs/mcp/tools.json`) déclare quels endpoints exposer en tant qu'outils MCP et leur niveau de sécurité.

```
Assistant IA → JSON-RPC (stdio) → LocalMcpServer → HTTP → Application NENE2
```

Le catalogue est validé par rapport à la spec OpenAPI avec `composer mcp`.

---

## 1. Ajouter le script de validation

Ajoutez dans `composer.json` :

```json
{
  "require-dev": {
    "symfony/yaml": "^7.0"
  },
  "scripts": {
    "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
  }
}
```

Installez la dépendance de développement :

```bash
composer require --dev symfony/yaml
```

---

## 2. Créer le catalogue d'outils

Créez `docs/mcp/tools.json`. Chaque entrée dans `tools` correspond à un endpoint API.

```json
{
  "version": 1,
  "source": "docs/openapi/openapi.yaml",
  "tools": [
    {
      "name": "listNotes",
      "title": "Liste des notes",
      "description": "Retourne toutes les notes depuis la base de données.",
      "safety": "read",
      "source": {
        "type": "openapi",
        "operationId": "listNotes",
        "method": "GET",
        "path": "/notes"
      },
      "inputSchema": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "limit":  { "type": "integer", "description": "Nombre maximum de résultats à retourner." },
          "offset": { "type": "integer", "description": "Nombre de résultats à ignorer." }
        }
      },
      "responseSchemaRef": "#/components/schemas/NoteListResponse"
    }
  ]
}
```

### Champs d'un outil

| Champ | Requis | Description |
|---|---|---|
| `name` | Oui | Identifiant camelCase unique |
| `title` | Oui | Libellé lisible par l'humain |
| `description` | Oui | Texte expliquant l'objectif à l'assistant IA |
| `safety` | Oui | `read` / `write` / `admin` / `destructive` |
| `source.operationId` | Oui | Doit correspondre à l'`operationId` dans la spec OpenAPI |
| `source.method` | Oui | Méthode HTTP (casse indifférente, stockée en majuscules) |
| `source.path` | Oui | Chemin URL avec paramètres au format `{param}` |
| `inputSchema` | Oui | JSON Schema des arguments de l'outil |
| `responseSchemaRef` | Non | `$ref` vers un schema de composant OpenAPI, ou `null` |

### Niveaux de sécurité

| Niveau | Signification |
|---|---|
| `read` | Peut être appelé sans effets de bord (requêtes GET) |
| `write` | Crée ou modifie des données (POST / PUT / PATCH) |
| `admin` | Actions d'administration — à utiliser avec précaution |
| `destructive` | Supprime définitivement des données — confirmation explicite requise |

Commencez avec uniquement des outils `read`, ajoutez les outils `write` une fois l'authentification en place.

---

## 3. Valider le catalogue

```bash
composer mcp
```

Le validateur vérifie :

- L'`operationId` de chaque outil existe dans la spec OpenAPI
- Le chemin de chaque outil correspond à la définition de chemin OpenAPI
- Le champ `safety` est l'une des quatre valeurs autorisées
- `responseSchemaRef` (si non null) se résout vers un schema de composant existant

Corrigez toutes les erreurs avant de démarrer le serveur MCP.

---

## 4. Ajouter des outils en écriture

```json
{
  "name": "createNote",
  "title": "Créer une note",
  "description": "Crée une nouvelle note.",
  "safety": "write",
  "source": {
    "type": "openapi",
    "operationId": "createNote",
    "method": "POST",
    "path": "/notes"
  },
  "inputSchema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["title", "content"],
    "properties": {
      "title":   { "type": "string", "description": "Titre de la note." },
      "content": { "type": "string", "description": "Corps de la note." }
    }
  },
  "responseSchemaRef": null
}
```

---

## 5. Protéger les outils en écriture avec JWT

`LocalMcpServer` vérifie un en-tête `Authorization: Bearer <token>` pour chaque appel d'outil `write`, `admin` ou `destructive`. Configurez la variable d'environnement :

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-secret
```

Sans cette variable, les appels d'outils en écriture retournent une erreur MCP sans transmettre la requête.

Protégez également les endpoints correspondants côté application avec `BearerTokenMiddleware` :

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret = getenv('NENE2_LOCAL_JWT_SECRET') ?: null;

$authMiddleware = $secret !== null
    ? new BearerTokenMiddleware(
        $problemDetails,
        new LocalBearerTokenVerifier($secret),
        excludedPaths: ['/notes'],
        protectedPathPrefixes: ['/notes/'],
    )
    : null;
```

---

## 6. Démarrer le serveur MCP

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8200 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

Le serveur lit depuis `stdin` et écrit sur `stdout` via le transport stdio MCP.

---

## 7. Configurer Claude Code ou Claude Desktop

### Claude Code (`~/.claude/claude_code_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "/path/to/my-app/mcp-server.sh"
    }
  }
}
```

### Claude Desktop (`claude_desktop_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "bash",
      "args": ["/path/to/my-app/mcp-server.sh"]
    }
  }
}
```

Redémarrez Claude et les outils déclarés dans le catalogue apparaîtront comme actions disponibles.

---

## 8. Tester la couche MCP

Testez `LocalMcpToolCatalog` directement. Aucun serveur HTTP n'est requis :

```php
use Nene2\Mcp\LocalMcpToolCatalog;

public function testListNotesToolIsPresent(): void
{
    $catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');

    $tool = $catalog->find('listNotes');

    self::assertNotNull($tool);
    self::assertSame('read', $tool['safety']);
    self::assertSame('GET', $tool['source']['method']);
    self::assertSame('/notes', $tool['source']['path']);
}
```

---

## Étapes suivantes

- [Ajouter l'authentification JWT](./add-jwt-authentication.md)
- [Ajouter la limitation de débit](./add-rate-limiting.md)
- [Ajouter un health check](./add-health-check.md)
