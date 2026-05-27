# Como Fazer: Controle de Concorrência Otimista (Campo de Versão)

> **Referência FT**: FT323 (`NENE2-FT/optimisticlog`) — API de documentos com campo de versão no corpo do PUT, 409 em versão obsoleta, prevenção de perda de atualização, 18 testes / 34 asserções PASS.

Este guia mostra como implementar controle de concorrência otimista passando um campo `version` no corpo da requisição, como alternativa aos headers HTTP ETag/If-Match.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/documents` | Criar (versão começa em 1) |
| `GET`  | `/documents` | Listar |
| `GET`  | `/documents/{id}` | Obter com versão |
| `PUT`  | `/documents/{id}` | Atualizar (versão obrigatória no corpo) |

## Criação

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## Atualização com Versão

O cliente lê a `version` atual e a inclui no corpo do PUT:

```php
// Ler
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// Atualizar com versão correta
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

A versão incrementa em cada atualização bem-sucedida.

## Versão Obsoleta — 409 Conflict

```php
// Alice e Bob leem a versão 1
// Alice atualiza primeiro → versão torna-se 2
// Bob tenta atualizar com versão 1 → rejeitado
PUT /documents/1
{"title": "Bob's edit", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob lê novamente, obtém versão 2, tenta novamente
PUT /documents/1
{"title": "Bob's edit", "version": 2}
→ 200  {"version": 3}
```

## Implementação

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

A cláusula `WHERE version = ?` na query UPDATE é a guarda atômica contra escritas concorrentes.

## Versão vs ETag

| Aspecto | Campo de Versão (este guia) | ETag / If-Match (veja `optimistic-locking-etag.md`) |
|---------|----------------------------|-----------------------------------------------------|
| Protocolo | Campo do corpo | Header HTTP |
| UX do cliente | `"version": N` explícito em JSON | Header `If-Match: "vN"` |
| Payload do 409 | Pode retornar `current_version` | 412 — sem padrão de corpo |
| Verificação ausente | 422 (faltando `version`) | 428 (faltando `If-Match`) |

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar PUT sem campo `version` | Perda de atualização: última escrita vence silenciosamente |
| Retornar 200 em versão obsoleta | Sobrescrita silenciosa de mudanças concorrentes |
| Verificar versão apenas no código da aplicação (não na cláusula WHERE) | Condição de corrida entre leitura e escrita |
| Não incluir `current_version` na resposta 409 | Cliente deve fazer GET novamente para recuperar; incluir para retry mais rápido |
