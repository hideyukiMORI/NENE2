# Como Fazer: Soft Delete, Restaurar e Exclusão Permanente

> **Referência FT**: `NENE2-FT/softdelete` — Soft delete via timestamp `deleted_at`, restauração (apenas notas soft-deleted podem ser restauradas), exclusão permanente/hard delete (apenas notas soft-deleted podem ser permanentemente excluídas), 14 testes PASS.

Este guia mostra como implementar três estados de exclusão: ativo, soft-deleted (recuperável) e permanentemente excluído (desaparecido). Compare com `docs/howto/soft-delete-trash-restore.md` (FT340 softdeletelog) que adiciona uma visão dedicada de lixeira e purga em massa.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = ativo; timestamp = soft-deleted
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → ativo. `deleted_at IS NOT NULL` → soft-deleted.

## Endpoints

| Método   | Caminho                     | Descrição                            |
|----------|-----------------------------|--------------------------------------|
| `POST`   | `/notes`                    | Criar nota                           |
| `GET`    | `/notes`                    | Listar apenas notas ativas           |
| `GET`    | `/notes/{id}`               | Obter nota (404 se excluída)         |
| `DELETE` | `/notes/{id}`               | Soft delete (define deleted_at)      |
| `POST`   | `/notes/{id}/restore`       | Restaurar nota soft-deleted          |
| `DELETE` | `/notes/{id}/permanent`     | Excluir permanentemente nota soft-deleted |

## Criar Nota

```php
POST /notes  {"title": "Minha Nota", "body": "Algum conteúdo"}

→ 201
{
  "id": 1,
  "title": "Minha Nota",
  "body": "Algum conteúdo",
  "deleted_at": null,    // ← null = ativo
  "created_at": "..."
}
```

## Listar Notas Ativas

```php
GET /notes
→ 200  {"items": [{...notas ativas...}], "total": 2}
```

Retorna apenas notas com `deleted_at IS NULL`. Notas soft-deleted são invisíveis aqui.

## Soft Delete

```php
DELETE /notes/1
→ 200  // define deleted_at = now

// Nota soft-deleted desaparece da lista ativa
GET /notes
→ 200  {"items": [], "total": 0}

// E do GET direto
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## Restaurar

```php
// Restaurar uma nota soft-deleted
POST /notes/1/restore
→ 200  {"id": 1, "title": "Minha Nota", "deleted_at": null, ...}  // de volta ao ativo

// Nota restaurada aparece novamente na lista ativa
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### Restaurar Nota Ativa → 404

```php
// Tentar restaurar uma nota ativa (não soft-deleted) → 404
POST /notes/2/restore   // nota 2 nunca foi excluída
→ 404
```

Apenas notas soft-deleted podem ser restauradas. Notas ativas retornam 404 na restauração.

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- Se 0 linhas afetadas → nota está ativa ou não existe → 404
```

## Exclusão Permanente

```php
// Deve ser soft-deleted primeiro
DELETE /notes/1   // soft delete
POST /notes/1/restore  // restaurar (opcional)

// Excluir permanentemente uma nota soft-deleted
DELETE /notes/1          // soft delete primeiro
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // desaparecida para sempre
```

### Exclusão Permanente de Nota Ativa → 404

```php
// Excluir permanentemente uma nota ativa → 404
// Deve fazer soft-delete primeiro, depois excluir permanentemente
DELETE /notes/2/permanent   // nota 2 está ativa
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- Se 0 linhas afetadas → nota está ativa ou não existe → 404
```

## Diagrama de Estado

```
Ativo
  │
  │ DELETE /notes/{id}     (soft delete)
  ▼
Soft-deleted
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
Ativo      Desaparecido (hard deleted)
```

**O invariante chave**: exclusão permanente requer um soft delete anterior. Isso previne hard deletes acidentais do estado ativo.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Permitir exclusão permanente de nota ativa | Pula a rede de segurança do soft-delete; dados desaparecem sem janela de recuperação |
| Retornar 200 na restauração de nota ativa | Chamadores não conseguem saber se a restauração era necessária; use 404 para sinalizar "não está na lixeira" |
| Sem índice em `deleted_at` | Varredura completa da tabela para cada query de lista; `WHERE deleted_at IS NULL` é lento sem índice |
| Hard delete imediatamente em `DELETE /notes/{id}` | Nenhuma recuperação possível; use soft delete primeiro |
| Expor `deleted_at` na lista ativa | Clientes veem o campo; polui as respostas visualmente; filtre ou use `null` |
