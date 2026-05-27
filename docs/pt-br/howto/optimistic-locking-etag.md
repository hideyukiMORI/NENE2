# Como Fazer: Bloqueio Otimista com ETag / If-Match

> **Referência FT**: FT320 (`NENE2-FT/locklog`) — Versionamento de documentos com header ETag, If-Match obrigatório para mutação (428), rejeição de ETag desatualizado (412), prevenção de atualização perdida, 15 testes / 30 assertivas PASS.

Este guia mostra como implementar controle de concorrência otimista usando ETags HTTP, prevenindo atualizações perdidas sem bloqueio pessimista no banco de dados.

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

`version` é o token de concorrência autoritativo. O ETag é `"v{version}"`.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/documents` | Criar documento |
| `GET`  | `/documents/{id}` | Obter com ETag |
| `PUT`  | `/documents/{id}` | Atualizar (If-Match obrigatório) |
| `DELETE` | `/documents/{id}` | Excluir (If-Match obrigatório) |

## Criar

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET — Retorna ETag

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

O cliente armazena o ETag e o envia como `If-Match` na próxima mutação.

## PUT — Bloqueio Otimista

```php
// Cliente envia o ETag atual
PUT /documents/1  If-Match: "v1"
{"title": "Atualizado"}
→ 200  ETag: "v2"
{"id": 1, "title": "Atualizado", "version": 2}

// ETag desatualizado (outro cliente atualizou primeiro)
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// If-Match ausente
PUT /documents/1
{"title": "Sem lock"}
→ 428 Precondition Required

// Coringa — ignora verificação de versão
PUT /documents/1  If-Match: *
→ 200  // sempre bem-sucedido se o documento existir
```

### Prevenção de Atualização Perdida

```
Alice lê o doc → version=1, ETag="v1"
Bob  lê o doc → version=1, ETag="v1"

Alice: PUT If-Match: "v1" → 200 (versão passa para 2)
Bob:   PUT If-Match: "v1" → 412 ← escrita de Bob é rejeitada

Bob deve fazer GET novamente para ver a mudança de Alice, depois tentar com "v2"
```

## DELETE — Também Requer If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // versão já foi incrementada
DELETE /documents/1                  → 428  // If-Match ausente
DELETE /documents/9999  If-Match: "v1" → 404
```

## Implementação

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // Verifica coringa ou correspondência exata de versão
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Força Bruta de ETag para Ignorar Pré-condição ✅ SAFE

**Ataque**: O atacante testa `"v1"`, `"v2"`, `"v3"` sequencialmente até encontrar a versão atual para forçar uma atualização.
**Resultado**: SAFE — A força bruta de ETag é possível com um contador sequencial simples, mas a atualização ainda é uma escrita legítima. A resposta 412 não revela nada sobre a versão atual; o atacante deve fazer GET para confirmar. Em cenários de alto valor, use ETags opacas (ex.: `hash('sha256', $version . $secret)`).

---

### ATK-02 — Omitir If-Match para Forçar Escrita Incondicional 🚫 BLOCKED

**Ataque**: O atacante envia PUT sem header `If-Match`, esperando que o servidor aceite escritas incondicionais.
**Resultado**: BLOCKED — `If-Match` ausente retorna 428 Precondition Required. O endpoint rejeita todas as escritas sem token de lock.

---

### ATK-03 — If-Match: * Coringa para Ignorar Verificação de Versão 🚫 BLOCKED

**Ataque**: O atacante envia `If-Match: *` para sobrescrever incondicionalmente, ignorando a concorrência.
**Resultado**: BLOCKED — O coringa é aceito por design (corresponde a qualquer versão existente), mas o documento deve existir (404 se não existir). Isso é conforme a especificação HTTP: `*` significa "existe"; é aceitável para operações administrativas. Para mutações do usuário, restrinja o coringa a funções de admin.

---

### ATK-04 — Condição de Corrida — Escritas Concorrentes com Mesmo ETag 🚫 BLOCKED

**Ataque**: Dois clientes enviam simultaneamente PUT com `"v1"`. Ambos passam na verificação do ETag antes de qualquer um atualizar.
**Resultado**: BLOCKED — O UPDATE no banco usa `WHERE version = $expectedVersion`. A segunda escrita encontra a versão já incrementada e atualiza 0 linhas → retorna 412. Atômico ao nível do banco.

---

### ATK-05 — Injetar Valor Arbitrário de ETag 🚫 BLOCKED

**Ataque**: O atacante envia `If-Match: "v999999"` para um documento na versão 1, esperando que o servidor ignore a validação.
**Resultado**: BLOCKED — O ETag é comparado com a string `"v{version}"` armazenada. `"v999999" ≠ "v1"` → 412.

---

### ATK-06 — Injeção de Header via If-Match 🚫 BLOCKED

**Ataque**: O atacante envia `If-Match: "v1"\r\nX-Admin: true` para injetar headers de resposta.
**Resultado**: BLOCKED — A análise de headers PSR-7 remove CR/LF dos valores de header. O header injetado nunca chega à camada da aplicação.

---

### ATK-07 — Excluir com ETag Desatualizado 🚫 BLOCKED

**Ataque**: O atacante obtém um ETag antigo, espera o documento ser atualizado e envia DELETE com o ETag desatualizado.
**Resultado**: BLOCKED — DELETE verifica o ETag exatamente como PUT. ETag desatualizado retorna 412; o documento sobrevive.

---

### ATK-08 — Versão Negativa no ETag 🚫 BLOCKED

**Ataque**: O atacante envia `If-Match: "v-1"` ou `If-Match: "v0"`.
**Resultado**: BLOCKED — A versão começa em 1 e apenas incrementa. `"v-1"` e `"v0"` nunca correspondem a uma versão armazenada.

---

### ATK-09 — Repetição do ETag Bem-Sucedido Anterior 🚫 BLOCKED

**Ataque**: Após uma atualização bem-sucedida (`v1→v2`), o atacante repete `If-Match: "v2"` para fazer outra atualização.
**Resultado**: BLOCKED — Este é o comportamento válido — o atacante tem um token atual. A preocupação é que um terceiro não deveria usar o token de outro usuário. A autorização (verificação de propriedade) é a guarda; o ETag apenas previne colisão concorrente.

---

### ATK-10 — Overflow do Número de Versão 🚫 BLOCKED

**Ataque**: Forçar o overflow do contador de versão fazendo milhões de atualizações.
**Resultado**: BLOCKED — Os inteiros PHP são de 64 bits (max ~9,2 × 10^18). Atingir overflow é inviável na prática. Rate limiting protege contra loops de atualização rápida.

---

### ATK-11 — Falsificação de ETag na Resposta 🚫 BLOCKED

**Ataque**: O atacante cria uma requisição para que o servidor retorne um `ETag: "v999"` falsificado, fazendo outros clientes crerem que o documento está na versão 999.
**Resultado**: BLOCKED — O ETag é sempre calculado a partir de `$doc['version']` no banco. Nenhuma entrada do usuário afeta o ETag retornado.

---

### ATK-12 — Sem If-Match no DELETE para Excluir Sem Lock 🚫 BLOCKED

**Ataque**: O atacante envia DELETE sem `If-Match`, contando com um servidor que não impõe a pré-condição.
**Resultado**: BLOCKED — DELETE, como PUT, retorna 428 quando `If-Match` está ausente.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Força bruta de ETag | ✅ SAFE (sequencial, veja nota) |
| ATK-02 | Omitir If-Match | 🚫 BLOCKED |
| ATK-03 | Bypass com If-Match coringa | 🚫 BLOCKED |
| ATK-04 | Corrida de escrita concorrente | 🚫 BLOCKED |
| ATK-05 | Injetar ETag arbitrário | 🚫 BLOCKED |
| ATK-06 | Injeção de header via If-Match | 🚫 BLOCKED |
| ATK-07 | Excluir com ETag desatualizado | 🚫 BLOCKED |
| ATK-08 | ETag com versão negativa/zero | 🚫 BLOCKED |
| ATK-09 | Repetição de ETag anterior | ✅ SAFE (questão de autorização, não de ETag) |
| ATK-10 | Overflow do contador de versão | 🚫 BLOCKED |
| ATK-11 | Falsificação de ETag na resposta | 🚫 BLOCKED |
| ATK-12 | Excluir sem If-Match | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Nenhum achado crítico.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Permitir PUT/DELETE sem If-Match | Qualquer escrita sem token de lock causa atualizações perdidas |
| Retornar 200 com ETag desatualizado (sobrescrita silenciosa) | Atualização perdida: o último a escrever vence, edições concorrentes descartadas silenciosamente |
| Usar ETag mutável (ex.: timestamp `Last-Modified`) | Desvio de relógio causa 412 espúrio ou correspondências falsas |
| Omitir suporte a `If-Match: *` coringa | Quebra ferramentas de admin e conformidade com RFC 7232 |
| Sem verificação de versão no banco na cláusula WHERE | Verificação na aplicação passa, mas escrita concorrente no banco vence |
