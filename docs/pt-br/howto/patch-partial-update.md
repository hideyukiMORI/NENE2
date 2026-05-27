# Como Fazer: Atualização Parcial com PATCH (JSON Merge Patch)

> **Referência FT**: FT326 (`NENE2-FT/patchlog`) — Atualização parcial JSON Merge Patch (RFC 7396): reset de campo nulo, rejeição de campo imutável, ETag/If-Match, mutação apenas pelo proprietário, 42 testes / 141 assertivas PASS.

Este guia mostra como implementar um endpoint `PATCH` seguindo a semântica JSON Merge Patch: apenas campos fornecidos são atualizados, `null` redefine para o padrão e campos imutáveis são rejeitados.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST`  | `/documents` | Criar (requer `X-User-Id`) |
| `GET`   | `/documents` | Listar |
| `GET`   | `/documents/{id}` | Obter com header ETag |
| `PATCH` | `/documents/{id}` | Atualização parcial (requer `X-User-Id`) |
| `DELETE`| `/documents/{id}` | Excluir (apenas proprietário) |

## Criar

```php
POST /documents  X-User-Id: 1
{"title": "Meu Doc", "body": "Conteúdo"}
→ 201  {"id": 1, "owner_id": 1, "title": "Meu Doc", "status": "draft", "version": 1}

// Sem X-User-Id → 401
// title ausente → 422
// title vazio   → 422
// body é opcional → padrão para ""
```

## GET com ETag

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "Meu Doc", "version": 1, ...}
```

Formato do ETag: `"doc-{id}-{version}"`.

## PATCH — Semântica JSON Merge Patch

```php
// Atualizar apenas title — body inalterado
PATCH /documents/1  X-User-Id: 1
{"title": "Atualizado"}
→ 200  {"title": "Atualizado", "body": "Conteúdo", ...}

// Atualizar apenas body
PATCH /documents/1  X-User-Id: 1
{"body": "Novo conteúdo"}
→ 200  {"title": "Atualizado", "body": "Novo conteúdo", ...}

// {} vazio — sem operação (válido per RFC 7396 §3)
PATCH /documents/1  X-User-Id: 1
{}
→ 200  (documento inalterado)

// null redefine campo para o padrão
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // redefinido para o padrão
```

## Campos Imutáveis — Rejeitados

Alguns campos nunca devem ser alterados via PATCH:

```php
PATCH /documents/1  {"id": 999}         → 422  // imutável
PATCH /documents/1  {"owner_id": 99}    → 422  // imutável
PATCH /documents/1  {"version": 999}    → 422  // imutável
PATCH /documents/1  {"created_at": "…"} → 422  // imutável
```

## Autorização Apenas para Proprietário

```php
// Usuário 2 tenta fazer patch no documento do usuário 1 → 404 (não 403, para prevenir enumeração)
PATCH /documents/1  X-User-Id: 2  {"title": "Roubado"}  → 404

// Proprietário sempre pode fazer patch no próprio
PATCH /documents/1  X-User-Id: 1  {"title": "Meu"}      → 200
```

## ETag / If-Match

```php
// PATCH condicional — 412 se a versão mudou
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Atualizado"}
→ 200  // se a versão ainda for 1

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Desatualizado"}
→ 412  // se a versão agora for 2
```

## Validação de Tipo

```php
PATCH /documents/1  {"title": 123}   → 422  // int em vez de string
PATCH /documents/1  {"body": [1,2]}  → 422  // array em vez de string
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Tratar campo ausente igual a `null` | O chamador não pode limpar um campo; `undefined` ≠ `null` no Merge Patch |
| Permitir patch em `owner_id` | Transferência de propriedade via API sem fluxo de autorização |
| Retornar 403 para acesso entre proprietários | Revela existência do documento; retornar 404 em vez disso |
| Substituir documento inteiro no PATCH | Sobrescreve campos que o cliente não pretendia alterar |
| Aceitar campos imutáveis silenciosamente (sem operação) | O cliente acredita que alterou `id`; falha silenciosa causa confusão |
