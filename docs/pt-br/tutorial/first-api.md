# Sua primeira API em 10 minutos

Este tutorial leva você do zero a uma API JSON funcionando com NENE2.

Ao final você terá:
- uma API local que responde a requisições HTTP
- um endpoint `/hello` que retorna JSON
- um entendimento de como as requisições fluem pelo framework

**Para quem é**: Desenvolvedores que conhecem JavaScript ou Python mas não usaram PHP antes. Se você já usou Express ou FastAPI, os conceitos se mapeiam diretamente.

**Tempo**: cerca de 10 minutos.

---

## O que você precisa

| Ferramenta | Por quê | Verificação |
|---|---|---|
| PHP 8.4 | executa a aplicação | `php --version` |
| Composer | gerenciador de pacotes PHP (como npm) | `composer --version` |
| Um terminal | todos os comandos rodam aqui | — |

> **Alternativa com Docker**: se você preferir não instalar PHP localmente, o Docker também funciona.
> Veja [Configuração com Docker](#configuração-com-docker) no final desta página.

---

## Passo 1 — Criar um diretório de projeto

```bash
mkdir my-api && cd my-api
```

É o equivalente a `mkdir my-app && cd my-app` em um projeto Node.js.

---

## Passo 2 — Instalar o NENE2

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` é o equivalente PHP do `npm install`. Ele baixa o NENE2 e suas dependências para `vendor/`.

Após isso, seu diretório ficará assim:

```
my-api/
  vendor/        ← pacotes instalados (como node_modules/)
  composer.json  ← metadados do pacote (como package.json)
  composer.lock  ← versões fixadas (como package-lock.json)
```

---

## Passo 3 — Criar um arquivo `.env`

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` funciona da mesma forma que no Node.js. O framework o lê automaticamente na inicialização.

---

## Passo 4 — Criar o front controller

Crie `public/index.php`:

```php
<?php
declare(strict_types=1);

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/hello', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['message' => 'Hello, world!', 'status' => 'ok']);
            });
        },
    ],
))->create();

$request  = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response = $app->handle($request);

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
```

**O que isso faz** (linha por linha):

- `require .../vendor/autoload.php` — carrega todos os pacotes instalados, como `import` em JS
- `$psr17 = new Psr17Factory()` — cria factories de objetos HTTP (pense: construtores de request/response)
- `RuntimeApplicationFactory` — monta o pipeline de middleware completo
- `routeRegistrars` — onde você adiciona suas próprias rotas (veja os docs HOWTO)
- `$router->get('/hello', ...)` — registra uma rota GET, como `app.get('/hello', ...)` no Express
- `$json->create([...])` — constrói uma resposta JSON a partir de um array PHP

---

## Passo 5 — Iniciar o servidor

```bash
php -S localhost:8080 -t public
```

Este é o servidor de desenvolvimento embutido do PHP. É o equivalente de `npm run dev` — não para produção, mas ótimo para desenvolvimento local.

Você deve ver:

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## Passo 6 — Chamar a API

Abra um novo terminal e execute:

```bash
curl http://localhost:8080/hello
```

Você deve ver:

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

Experimente também o endpoint de health check embutido:

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

Essa é sua primeira API funcionando. Vamos ver o que mais está incluído.

---

## Passo 7 — Ver o tratamento de erros em ação

NENE2 retorna [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457) para todos os erros. Chame uma rota que não existe:

```bash
curl http://localhost:8080/missing
```

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "instance": "/missing"
}
```

Cada resposta de erro tem um URI `type`, um `title` e um `status` HTTP. Este é o formato padrão usado em todas as respostas de erro do NENE2.

---

## O que acabou de acontecer

Aqui está o fluxo de requisição para `GET /hello`:

```
Requisição HTTP
  → RequestIdMiddleware      adiciona o cabeçalho X-Request-Id
  → SecurityHeadersMiddleware adiciona X-Content-Type-Options etc.
  → CorsMiddleware           trata o preflight CORS
  → ErrorHandlerMiddleware   captura exceções não tratadas
  → RequestSizeLimitMiddleware rejeita payloads muito grandes
  → Router                   corresponde /hello → seu handler
  → seu handler              retorna {"message": "Hello, world!"}
Resposta HTTP
```

Tudo isso acontece automaticamente. Seu handler só precisa retornar uma resposta — o framework cuida dos cabeçalhos, formatação de erros e correlação de requisições.

---

## Próximos passos

- **Adicionar um parâmetro de rota** (como `/hello/{name}`): veja [Adicionar uma rota personalizada](../howto/add-custom-route.md)
- **Conectar um banco de dados**: veja [Adicionar um endpoint com banco de dados](../howto/add-database-endpoint.md)
- **Ver a documentação completa da API**: inicie o servidor e abra `http://localhost:8080/openapi.php`

---

## Configuração com Docker

Se você preferir Docker a uma instalação local de PHP:

```bash
mkdir my-api && cd my-api
```

Crie um `compose.yaml` mínimo:

```yaml
services:
  app:
    image: php:8.4-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html
```

Então instale o Composer dentro do container:

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

Siga os Passos 3–4 acima para criar `.env` e `public/index.php`, depois:

```bash
docker compose up -d
curl http://localhost:8080/hello
```

Para uma configuração Docker mais completa com suporte a MySQL, veja o [guia de configuração do repositório NENE2](../development/setup.md).
