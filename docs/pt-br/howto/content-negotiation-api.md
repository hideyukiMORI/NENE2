# Como Fazer: Negociação de Conteúdo — API JSON

> **Referência FT**: FT301 (`NENE2-FT/contentlog`) — Negociação de conteúdo de API JSON: sempre retorna `application/json` independente do header `Accept`, `application/problem+json` para erros (404/422/405), 415 em `Content-Type` não JSON para POST, 16 testes / 28 asserções PASS.

Este guia explica como o runtime do NENE2 lida com negociação de conteúdo HTTP para APIs JSON — quais valores do header `Accept` são aceitos, quando o `Content-Type` importa e como respostas de erro usam `application/problem+json`.

## Sempre JSON — Ignorando o Header Accept

APIs JSON do NENE2 retornam `application/json` para respostas de sucesso independente do header `Accept` enviado pelo cliente:

| Header Accept enviado | Content-Type da resposta |
|---|---|
| _(nenhum)_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

Isso é intencional para serviços de API pura: o servidor é um endpoint exclusivamente de API, não um servidor multi-formato com negociação de conteúdo. Clientes que enviam `Accept: text/html` ainda recebem JSON.

## Respostas de Erro — application/problem+json

Respostas de erro usam `application/problem+json` (RFC 9457) independente do header `Accept`:

| Cenário | Status | Content-Type |
|---|---|---|
| Rota não encontrada | 404 | `application/problem+json` |
| Método não permitido | 405 | `application/problem+json` |
| Falha de validação | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory sempre produz application/problem+json
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

Clientes podem detectar erros tanto pelo código de status HTTP quanto verificando `Content-Type: application/problem+json`.

## Content-Type da Requisição — Corpos POST

Para requisições `POST` com corpo JSON, o NENE2 usa `JsonRequestBodyParser::parse()`:

```php
$body = JsonRequestBodyParser::parse($request);
```

Se a requisição tiver um `Content-Type: text/plain` explícito ou outro tipo não JSON, o parser pode retornar um array vazio. Porém, se o corpo for JSON válido sem nenhum header `Content-Type`, o parser o aceita:

```
POST /articles (sem Content-Type, corpo JSON) → 201 Created ✅
POST /articles (Content-Type: text/plain) → 415 Unsupported Media Type ✅
```

## Validação — Campos Obrigatórios

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

Após `trim()`, uma string vazia é tratada da mesma forma que um campo ausente. O erro de validação retorna um array `errors` estruturado com chaves `field`, `code` e `message` — extensão padrão do RFC 9457.

## Formato de Resposta

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## Registro de Rotas

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles` (lista) é registrado antes de `GET /articles/{id}` (único) — embora neste caso ambos sejam GET com caminhos diferentes, então a ordem não cria conflito de captura. A rota de lista usa um caminho estático; a rota de único usa captura dinâmica `{id}`.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 406 para headers `Accept` não suportados | Serviços exclusivos de API devem servir JSON para todos os clientes, não recusar |
| Usar `text/json` em vez de `application/json` | Tipo MIME não padrão; alguns clientes não vão reconhecê-lo |
| Retornar `application/json` simples para respostas de erro | Clientes não conseguem distinguir erros de sucesso pelo Content-Type; use `application/problem+json` |
| Omitir o array `errors` nos erros de validação | Clientes não conseguem mostrar mensagens de erro por campo para usuários |
| Aceitar `Content-Type: text/plain` para corpos JSON | Input ambíguo; seja explícito sobre quais tipos de conteúdo são aceitos |
| Fazer trim após a validação | `trim()` deve vir antes da verificação de string vazia; `" "` passaria se você verificar antes do trim |
