# Guia de Início de Projeto Cliente

Este guia explica como adaptar o NENE2 em um pequeno projeto de API estilo cliente.

Intencionalmente prático e manual. O objetivo é tornar a primeira entrega de projeto credível antes de adicionar geradores ou camadas amplas de conveniência do framework.

## Ponto de Partida

Use este guia quando um projeto precisar de:

- uma API JSON local em funcionamento
- documentação OpenAPI que pode ser compartilhada cedo
- um pequeno conjunto de endpoints testados
- integração opcional de starter React
- inspeção MCP local segura através de fronteiras de API documentadas
- autenticação básica de machine-client
- um caminho de verificação de banco de dados baseado em Docker

O NENE2 ainda é uma fundação `0.x`. Trate contratos públicos como úteis mas ainda em formação.

## Sandbox de Referência para Testes de Campo Públicos (opcional)

Após o primeiro milestone local, pode ser útil inspecionar uma **demo pública completa** que permaneceu no caminho de scaffold documentado:

- Repositório: [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial) (baseado no NENE2 **`v0.1.1`**).
- Conteúdo: APIs JSON somente leitura em formato de exposição, OpenAPI, PHPUnit, ferramentas MCP locais e notas de testes de campo em Markdown.

Isto **não é** um repositório de produto oficial e **não implica endosso** de nenhuma exposição real. **Dados sandbox fictícios** — leia o `README.md` e `SECURITY.md` daquele projeto antes de tratar nomes ou anos como fatos.

## Começando com `composer require`

Se você está começando um novo projeto do zero em vez de fazer fork do repositório NENE2:

```bash
mkdir my-project && cd my-project
composer init --name="vendor/my-project" --no-interaction
composer require hideyukimori/nene2:^0.3
```

Então crie os arquivos mínimos manualmente:

**`.env`**
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="My Project"
DB_ADAPTER=sqlite
```

**`public/index.php`** — front controller usando o container integrado:
```php
<?php
declare(strict_types=1);

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeContainerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = (new RuntimeContainerFactory(dirname(__DIR__)))->create();
$psr17     = $container->get(Psr17Factory::class);
$request   = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response  = $container->get(RequestHandlerInterface::class)->handle($request);
$container->get(ResponseEmitter::class)->emit($response);
```

Sirva localmente com o servidor integrado do PHP:
```bash
php -S localhost:8080 -t public
```

### Adicionando Rotas Personalizadas

Passe rotas personalizadas via `$routeRegistrars`:

```php
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
                $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                return $json->create(['id' => (int) ($params['id'] ?? 0)]);
            });
        },
    ],
))->create();
```

## Primeira Configuração Local

Comece a partir de um clone limpo:

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

Confirme a API e os docs locais:

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

URLs úteis no navegador:

- OpenAPI: `http://localhost:8080/openapi.php`
- Interface Swagger: `http://localhost:8080/docs/`

## Renomear a Fronteira do Projeto

Antes de adicionar comportamento de aplicação, atualize primeiro os metadados voltados ao projeto:

- Descrição do projeto no `README.md`
- Nome e descrição do pacote no `composer.json`
- `info.title`, `info.description` e `info.version` do OpenAPI
- Exemplos padrão que não devem mais descrever o NENE2 em si

## Adicionar o Primeiro Endpoint de Aplicação

Use `docs/development/endpoint-scaffold.md` para cada endpoint JSON entregue.

1. Criar ou reutilizar uma Issue GitHub focada.
2. Adicionar a rota na menor fronteira de runtime clara.
3. Adicionar o caminho OpenAPI, `operationId`, schema e exemplos.
4. Adicionar testes runtime próximos ao comportamento do endpoint.
5. Deixar `tests/OpenApi/RuntimeContractTest.php` verificar os exemplos de sucesso documentados.
6. Executar um smoke check HTTP local via Docker.

## Manter OpenAPI como Contrato de Entrega

O OpenAPI deve ser atualizado no mesmo PR que o comportamento do endpoint.

Antes de enviar a entrega, execute:

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## Adicionar MCP Apenas Através de Fronteiras de API

1. Adicionar ou confirmar a operação OpenAPI.
2. Adicionar uma entrada read-only em `docs/mcp/tools.json`.
3. Executar `docker compose run --rm app composer mcp`.
4. Smoke test o servidor MCP local apenas contra APIs locais.

## Proteger Caminhos Machine-Client

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

Não comite chaves de API reais, segredos gerados ou arquivos `.env` locais.

## Verificar Comportamento do Banco de Dados

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Checklist de Entrega

Antes de entregar um projeto estilo cliente, confirme:

- `README.md` descreve o projeto, não apenas o starter.
- `docs/openapi/openapi.yaml` corresponde ao comportamento JSON entregue.
- A interface Swagger carrega localmente.
- Novos endpoints têm testes runtime e exemplos OpenAPI.
- Rotas protegidas documentam credenciais necessárias sem expor valores secretos.
- Ferramentas MCP, se houver, chamam apenas fronteiras de API documentadas.
- `docker compose run --rm app composer check` passa.
- Trabalho adiado é visível em `docs/todo/current.md`.

## Próximos Documentos Úteis

- Política de camada de domínio: `docs/development/domain-layer.md`
- Workflow de scaffold de endpoint: `docs/development/endpoint-scaffold.md`
- Guia de servidor MCP local: `docs/integrations/local-mcp-server.md`
- Fronteira de autenticação: `docs/development/authentication-boundary.md`
- Estratégia de teste de banco de dados: `docs/development/test-database-strategy.md`
