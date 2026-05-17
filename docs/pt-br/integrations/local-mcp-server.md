# Integração do Servidor MCP Local

A integração do servidor MCP local permite que agentes inspecionem e validem o NENE2 através de fronteiras documentadas.

Esta é uma conveniência de desenvolvimento, não uma backdoor de produção.

## Posição

O servidor MCP local pode expor ferramentas de inspeção somente leitura e comandos de validação seguros no checkout NENE2 local do desenvolvedor.

Usa:

- API HTTP pública local
- Documentação versionada
- `docs/mcp/tools.json`
- Comandos locais seguros documentados

## Primeiro Servidor Local

O NENE2 inclui um servidor MCP stdio apenas local:

```bash
docker compose run --rm app php tools/local-mcp-server.php
```

Por padrão, chama a API local em `http://localhost:8080`. Substitua a URL base fora do repositório se necessário:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://localhost:8080 app php tools/local-mcp-server.php
```

Ao executar o servidor no Docker contra o serviço Compose `app`:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

### Pré-requisitos de DB para Ferramentas de Escrita

Ferramentas de Leitura (`getHealth`, `listExampleNotes`, `getExampleNoteById`, etc.) requerem apenas o container `app`.

Ferramentas de Escrita (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) chamam endpoints que persistem no banco de dados. Antes de chamar ferramentas de escrita, inicie o MySQL e aplique as migrações:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

O servidor suporta os métodos:

- `initialize`
- `tools/list`
- `tools/call`

Ferramentas são carregadas de `docs/mcp/tools.json`. Ferramentas somente leitura (`safety: read`) e de escrita (`safety: write`) correspondentes ao OpenAPI são expostas.

Ferramentas de Leitura (`getHealth`, `getFrameworkSmoke`, `listExampleNotes`, `getExampleNoteById`) mapeiam para HTTP GET. Argumentos tornam-se parâmetros de caminho ou valores de query string.

Ferramentas de Escrita (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) mapeiam para HTTP POST, PUT e DELETE respectivamente.

## O que Não Usar

- Acesso direto ao banco de dados de produção
- Leitura bruta de segredos `.env`
- Caminhos de sistema de arquivos privados do usuário
- Comportamento de aplicação oculto não testável via fronteiras normais

## Operações Permitidas para Ferramentas Locais

- Ler o catálogo MCP versionado
- Chamar `http://localhost:8080/` e outras rotas de API locais documentadas
- Retornar metadados `X-Request-Id` de respostas HTTP
- Executar comandos de validação documentados de `docs/integrations/local-ai-commands.md`

## Formato das Ferramentas

Ferramentas locais devem mapear para catálogo existente ou operações OpenAPI quando prático.

Metadados recomendados:

- Nome da ferramenta
- Nível de segurança (`read`, `write`, `admin`, `destructive`)
- Operação ou comando fonte
- Escopos necessários (se houver)
- Se a ferramenta chama HTTP
- Se a ferramenta retorna metadados de request id

Ferramentas `admin` e `destructive` estão fora do escopo da orientação atual do servidor MCP local.

### Parâmetros de Caminho Inteiros

Se uma ferramenta mapeia para um caminho OpenAPI com parâmetros inteiros como `{year}` ou `{id}`, declare-os como `"type": "integer"` no `inputSchema` e passe-os como números JSON nos argumentos de `tools/call`.

## Comportamento HTTP

Quando uma ferramenta MCP local chama uma API HTTP:

- Use a URL base da API local configurada
- Envie `Accept: application/json` para APIs JSON
- Preserve erros Problem Details sem reescrever
- Retorne ou registre o header de resposta `X-Request-Id` se existir
- Não inclua credenciais nos metadados retornados

## Comandos Seguros

Ferramentas de comando local devem se limitar a verificações documentadas:

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

Comandos que instalam dependências, modificam banco de dados, tagueiam releases, fazem merge de PRs ou modificam histórico git requerem uma Issue focada e intenção explícita do usuário.

## Fronteira de Produção

Ferramentas MCP de produção devem ser projetadas como funcionalidades de produto com autenticação, autorização, auditoria e propriedade operacional.

Não reutilize a configuração do servidor MCP local como configuração de produção.
