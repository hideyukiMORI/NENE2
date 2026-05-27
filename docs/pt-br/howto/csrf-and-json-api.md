# CSRF e APIs JSON

## CORS ≠ proteção contra CSRF

Esta é uma das concepções de segurança equivocadas mais comuns no desenvolvimento de APIs web.

**CORS** (Cross-Origin Resource Sharing) controla se um navegador permitirá que JavaScript em uma origem *leia a resposta* de outra origem. O servidor adiciona headers `Access-Control-Allow-Origin`; o navegador aplica a política.

**CSRF** (Cross-Site Request Forgery) é um ataque onde uma página maliciosa engana o navegador da vítima para enviar uma requisição que altera estado para um site confiável — usando os cookies de sessão da vítima.

O `CorsMiddleware` do NENE2 lida com CORS. Ele **não** bloqueia requisições de origens desconhecidas. Uma requisição com `Origin: https://evil.example.com` passa e chega ao seu handler sem alterações — esse é o comportamento esperado. CORS é uma proteção do navegador que limita o que o *JavaScript pode ler*, não o que o *servidor aceita*.

```
# Todos esses chegam ao seu handler — o CorsMiddleware NÃO os bloqueia
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# (sem header Origin — ex.: chamadas server-to-server)
```

## Por que APIs JSON são mais resistentes a CSRF do que APIs baseadas em formulários

O CSRF clássico explora formulários HTML (`<form method="POST">`). Navegadores enviam submissões de formulário com `Content-Type: application/x-www-form-urlencoded` ou `multipart/form-data` — o navegador inclui cookies de sessão automaticamente.

Uma requisição com `Content-Type: application/json` **não é uma "requisição simples"** segundo a especificação CORS. O navegador envia um preflight `OPTIONS` primeiro. Se a sua configuração CORS não listar a origem do atacante, o navegador bloqueia o preflight — a requisição real nunca chega.

Porém, **isso só protege ataques baseados em navegador**. Um servidor ou uma chamada `fetch()` com headers explícitos pode enviar `Content-Type: application/json` para a sua API sem restrição. Preflights CORS são aplicados por navegadores, não por servidores.

## A proteção real: Bearer JWT

A autenticação padrão do NENE2 usa Bearer JWTs no header `Authorization`:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

Ataques CSRF funcionam abusando de cookies — o navegador os anexa automaticamente em requisições cross-site. O header `Authorization` **nunca** é enviado automaticamente. Uma página maliciosa não consegue incluir o JWT da vítima porque JavaScript em `https://evil.example.com` não consegue ler o token de `https://app.example.com`.

Se você usa autenticação Bearer JWT e nunca coloca tokens em cookies, você não é vulnerável a CSRF por design. Nenhum token CSRF adicional ou atributo `SameSite` é necessário.

## Se você usa sessões baseadas em cookie

Se a sua aplicação usa `Set-Cookie` para gerenciamento de sessão (em vez de Bearer JWT), você precisa de proteção CSRF explícita:

### Opção 1: Cookies SameSite (mais simples)

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` impede que o navegador inclua o cookie em requisições cross-site. `SameSite=Lax` também é um padrão razoável que ainda bloqueia `POST` cross-site.

### Opção 2: Middleware de validação do header Origin

Rejeite requisições cujo `Origin` não corresponda à sua allowlist:

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Chamadas não-navegador (curl, server-to-server) não têm Origin — permitir
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // Retornar resposta 403 Problem Details
            // ...
        }

        return $handler->handle($request);
    }
}
```

Registre-o após o CORS na pilha de middlewares (consulte a seção 5 do CLAUDE.md para ordenação).

### Opção 3: Token CSRF

Gere um token por sessão, armazene-o no servidor, inclua-o nos formulários como campo oculto e verifique-o em cada requisição que altera estado. Esta é a abordagem tradicional, mas adiciona complexidade.

## Resumo

| Cenário | Risco de CSRF | Mitigação recomendada |
|---------|---------------|----------------------|
| Bearer JWT no header `Authorization` | Nenhum — header não é enviado automaticamente | Nenhuma ação necessária |
| Sessão em cookie, SameSite=Strict | Muito baixo | Mantenha `SameSite=Strict` |
| Sessão em cookie, sem SameSite | Alto | Adicione `SameSite` ou verificação de Origin |
| API key em header customizado | Nenhum — headers customizados não são enviados automaticamente | Nenhuma ação necessária |

O caminho mais simples: use a autenticação Bearer JWT nativa do NENE2 e evite sessões baseadas em cookie para endpoints de API.
