# Por que RFC 9457 Problem Details?

Os erros de API da NENE2 usam o formato RFC 9457 Problem Details. Esta página explica a escolha.

## Como Problem Details é exibido

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## Por que um padrão em vez de um formato personalizado?

### 1. Clientes podem tratar erros genericamente

Um cliente que conhece RFC 9457 pode exibir `title` e `status` para qualquer erro de qualquer API RFC 9457.

### 2. `Content-Type: application/problem+json` é legível por máquina

Quando uma resposta carrega `application/problem+json`, um cliente sabe que recebeu um objeto de erro. Isso importa para ferramentas MCP e outros clientes máquina.

### 3. A URI `type` dá às erros uma identidade estável

Cada tipo de problema tem uma URI como `https://nene2.dev/problems/validation-failed`. Essa URI é estável, documentável e usável para correspondência de padrões pelo cliente.

### 4. É um padrão publicado

RFC 9457 (sucessor do RFC 7807) é um padrão IETF publicado.

## As URIs `nene2.dev`

As URIs `type` na NENE2 atualmente usam `https://nene2.dev/problems/...` como domínio de espaço reservado. Antes de ir para produção, o implantador deve registrar esse domínio ou substituir a URL base em `ProblemDetailsResponseFactory`.
