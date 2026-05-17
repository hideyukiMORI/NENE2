# Política de Integração de Ferramentas MCP

As ferramentas MCP do NENE2 devem expor funcionalidades de aplicação através de fronteiras documentadas, não atalhos ocultos para banco de dados ou sistema de arquivos.

## Posição

A integração MCP é uma camada de integração compatível com API.

Direção padrão:

- Derivar o formato das ferramentas do OpenAPI quando prático
- Começar com ferramentas de inspeção somente leitura
- Separar ferramentas de desenvolvimento local das ferramentas de produção
- Exigir autorização explícita e política de auditoria antes de ferramentas de mutação
- Evitar acesso direto ao banco de dados a partir de ferramentas MCP por padrão

## Fontes das Ferramentas

Fontes recomendadas para definições de ferramentas:

- Operações OpenAPI de APIs JSON públicas
- Serviços de aplicação documentados para ferramentas internas que não usam HTTP
- Comandos de manutenção explícitos para workflows apenas locais

Evite criar comportamentos apenas MCP que não possam ser executados e verificados via fronteiras normais da aplicação.

## Catálogo

O primeiro catálogo de ferramentas MCP legível por máquina está em `docs/mcp/tools.json`.

Contém metadados de ferramentas somente leitura correspondentes às operações OpenAPI entregues. O catálogo é validado por:

```bash
docker compose run --rm app composer mcp
```

`composer check` inclui esta validação.

## Níveis de Segurança

Cada ferramenta MCP deve ser classificada antes da implementação:

- `read`: retorna sem modificar o estado da aplicação
- `write`: modifica o estado da aplicação
- `admin`: modifica configuração, permissões, retenção de dados ou estado operacional
- `destructive`: deleta dados ou executa operações irreversíveis

As primeiras ferramentas MCP devem ser ferramentas `read`.

Ferramentas `write`, `admin` e `destructive` requerem:

- Comportamento documentado de autenticação e autorização
- Campos de auditoria/logging
- Propagação do request id
- Comportamento de confirmação explícito para ações destrutivas
- Testes cobrindo falhas e fronteiras de permissões

As fronteiras de chaves de API e tokens são definidas em `docs/development/authentication-boundary.md`.

## Ferramentas de Desenvolvimento Local

Ferramentas MCP apenas locais ajudam agentes a inspecionar a aplicação de desenvolvimento, mas o escopo deve ser claramente limitado.

Operações permitidas para ferramentas locais:

- Chamar a API HTTP local
- Ler documentação versionada
- Executar comandos de validação seguros documentados

Operações proibidas para ferramentas locais:

- Ler segredos `.env`
- Contornar autorização de aplicação de forma que se assemelhe ao comportamento de produção
- Modificar o banco de dados fora dos comandos de teste ou migração documentados
- Depender do layout privado de sistema de arquivos do desenvolvedor

## Ferramentas de Produção

Ferramentas MCP de produção devem ser projetadas como funcionalidades de produto, não atalhos de debug.

Antes de habilitar uma ferramenta de produção, documente:

- Proprietário e propósito
- Credenciais ou escopos necessários
- Ambientes permitidos
- Limites de rate ou medidas anti-abuso
- Campos de auditoria
- Caminho de rollback ou reparo para mutações que falharam

## Alinhamento com OpenAPI

Quando uma ferramenta mapeia para uma operação de API HTTP:

- Use o summary e schema da operação OpenAPI como ponto de partida
- Corresponda nomes de parâmetros ao contrato da API
- Preserve o comportamento de erro Problem Details
- Inclua request id em logs e metadados retornados quando útil

Se uma ferramenta requer um formato que não corresponde à API atual, atualize primeiro o contrato da API ou documente por que uma fronteira de serviço interna é melhor.

### Tipos de Parâmetros de Caminho

Se um parâmetro de caminho OpenAPI é do tipo `integer` (ex. `{year}`, `{id}`), o `inputSchema` da ferramenta deve refletir esse tipo:

```json
"inputSchema": {
  "type": "object",
  "properties": {
    "year": { "type": "integer" }
  },
  "required": ["year"]
}
```

Clientes LLM devem enviar parâmetros de caminho inteiros como números JSON, não como strings:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Enviar uma string (`"2026"`) será rejeitado pela validação do adaptador se o schema especificar `"type": "integer"`.

## Não-objetivos

- Fornecer ferramentas de banco de dados de produção direta como primeiro milestone MCP.
- Comportamento de negócio apenas MCP contornando testes HTTP/API.
- Armazenar credenciais MCP no repositório.
- Expor ferramentas destrutivas antes que políticas de autenticação, autorização e auditoria existam.
