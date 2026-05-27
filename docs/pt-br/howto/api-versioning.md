# Como Fazer: Versionamento de API

> **Referência FT**: FT346 (`NENE2-FT/versionlog`) — Versionamento por caminho de URL com namespaces /v1/ e /v2/, V1 depreciado com cabeçalhos Deprecation/Sunset/Link, V2 com formato de resposta enriquecido, armazenamento compartilhado, 16 testes PASS.

Este guia mostra como implementar versionamento por caminho de URL: executar duas versões de API lado a lado com diferentes formatos de resposta, marcar a versão antiga como depreciada com cabeçalhos HTTP e compartilhar um único banco de dados entre versões.

## Estratégia de Versão

| Versão | Status      | Prefixo | Wrapper de lista              |
|--------|-------------|---------|-------------------------------|
| V1     | Depreciada  | `/v1/`  | `{"notes": [...]}`            |
| V2     | Atual       | `/v2/`  | `{"data": [...], "meta": {...}}` |

Ambas as versões compartilham as mesmas tabelas do banco de dados. Os clientes V1 podem continuar usando sua integração existente enquanto os cabeçalhos de depreciação sinalizam um prazo de migração.

## Endpoints

| Método   | Caminho V1       | Caminho V2       | Descrição            |
|----------|------------------|------------------|----------------------|
| `POST`   | `/v1/notes`      | `/v2/notes`      | Criar nota           |
| `GET`    | `/v1/notes`      | `/v2/notes`      | Listar notas         |
| `GET`    | `/v1/notes/{id}` | `/v2/notes/{id}` | Obter nota única     |

## Formato de Resposta V1

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← nome do campo: "content"
  "created_at": "..."
  // Sem "body", "tags", "updated_at"
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← chave wrapper: "notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## Formato de Resposta V2

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← chave de envelope: "data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← nome do campo: "body"
    "tags": ["php", "api"],  // ← tags adicionadas
    "updated_at": "...",     // ← updated_at adicionado
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← wrapper de lista: "data"
  "meta": {               // ← seção meta
    "limit": 20,
    "offset": 0
  }
}
```

## Cabeçalhos de Depreciação V1

Toda resposta V1 carrega três cabeçalhos informando os clientes sobre a migração:

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// Todo endpoint V1 adiciona:
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

As respostas V2 **não** carregam nenhum desses cabeçalhos.

```php
// Cabeçalhos GET /v1/notes V1:
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// Cabeçalhos GET /v2/notes V2:
// (sem Deprecation, Sunset ou Link)
```

## Armazenamento Compartilhado — Acesso entre Versões

Ambas as versões compartilham a mesma tabela `notes`. Uma nota criada via V1 é legível pelo V2 (e vice-versa):

```php
// Criar via V1
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// Ler via V2 — mesmo registro, formato V2
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 chama de "body", não "content"
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

Os clientes V1 nunca veem `tags` (não está no formato de resposta V1), mesmo que a nota tenha tags de uma escrita V2.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- array JSON
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

A coluna subjacente é `body`. V1 a mapeia para `content` no transformador de resposta.

## Implementação — Transformadores de Resposta

```php
// Transformador V1 — mapeia coluna "body" → campo "content", oculta tags/updated_at
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // renomeação de campo
            'created_at' => $row['created_at'],
            // Sem "body", "tags", "updated_at"
        ];
    }
}

// Transformador V2 — linha completa, envolto em "data"
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## Registro de Rotas

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

Ambos os registrars são passados para `RuntimeApplicationFactory` — as rotas de ambos são registradas no mesmo roteador.

## Versão Desconhecida → 404

```php
GET /v3/notes
→ 404
```

Não existe rota V3; o roteador retorna 404. Nenhum tipo de erro "versão não suportada" é necessário — 404 é suficiente.

## Validação

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title é obrigatório

POST /v2/notes  {"body": "no title"}
→ 422  // title é obrigatório
```

Ambas as versões exigem `title`. V1 aceita `content` como campo de corpo; V2 aceita `body`.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Tabelas diferentes do banco de dados por versão | Leituras entre versões quebram; dados migram mal quando versões não compartilham estado |
| Retornar `Deprecation: true` no V2 | Clientes não conseguem distinguir qual versão é atual |
| Sem cabeçalho `Link` com o sucessor | Clientes com versão depreciada não sabem para onde migrar |
| Renomear coluna `body` → `content` no banco de dados para V1 | Todo código V2 deve mudar; use transformador de resposta para renomear, não schema |
| Data de Sunset hard-coded em testes | Testes falham após a data de sunset; use uma constante futura ou valor de configuração |
| Expor `tags` do V1 na resposta | Clientes V1 recebem um campo que não entendem; contratos de formato quebram silenciosamente |
