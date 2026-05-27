# Como Fazer: API de Soft Delete, Lixeira e Restauração

> **Referência FT**: FT340 (`NENE2-FT/softlog`) — API de notas com soft delete (deleted_at), visão de lixeira, restauração, hard delete permanente, purga em massa, ordenação com fixados primeiro e avaliação de ataques cracker-mindset ATK, 26 testes / 60+ assertivas PASS.

Este guia mostra como implementar um ciclo de vida de exclusão em dois estágios: itens são primeiro soft-deleted (movidos para lixeira) e podem ser restaurados, depois apagados permanentemente via hard delete explícito ou purga em massa.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = ativo; ISO 8601 quando soft-deleted
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = ativo; `deleted_at IS NOT NULL` = soft-deleted (na lixeira).

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/notes` | Criar nota |
| `GET`  | `/notes` | Listar notas ativas (fixadas primeiro) |
| `GET`  | `/notes/{id}` | Obter nota ativa |
| `PUT`  | `/notes/{id}` | Atualizar nota ativa |
| `DELETE` | `/notes/{id}` | Soft delete (→ lixeira) |
| `GET`  | `/notes/trash` | Listar notas na lixeira |
| `POST` | `/notes/{id}/restore` | Restaurar da lixeira |
| `DELETE` | `/notes/{id}/permanent` | Hard delete (permanente) |
| `POST` | `/notes/trash/purge` | Purgar toda a lixeira |

## Criar Nota

```php
POST /notes
{"title": "Minha Nota", "body": "Conteúdo", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "Minha Nota",
  "body": "Conteúdo",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "Sem título"}  → 422  // title obrigatório
```

## Listar Notas Ativas (Fixadas Primeiro)

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Fixada", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

Notas soft-deleted nunca são retornadas na lista ativa.

## Obter Nota

```php
GET /notes/1
→ 200  {"id": 1, "title": "Minha Nota", ...}

// Soft-deleted ou desconhecido → mesmo 404
GET /notes/9999    → 404
GET /notes/1 (após DELETE /notes/1)  → 404
```

## Atualizar Nota

```php
PUT /notes/1
{"title": "Atualizado", "body": "Novo corpo", "is_pinned": true}
→ 200  {"title": "Atualizado", "is_pinned": true, ...}

// Nota soft-deleted não é atualizável
PUT /notes/1  (após DELETE /notes/1)  → 404
```

## Soft Delete

```php
DELETE /notes/1
→ 204  (sem corpo)

// Nota desaparece de GET /notes e GET /notes/1
// Mas aparece em GET /notes/trash

DELETE /notes/9999  → 404  // não encontrado
```

## Visão da Lixeira

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Desaparecida", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// Notas ativas NÃO estão na lixeira
```

`deleted_at` é não-null para todos os itens da lixeira.

## Restaurar

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restaure-me", "deleted_at": null, ...}

// Nota restaurada reaparece em GET /notes
// POST /notes/9999/restore  → 404
```

## Hard Delete (Permanente)

```php
DELETE /notes/1/permanent
→ 204  (sem corpo; nota desapareceu do banco)

// Sumiu da lixeira também
// DELETE /notes/9999/permanent  → 404
```

## Purgar Lixeira

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// Lixeira vazia
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` executa `DELETE FROM notes WHERE deleted_at IS NOT NULL` e retorna a contagem de linhas.

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Hard Delete Sem Soft Delete Prévio 🚫 BLOCKED

**Ataque**: Atacante chama `DELETE /notes/1/permanent` em uma nota ativa (ainda não soft-deleted).
**Resultado**: BLOCKED — `DELETE /notes/{id}/permanent` verifica `deleted_at IS NOT NULL` antes de prosseguir. Notas ativas retornam 404 para o endpoint de exclusão permanente; apenas itens na lixeira podem ser hard-deleted.

---

### ATK-02 — Acessar Nota Soft-Deleted via GET Direto ✅ SAFE

**Ataque**: Atacante sabe que a nota ID 5 foi soft-deleted e chama `GET /notes/5` esperando ler conteúdo protegido.
**Resultado**: SAFE — `GET /notes/{id}` faz query com `WHERE id = ? AND deleted_at IS NULL`. Notas soft-deleted retornam 404 identicamente a notas desconhecidas — sem dica de existência.

---

### ATK-03 — Purgar Lixeira Sem Auth (Destruição em Massa) ⚠️ EXPOSED

**Ataque**: Qualquer cliente chama `POST /notes/trash/purge` para destruir permanentemente todas as notas na lixeira de todos os usuários.
**Resultado**: EXPOSED — Não há verificação de autenticação em `POST /notes/trash/purge`. Sem escopo por usuário, um cliente não autenticado pode deletar irreversivelmente todos os dados da lixeira de todos os usuários. Mitigação: exigir autenticação; escopar purga à própria lixeira do usuário autenticado; exigir papel de admin para purga global.

---

### ATK-04 — Double Soft Delete para Corromper deleted_at ✅ SAFE

**Ataque**: Atacante envia `DELETE /notes/1` duas vezes, esperando que a segunda chamada redefina `deleted_at` para um timestamp posterior.
**Resultado**: SAFE — O primeiro delete define `deleted_at`. O segundo delete encontra `deleted_at IS NULL = false`, então a busca retorna 0 linhas → 404. O timestamp não é modificado.

---

### ATK-05 — Restaurar Nota Ativa (Corromper Estado) 🚫 BLOCKED

**Ataque**: Atacante chama `POST /notes/1/restore` em uma nota ativa (não-deletada) para forçar `deleted_at = null` incondicionalmente.
**Resultado**: BLOCKED — `restore` faz query com `WHERE id = ? AND deleted_at IS NOT NULL`. Notas ativas não correspondem → 404. Idempotente: restaurar uma nota já ativa é um 404 sem efeito.

---

### ATK-06 — Injeção SQL via Título na Criação ✅ SAFE

**Ataque**: Atacante submete `{"title": "'; DROP TABLE notes; --"}` para corromper o banco de dados.
**Resultado**: SAFE — Todas as escritas usam statements parametrizados. O título é armazenado como string literal.

---

### ATK-07 — Overflow de ID de Nota para Pular Validação 🚫 BLOCKED

**Ataque**: Atacante envia `GET /notes/99999999999999999999` (20 dígitos) para fazer overflow do inteiro PHP e alcançar IDs não pretendidos.
**Resultado**: BLOCKED — IDs de nota são validados com `ctype_digit` + `strlen <= 18` antes da conversão. Valores de overflow → 422.

---

### ATK-08 — Atualizar Nota Deletada (Escrita em Fantasma) 🚫 BLOCKED

**Ataque**: Atacante tem uma referência de sessão desatualizada para uma nota deletada e submete PUT para modificá-la.
**Resultado**: BLOCKED — `PUT /notes/{id}` faz query com `WHERE id = ? AND deleted_at IS NULL`. Notas soft-deleted falham nesta verificação → 404. A atualização é rejeitada.

---

### ATK-09 — Corrida: Restaurar e Então Purgar Imediatamente 🚫 BLOCKED

**Ataque**: Atacante corre `POST /notes/1/restore` e `POST /notes/trash/purge` para destruir uma nota no meio da restauração.
**Resultado**: BLOCKED — Cada operação é uma transação de banco atômica única. A purga emite `DELETE WHERE deleted_at IS NOT NULL`; a restauração define `deleted_at = NULL`. Uma vence e a nota termina em um estado consistente.

---

### ATK-10 — Soft Delete Concorrente Deixa Órfão ✅ SAFE

**Ataque**: Duas requisições simultaneamente chamam `DELETE /notes/1`. Ambas verificam `deleted_at IS NULL`, ambas veem null, e ambas tentam definir `deleted_at`.
**Resultado**: SAFE — A primeira atualização tem sucesso. A segunda encontra `deleted_at IS NOT NULL` (ou 0 linhas atualizadas) → 404. SQLite serializa escritas; a segunda chamada é idempotente no nível do banco.

---

### ATK-11 — Título Muito Longo (Abuso de Armazenamento) ⚠️ EXPOSED

**Ataque**: Atacante submete uma string de título de 10 MB para esgotar o armazenamento do banco.
**Resultado**: EXPOSED — Nenhum comprimento máximo é aplicado em `title` ou `body`. Mitigação: adicionar `MAX_TITLE_LENGTH` (ex.: 500 chars) e `MAX_BODY_LENGTH` (ex.: 100.000 chars), retornando 422 se excedido. Middleware de tamanho de requisição fornece uma guarda secundária.

---

### ATK-12 — Flood de Fixados (Inundar Notas Fixadas) ⚠️ EXPOSED

**Ataque**: Atacante cria milhares de notas fixadas para empurrar todas as notas reais para fora do topo da lista ativa.
**Resultado**: EXPOSED — Sem limite na contagem de notas fixadas. Qualquer nota pode ser criada com `is_pinned: true`. Mitigação: limitar o número máximo de notas fixadas por usuário (ex.: 10); retornar 422 se excedido.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Hard delete sem soft delete | 🚫 BLOCKED |
| ATK-02 | Acessar soft-deleted via GET | ✅ SAFE |
| ATK-03 | Purgar lixeira sem auth | ⚠️ EXPOSED |
| ATK-04 | Double soft delete | ✅ SAFE |
| ATK-05 | Restaurar nota ativa | 🚫 BLOCKED |
| ATK-06 | Injeção SQL via título | ✅ SAFE |
| ATK-07 | Overflow de ID de nota | 🚫 BLOCKED |
| ATK-08 | Atualizar nota soft-deleted | 🚫 BLOCKED |
| ATK-09 | Corrida: restaurar + purgar | 🚫 BLOCKED |
| ATK-10 | Soft delete concorrente | ✅ SAFE |
| ATK-11 | Título muito longo | ⚠️ EXPOSED |
| ATK-12 | Flood de fixados | ⚠️ EXPOSED |

**7 BLOCKED, 2 SAFE, 3 EXPOSED** — Crítico: autenticar purga e escopar aos dados do próprio ator; adicionar limites de comprimento de título/corpo; limitar contagem de notas fixadas por usuário.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Hard-delete no primeiro DELETE | Sem caminho de recuperação; exclusão acidental é permanente |
| Sem filtro `deleted_at IS NULL` nas queries de lista/get | Itens soft-deleted reaparecem como se ainda estivessem ativos |
| Permitir `PUT` em notas soft-deleted | Escritas fantasma — usuários editando dados que pensavam estar deletados |
| Sem auth em `POST /trash/purge` | Qualquer cliente destrói irreversivelmente todos os dados da lixeira |
| Retornar 403 para GET de nota soft-deleted | Revela que a nota existe; 404 previne enumeração de existência |
| Sem verificação de contagem de linhas após soft-delete | 200 silencioso quando nota não encontrada; sempre verifique linhas afetadas |
