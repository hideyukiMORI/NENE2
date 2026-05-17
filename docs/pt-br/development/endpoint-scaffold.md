# Workflow de Scaffold de Endpoint

Este workflow alinha um novo endpoint JSON NENE2 em todo o código runtime, OpenAPI, testes e metadados MCP opcionais.

Intencionalmente manual por ora. O objetivo é clarificar os passos antes de adicionar geradores.

## Posição

Um endpoint só está completo quando seu comportamento é visível em todos os lugares relevantes:

- Rota runtime e handler
- Caminho OpenAPI, schema de resposta e exemplos
- Testes runtime ou handler
- Testes de contrato via `docs/openapi/openapi.yaml`
- Atualização do catálogo MCP se o endpoint se tornar uma ferramenta
- Atualização de documentação se o endpoint alterar políticas ou workflows do projeto

## Procedimento Padrão

1. Criar ou reutilizar uma Issue GitHub focada.
2. Adicionar ou atualizar a rota runtime na menor fronteira de handler apropriada.
3. Adicionar o caminho OpenAPI com `operationId`, exemplos, schema de sucesso e respostas Problem Details.
4. Adicionar ou atualizar testes próximos ao comportamento.
5. Executar primeiro testes focados, depois `docker compose run --rm app composer check`.
6. Se o endpoint for acessível via Docker, executar um smoke check HTTP local.
7. Atualizar `docs/todo/current.md`, documentos de milestone e catálogo MCP apenas se o trabalho atual for impactado.

## Rota Runtime

No runtime minimal atual, rotas de exemplo são descritas em `RuntimeApplicationFactory`.

Exemplo de scaffold:

```text
GET /examples/ping
```

Resposta retornada:

```json
{
  "message": "pong",
  "status": "ok"
}
```

Este endpoint existe para praticar o workflow. À medida que o comportamento aumenta, endpoints de aplicação devem migrar para handlers finos que delegam aos casos de uso.

### Parâmetros de Caminho

O roteador armazena parâmetros de caminho correspondentes em um array nomeado sob `Router::PARAMETERS_ATTRIBUTE` — não são definidos como atributos PSR-7 individuais.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $request) use ($json): ResponseInterface {
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

Escrever `$request->getAttribute('id')` retorna `null`. Sempre leia de `Router::PARAMETERS_ATTRIBUTE`.

## Requisitos OpenAPI

Cada endpoint JSON enviado deve incluir:

- um `operationId` estável
- um summary curto e description segura
- schema de resposta `200` e exemplo `ok`
- respostas Problem Details apropriadas (`401`, `413`, `500`, etc.)
- requisitos de segurança apenas se o middleware correspondente existir

Testes de contrato runtime leem automaticamente `docs/openapi/openapi.yaml` e validam os exemplos `200` documentados dos endpoints JSON.

## Requisitos de Teste

Use primeiro a verificação mais estreita útil:

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

Depois execute a verificação completa do backend:

```bash
docker compose run --rm app composer check
```

Para endpoints servidos via Docker, execute um smoke check local:

```bash
curl -i http://localhost:8080/examples/ping
```

## Relação com MCP

Se um novo endpoint se tornar uma ferramenta MCP:

1. Adicione primeiro a operação OpenAPI.
2. Adicione uma entrada read-only em `docs/mcp/tools.json` apenas se a ferramenta puder chamar a fronteira da API pública com segurança.
3. Execute `docker compose run --rm app composer mcp`.
4. Ferramentas de mutação, admin e destrutivas permanecem fora do escopo até que comportamento de autenticação, autorização e auditoria seja documentado e implementado.

## Não-objetivos

- Gerar automaticamente arquivos de endpoint antes que o workflow manual seja provado útil.
- Esconder comportamento de rotas com detecção mágica de controllers.
- Atualizar o catálogo MCP para todos os endpoints por padrão.
- Exigir validação OpenAPI runtime antes que padrões de rotas e schemas estejam estáveis.

## Documentação Relacionada

- Runtime: `src/Http/RuntimeApplicationFactory.php`
- OpenAPI: `docs/openapi/openapi.yaml`
- Testes de contrato runtime: `tests/OpenApi/RuntimeContractTest.php`
- Política de validação de requests: `docs/development/request-validation.md`
- Política de camada de domínio: `docs/development/domain-layer.md`
- Política de ferramentas MCP: `docs/integrations/mcp-tools.md`
