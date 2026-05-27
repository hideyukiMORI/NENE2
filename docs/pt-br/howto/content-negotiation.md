# Negociação de conteúdo

O NENE2 é um framework que prioriza JSON. Ele não implementa negociação de conteúdo — todas as respostas usam `application/json` (ou `application/problem+json` para erros) independente do que o cliente envia no header `Accept`.

## O que o NENE2 faz

| Cliente envia | Servidor retorna |
|---|---|
| Sem header `Accept` | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**O NENE2 nunca retorna `406 Not Acceptable`.** O RFC 7231 §6.5.6 diz que o servidor DEVE retornar 406 quando nenhum tipo aceitável estiver disponível, mas isso é um SHOULD (não MUST). Para um servidor de API exclusivamente JSON, sempre retornar JSON é a escolha mais simples e mais comum.

Respostas de erro usam `application/problem+json` (RFC 9457) independente do `Accept`:

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## Content-Type do corpo da requisição

`JsonRequestBodyParser::parse()` não verifica o header `Content-Type` da requisição de entrada. Ele tenta fazer o JSON-decode do corpo incondicionalmente:

```php
// Todos os três chegam ao JsonRequestBodyParser::parse() de forma idêntica:
// Content-Type: application/json → funciona
// Content-Type: application/x-www-form-urlencoded → 400 (falha no parse JSON de corpo de formulário)
// (sem Content-Type) + corpo JSON → funciona
```

Isso significa:
- Um corpo JSON válido sem `Content-Type` é aceito — política de input liberal.
- Um corpo codificado como formulário (`name=Alice&age=30`) resulta em um 400 Bad Request (falha no parse JSON), não um 415 Unsupported Media Type.

## Se você precisar de respostas 406 ou 415

Adicione um middleware que inspecione os headers `Accept` e `Content-Type` antes do handler de rota:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Forçar Accept exclusivamente JSON (opcional — a maioria dos clientes envia */* ou application/json)
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // Forçar Content-Type JSON em requisições que alteram estado
        $method      = strtoupper($request->getMethod());
        $contentType = $request->getHeaderLine('Content-Type');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && $contentType !== ''
            && !str_contains($contentType, 'application/json')
        ) {
            return $this->problems->create($request, 'unsupported-media-type', 'Unsupported Media Type', 415,
                'This API only accepts application/json request bodies.');
        }

        return $handler->handle($request);
    }
}
```

Configure via `RuntimeApplicationFactory`:

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **Nota:** `authMiddleware` é avaliado antes do roteamento. Coloque a validação do tipo de conteúdo aqui se quiser que seja aplicada globalmente.
