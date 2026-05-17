# Política de Fronteira de Autenticação

O NENE2 trata a autenticação como uma fronteira de aplicação explícita, não como magia oculta do framework.

Esta política define a primeira direção de chave de API e token para clientes machine, ferramentas MCP e futuros middlewares de autenticação.

## Posição

Autenticação e autorização são fronteiras explícitas de middleware.

A direção padrão é:

- Chaves de API são para clientes machine e ferramentas MCP.
- Tokens Bearer são para autenticação de usuário ou serviço quando uma aplicação os adota.
- Autenticação por sessão pertence a aplicações que precisam de sessões de browser do lado do servidor.
- Esquemas de segurança OpenAPI devem ser adicionados apenas quando o comportamento de middleware correspondente existe.
- Segredos nunca devem ser commitados, logados ou expostos através de metadados MCP.

O primeiro caminho de middleware implementado é uma verificação de chave de API para endpoints machine-client usando:

```text
X-NENE2-API-Key
```

O valor da chave é carregado de `NENE2_MACHINE_API_KEY` quando configurado. Deixe sem definir para desenvolvimento local apenas público, e defina fora do repositório ao testar rotas protegidas.

## Chaves de API

Chaves de API são credenciais de longa duração para clientes não-humanos.

Use chaves de API para:

- ferramentas MCP locais que chamam APIs HTTP locais
- ferramentas de inspeção serviço-a-serviço
- clientes machine que precisam de acesso estável e com escopo

Chaves de API devem ter:

- um proprietário
- um ambiente
- uma lista de escopos
- hora de criação
- hora de último uso quando o armazenamento existe
- caminho de rotação ou revogação

Não coloque chaves de API brutas em exemplos OpenAPI, catálogos de ferramentas MCP, logs, capturas de tela ou configuração versionada.

## Tokens Bearer

Tokens Bearer são credenciais de request enviadas no header `Authorization`.

Use tokens Bearer para:

- tokens de usuário de curta duração
- tokens de serviço com escopos explícitos
- futuros fluxos OAuth ou token first-party

Tokens Bearer devem ser tratados como segredos mesmo quando são de curta duração.

O framework não deve prescrever um formato de token antes que um adaptador de autenticação exista.

## Escopos

Escopos descrevem capacidades permitidas.

A nomenclatura inicial de escopos deve permanecer pequena e legível:

- `read:system`
- `read:health`
- `read:docs`
- `write:*` somente após as ferramentas de escrita serem projetadas
- `admin:*` somente após a política de admin ser documentada

Ferramentas MCP devem declarar os escopos mínimos que requerem antes do uso em produção.

## Desenvolvimento Local

Desenvolvimento local pode usar credenciais placeholder apenas quando elas são claramente não-secretas e documentadas como exemplos.

Ferramentas locais podem:

- chamar endpoints HTTP locais públicos sem credenciais quando o endpoint é intencionalmente público
- usar chaves de API apenas para teste geradas fora do repositório
- documentar nomes de variáveis de ambiente necessárias sem valores

Ferramentas locais não devem:

- ler valores `.env` através de ferramentas MCP
- imprimir credenciais na saída de comando
- depender do armazenamento privado de credenciais do desenvolvedor
- contornar autenticação de forma que se assemelhe ao comportamento de produção

## Expectativas de Produção

Autenticação de produção requer design explícito antes da implementação.

Antes de habilitar credenciais de produção, documente:

- tipo de credencial
- proprietário e processo de rotação
- ambientes permitidos
- escopos necessários
- backend de armazenamento
- campos de auditoria
- comportamento de falha para credenciais ausentes, inválidas, expiradas ou insuficientes

Falhas de validação de credenciais devem usar respostas Problem Details e não devem revelar se um valor secreto existe.

## Logging e Observabilidade

Logs podem incluir:

- id de request
- tipo de credencial
- id do proprietário da credencial quando seguro
- nomes de escopos
- resultado da autenticação
- categoria de motivo de falha

Logs não devem incluir:

- chaves de API brutas
- tokens Bearer
- cookies
- headers de autorização
- hashes de credenciais

## OpenAPI e MCP

Esquemas de segurança OpenAPI devem corresponder ao middleware implementado.

Quando adicionado, OpenAPI deve descrever:

- localização da credencial
- escopos necessários
- respostas Problem Details `401` e `403`
- exemplos sem segredos reais

Metadados MCP devem referenciar escopos necessários, não credenciais brutas.

Ferramentas MCP de escrita, admin e destrutivas requerem autenticação, autorização, auditoria, propagação de request id e comportamento de confirmação antes da implementação.
