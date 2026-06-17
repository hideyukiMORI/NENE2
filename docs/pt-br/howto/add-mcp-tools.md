# Adicionar ferramentas MCP

Este guia explica como expor os endpoints de API de uma aplicação NENE2 como ferramentas MCP,
permitindo que assistentes de IA (Claude, Cursor, etc.) chamem a API via o Model Context Protocol.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando com pelo menos uma rota e um arquivo `docs/openapi/openapi.yaml`. Caso contrário, comece por [Adicionar uma rota personalizada](./add-custom-route.md).

---

## Visão geral

NENE2 fornece um servidor MCP local (`LocalMcpServer`) que traduz mensagens JSON-RPC MCP em chamadas HTTP para a API. O catálogo de ferramentas (`docs/mcp/tools.json`) declara quais endpoints expor como ferramentas MCP e o nível de segurança de cada uma.

```
Assistente IA → JSON-RPC (stdio) → LocalMcpServer → HTTP → Aplicação NENE2
```

O catálogo é validado em relação à spec OpenAPI com `composer mcp`.

---

## 1. Adicionar o script de validação

Adicione em `composer.json`:

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

Instale a dependência de desenvolvimento:

```bash
composer require --dev symfony/yaml
```

---

## 2. Criar o catálogo de ferramentas

Crie `docs/mcp/tools.json`. Cada entrada em `tools` corresponde a um endpoint da API.

```json
{
  "version": 1,
  "source": "docs/openapi/openapi.yaml",
  "tools": [
    {
      "name": "listNotes",
      "title": "Lista de notas",
      "description": "Retorna todas as notas do banco de dados.",
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
          "limit":  { "type": "integer", "description": "Número máximo de itens a retornar." },
          "offset": { "type": "integer", "description": "Número de itens a ignorar." }
        }
      },
      "responseSchemaRef": "#/components/schemas/NoteListResponse"
    }
  ]
}
```

### Campos de uma ferramenta

| Campo | Obrigatório | Descrição |
|---|---|---|
| `name` | Sim | Identificador camelCase único |
| `title` | Sim | Rótulo legível por humanos |
| `description` | Sim | Texto que explica o propósito ao assistente de IA |
| `safety` | Sim | `read` / `write` / `admin` / `destructive` |
| `source.operationId` | Sim | Deve corresponder ao `operationId` na spec OpenAPI |
| `source.method` | Sim | Método HTTP (maiúsculas/minúsculas indiferente, armazenado em maiúsculas) |
| `source.path` | Sim | Caminho URL com parâmetros no formato `{param}` |
| `inputSchema` | Sim | JSON Schema dos argumentos da ferramenta |
| `responseSchemaRef` | Não | `$ref` para um schema de componente OpenAPI, ou `null` |

### Níveis de segurança

| Nível | Significado |
|---|---|
| `read` | Pode ser chamado sem efeitos colaterais (requisições GET) |
| `write` | Cria ou modifica dados (POST / PUT / PATCH) |
| `admin` | Ações administrativas — usar com cautela |
| `destructive` | Exclui dados permanentemente — confirmação explícita necessária |

Comece apenas com ferramentas `read`, adicione ferramentas `write` quando a autenticação estiver configurada.

---

## 3. Validar o catálogo

```bash
composer mcp
```

O validador verifica:

- O `operationId` de cada ferramenta existe na spec OpenAPI
- O caminho de cada ferramenta corresponde à definição de caminho OpenAPI
- O campo `safety` é um dos quatro valores permitidos
- `responseSchemaRef` (se não nulo) resolve para um schema de componente existente

Corrija todos os erros antes de iniciar o servidor MCP.

---

## 4. Adicionar ferramentas de escrita

```json
{
  "name": "createNote",
  "title": "Criar nota",
  "description": "Cria uma nova nota.",
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
      "title":   { "type": "string", "description": "Título da nota." },
      "content": { "type": "string", "description": "Conteúdo da nota." }
    }
  },
  "responseSchemaRef": null
}
```

---

## 5. Proteger ferramentas de escrita com JWT

`LocalMcpServer` verifica um cabeçalho `Authorization: Bearer <token>` para cada chamada de ferramenta `write`, `admin` ou `destructive`. Configure a variável de ambiente:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-secret
```

Sem essa variável, chamadas de ferramentas de escrita retornam um erro MCP sem encaminhar a requisição.

Proteja também os endpoints correspondentes no lado da aplicação com `BearerTokenMiddleware`:

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

## 6. Iniciar o servidor MCP

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8200 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

O servidor lê de `stdin` e escreve em `stdout` via o transporte stdio do MCP.

---

## 7. Configurar Claude Code ou Claude Desktop

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

Reinicie o Claude e as ferramentas declaradas no catálogo aparecerão como ações disponíveis.

---

## 8. Testar a camada MCP

Teste `LocalMcpToolCatalog` diretamente. Nenhum servidor HTTP é necessário:

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

## Próximos passos

- [Adicionar autenticação JWT](./add-jwt-authentication.md)
- [Adicionar limitação de taxa](./add-rate-limiting.md)
- [Adicionar health check](./add-health-check.md)
