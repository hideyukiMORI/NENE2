# Por que MCP como fronteira de integração de IA?

NENE2 integra agentes de IA via Model Context Protocol (MCP) em vez de dar a eles acesso direto ao banco de dados ou sistema de arquivos. Esta página explica a decisão de design.

## Como a fronteira funciona

```
Agente de IA (Claude, Cursor, …)
    │  MCP stdio
    ▼
local-mcp-server.php          ← Servidor MCP da NENE2
    │  HTTP
    ▼
API NENE2 (PSR-7 / OpenAPI)   ← Mesmos endpoints que o navegador usa
    │  PDO
    ▼
Banco de dados
```

O agente de IA nunca acessa o banco de dados diretamente. Toda operação passa por um endpoint HTTP documentado com validação, autenticação e respostas de erro estruturadas.

## Por que não deixar agentes consultarem o banco diretamente?

### 1. O contrato da API é a fonte da verdade

O documento OpenAPI descreve quais operações existem, quais entradas aceitam e quais saídas retornam. Consultas SQL contornam esse contrato.

### 2. Autorização vive na camada de API

Autenticação por chave de API, política CORS e limites de tamanho de requisição são aplicados no middleware PSR-15.

### 3. Erros estruturados ajudam agentes a se recuperar

Quando uma chamada de API falha, o agente recebe uma resposta Problem Details com `type` legível por máquina e `errors` estruturados.

### 4. Os mesmos endpoints servem todos os clientes

O servidor MCP chama as mesmas rotas que um navegador, suite de testes ou comando curl.

## Níveis de segurança das ferramentas

| Nível | Exemplos | Requisitos |
|-------|---------|------------|
| `read` | `getHealth`, `getNote` | Apenas chave de API |
| `write` | `createNote`, `updateNote` | Mesmo que acima |
| `admin` | Mudanças hipotéticas de papel | Etapa de confirmação explícita |
| `destructive` | Exclusões em lote | Fora do escopo local |
