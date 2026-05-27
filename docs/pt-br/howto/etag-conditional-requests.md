# ETag e Requisições Condicionais

> **Referência FT**: FT307 (`NENE2-FT/etaglog`) — Requisições condicionais com ETag: `If-None-Match`→304, `If-Modified-Since`→304, `If-Match`→412 obsoleto / 428 ausente, wildcard `If-Match: *` passa, ETag muda após cada atualização, 15 testes PASS.

ETags permitem que clientes evitem baixar novamente conteúdo não alterado e detectem estado obsoleto antes de escrever. O NENE2 fornece dois helpers para os padrões mais comuns.

| Cenário | Header | Helper | Ao corresponder |
|---|---|---|---|
| GET condicional | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| Escrita condicional | `If-Match` | `ConditionalWriteHelper` | escrita prossegue |
| Escrita sem header | — | `ConditionalWriteHelper` | 428 Precondition Required |
| ETag de escrita obsoleto | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## Geração de ETag

Gere uma ETag forte a partir do conteúdo do recurso como um MD5 entre aspas duplas:

```php
final readonly class Article
{
    public function etag(): string
    {
        // Aspas duplas são exigidas pela RFC 9110 — sem elas a comparação com If-None-Match sempre falha
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

Mantenha a geração de ETag em um único lugar (um método na entidade) para que alterar o algoritmo (ex.: para SHA-256) seja uma única edição.

## GET condicional — 304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // Retorna uma resposta 304 quando If-None-Match corresponde à ETag atual.
    // Retorna null quando uma resposta 200 completa deve ser enviada.
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` avalia dois headers:
- `If-None-Match`: correspondência exata de ETag → 304
- `If-Modified-Since`: comparação de string `$ifModifiedSince >= $lastModified` → 304

Sempre inclua o mesmo valor `$etag` tanto na chamada `check()` quanto na chamada `withHeader('ETag', $etag)`. Gerá-los separadamente arrisca divergência.

### Formato Last-Modified

A verificação `If-Modified-Since` é uma **comparação de string**, não uma comparação de data interpretada. Use um formato que ordena lexicograficamente — ISO 8601 é recomendado:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

O formato padrão HTTP `Sat, 21 May 2026 12:00:00 GMT` ordena incorretamente — não use-o com este helper.

### 304 não tem corpo

A RFC 9110 proíbe um corpo em respostas 304. `ConditionalGetHelper` retorna um `createResponse(304)` vazio, portanto isso é tratado corretamente contanto que você retorne a resposta do helper diretamente.

## Escrita condicional — If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // Deve ser chamado ANTES da escrita — verificar depois não tem sentido.
    // Retorna 428 quando If-Match está ausente; 412 quando If-Match está presente mas errado.
    // Retorna null quando a pré-condição passa.
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * wildcard

Um cliente pode enviar `If-Match: *` para significar "prossiga se o recurso existir de alguma forma". `ConditionalWriteHelper` passa isso incondicionalmente. **O chamador é responsável por retornar 404 quando o recurso não existe** — busque o registro primeiro e proteja com um 404.

### Tornando If-Match opcional

Por padrão (`$require = true`), `If-Match` ausente retorna 428. Para permitir escritas sem header de pré-condição:

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

Só relaxe isso quando o locking otimista for genuinamente opcional para o recurso.

## Fluxo do cliente

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304 (sem corpo)
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Atualizado" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       (obsoleto — conteúdo mudou, ETag agora é "def456")

PATCH /articles/1         → 428 Precondition Required
  (sem header If-Match)

PATCH /articles/1         → 200 { ... }
  If-Match: *              (wildcard — qualquer versão existente)
```

## Sempre inclua ETag em toda resposta

Retorne `ETag` (e `Last-Modified`) nas respostas POST, GET e PATCH para que o cliente sempre tenha um valor fresco sem um round-trip extra:

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag vs campo Version

| | ETag (header HTTP) | Campo version (body) |
|---|---|---|
| Onde é verificado | Header HTTP | Corpo da requisição |
| Granularidade | Hash do conteúdo | Contador inteiro |
| Cliente precisa rastrear | Valor da ETag | Número de versão |
| Melhor para | Cache HTTP + locking otimista | Detecção de conflito no nível da API |

Eles podem ser usados juntos: ETag para cache HTTP, version para detecção de conflito no nível do DB (veja [optimistic-locking.md](optimistic-locking.md)).

## Checklist de revisão de código

- [ ] String ETag inclui aspas duplas ao redor (`'"' . md5(...) . '"'`)
- [ ] Geração de ETag está em um único lugar (método da entidade), não duplicada entre handlers
- [ ] `ConditionalGetHelper::check()` é chamado antes de construir a resposta 200
- [ ] O mesmo valor `$etag` é passado tanto para `check()` quanto para `withHeader('ETag', $etag)`
- [ ] `ConditionalWriteHelper::check()` é chamado antes da escrita
- [ ] Corpo de resposta 304 está vazio (use a resposta do helper diretamente)
- [ ] Valores de `Last-Modified` usam formato ISO 8601 (ordenação lexicográfica obrigatória)
- [ ] Toda resposta (201, 200) inclui `ETag` para que o cliente sempre tenha um valor fresco
- [ ] Testes cobrem: 200 sem `If-None-Match`, 304 na correspondência, 200 em ETag obsoleto, 428 sem `If-Match`, 412 em `If-Match` obsoleto, 200 em `If-Match` correto, `If-Match: *`
