# Tipos Problem Details

O NENE2 retorna `application/problem+json` para todas as respostas de erro, seguindo o [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457).

## Catálogo de tipos

| `type` | Status HTTP | `title` | Produzido por |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | Rota não encontrada; Note ou Tag com id não encontrado |
| `…/method-not-allowed` | 405 | Method Not Allowed | Método HTTP incorreto para uma rota conhecida |
| `…/validation-failed` | 422 | Validation Failed | Corpo de requisição inválido ou campos obrigatórios ausentes |
| `…/unauthorized` | 401 | Unauthorized | Token Bearer ausente ou inválido |
| `…/payload-too-large` | 413 | Payload Too Large | Corpo da requisição excede o limite configurado |
| `…/internal-server-error` | 500 | Internal Server Error | Exceção não tratada |

Prefixo URI base: `https://nene2.dev/problems/`

## Adicionando um tipo personalizado

1. Crie uma classe de exceção de domínio (ex.: `ProductNotFoundException`).
2. Implemente `DomainExceptionHandlerInterface` chamando `ProblemDetailsResponseFactory::create()`.
3. Registre o handler em `RuntimeServiceProvider`.

Veja `NoteNotFoundExceptionHandler` e `TagNotFoundExceptionHandler` como exemplos concretos.
