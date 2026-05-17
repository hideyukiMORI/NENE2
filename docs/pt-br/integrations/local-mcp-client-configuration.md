# Configuração do Cliente MCP Local

Este guia explica como conectar um cliente MCP local ao servidor MCP stdio do NENE2.

Apenas para desenvolvimento local. Não reutilize esta configuração para implantações MCP de produção.

## Pré-requisitos

Construa a imagem PHP e inicie a API local:

```bash
docker compose build app
docker compose up -d app
```

Verifique se a API está acessível:

```bash
curl -i http://localhost:8080/health
```

O servidor MCP é um processo stdio. Não é um servidor HTTP — precisa ser iniciado pelo cliente MCP.

## Configuração Stdio Genérica

Para clientes MCP que aceitam comando, argumentos e variáveis de ambiente, use este formato:

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

Por que usar `http://app`:

- O processo servidor MCP roda dentro do container `app` do Docker Compose
- O serviço web alvo é acessível pelo nome do serviço Compose
- `localhost` dentro daquele container refere-se ao container MCP único, não ao serviço web em execução

Não comite segredos em configurações de cliente MCP versionadas.

## Smoke Check Local

Use o script auxiliar de smoke para executar uma sequência JSON-RPC completa sem boilerplate.

O serviço app precisa estar iniciado primeiro:

```bash
docker compose up -d app
```

Então execute o auxiliar:

```bash
# apenas initialize + tools/list
bash tools/mcp-smoke.sh

# Chamar uma ferramenta específica
bash tools/mcp-smoke.sh getHealth '{}'

# Chamar uma ferramenta com parâmetros de caminho (use números JSON para campos inteiros)
bash tools/mcp-smoke.sh getExhibitionWorkByYearAndId '{"year":2026,"workId":20260101}'
```

Substitua a URL base da API se necessário:

```bash
NENE2_LOCAL_API_BASE_URL=http://my-api bash tools/mcp-smoke.sh getHealth '{}'
```

**Alternativa manual** — para mais controle, faça pipe de linhas JSON-RPC brutas:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Ferramentas Disponíveis

O primeiro servidor local carrega ferramentas somente leitura de `docs/mcp/tools.json`.

Exemplos atuais:

- `getFrameworkSmoke`
- `getHealth`

Para validar o catálogo:

```bash
docker compose run --rm app composer mcp
```

### Tipos de Parâmetros de Caminho

Ferramentas mapeadas para caminhos OpenAPI com parâmetros inteiros (ex. `{year}`, `{id}`) requerem números JSON nos argumentos de `tools/call`, não strings.

Correto:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Incorreto (rejeitado se o schema especifica `integer`):

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

Consulte o `inputSchema` da ferramenta em `docs/mcp/tools.json` para os tipos esperados.

## Regras de Segurança

Operações permitidas para o cliente MCP local:

- Chamar a API HTTP local documentada
- Ler metadados MCP versionados através do servidor
- Usar ferramentas somente leitura correspondentes a operações OpenAPI

Operações proibidas para o cliente MCP local:

- Ler segredos `.env`
- Chamar APIs de produção
- Expor acesso direto ao banco de dados ou sistema de arquivos
- Adicionar ferramentas de escrita, admin ou destrutivas sem Issue e design focados
- Commitar configurações de cliente MCP específicas do usuário

## Documentação Relacionada

- Guia do servidor MCP local: `docs/integrations/local-mcp-server.md`
- Política de ferramentas MCP: `docs/integrations/mcp-tools.md`
- Catálogo MCP: `docs/mcp/tools.json`
- Guia de início de projeto cliente: `docs/development/client-project-start.md`
- Fronteira de autenticação: `docs/development/authentication-boundary.md`
