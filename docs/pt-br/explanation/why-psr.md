# Por que os padrões PSR?

NENE2 é construído sobre PSR-7, PSR-15 e PSR-17 em vez de abstrações HTTP personalizadas. Esta página explica o raciocínio.

## O que esses padrões cobrem

| Padrão | O que define |
|--------|-------------|
| PSR-7 | `RequestInterface`, `ResponseInterface`, `StreamInterface` — a forma das mensagens HTTP |
| PSR-15 | `MiddlewareInterface`, `RequestHandlerInterface` — como middlewares e handlers se compõem |
| PSR-17 | Interfaces de factory para criar objetos PSR-7 |

## Por que não uma abstração personalizada?

Uma classe `Request` personalizada é rápida de escrever e fácil de controlar. O custo aparece mais tarde:

- Cada nova biblioteca HTTP precisa de um adaptador personalizado.
- Middlewares escritos para um projeto não podem ser movidos para outro.
- Testes requerem um servidor HTTP em execução ou a própria classe personalizada.

Os objetos PSR-7 são value objects imutáveis. Um handler que aceita `ServerRequestInterface` e retorna `ResponseInterface` não faz suposições sobre o framework que o chama.

## Por que mensagens imutáveis?

Mensagens PSR-7 são imutáveis: `withHeader()`, `withBody()` e métodos similares retornam uma nova instância em vez de mutar a existente. Isso elimina uma classe de bugs onde um middleware modifica silenciosamente uma requisição que um handler posterior inspeciona.

```php
// Cada middleware recebe uma cópia limpa — o original não é alterado
$request = $request->withAttribute('request_id', $id);
```

## Por que middleware PSR-15?

PSR-15 define o contrato de middleware com um único método:

```php
public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $next
): ResponseInterface
```

Isso significa:

- Qualquer middleware PSR-15 pode ser inserido em qualquer pipeline PSR-15.
- A ordem do pipeline é código explícito, não um ciclo de vida de framework oculto.
- Testar um middleware unitariamente requer apenas um mock `RequestHandlerInterface`, não um servidor em execução.

## Escolha do pacote concreto

NENE2 usa **Nyholm PSR-7** para objetos de mensagem e **Relay** para o dispatcher de middleware (ver ADR 0001).

## Compromissos

| Benefício | Custo |
|---------|-------|
| Middleware interoperável | Mais verboso que uma API fluente personalizada |
| Mensagens imutáveis reduzem bugs | Criação de objetos a cada chamada `with*` |
| Testável sem servidor | Requer compreender as interfaces PSR |
