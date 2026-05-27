# Como Fazer: Bloqueio Otimista com PATCH + Campo de Versão

> **Referência FT**: FT324 (`NENE2-FT/optlocklog`) — Bloqueio otimista baseado em PATCH, 409 inclui `current_version` para retry sem GET, tipo de versão inteiro estrito, avaliação ATK, 12 testes / 24 asserções PASS.

Este guia mostra como implementar controle de concorrência otimista via PATCH com um campo `version`, retornando a versão atual do servidor nas respostas 409 para que os clientes possam tentar novamente sem um GET extra.

## Schema

```sql
CREATE TABLE articles (
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
| `POST`  | `/articles` | Criar (versão=1) |
| `GET`   | `/articles/{id}` | Obter com versão |
| `PATCH` | `/articles/{id}` | Atualizar (versão obrigatória como inteiro) |

## Criação e Leitura

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## PATCH com Versão

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

A versão deve ser um **inteiro JSON** — uma string `"1"` é rejeitada.

## 409 Inclui current_version

Quando um conflito é detectado, a resposta inclui `current_version` para que o cliente possa tentar novamente sem um GET:

```php
// Versão 1 já incrementada para 2 por outro escritor
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← cliente pode usar diretamente para retry
}

// Cliente tenta novamente com current_version do corpo 409
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← sucesso
```

## Validação de Tipo

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // versão ausente
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // string não int
PATCH /articles/9999 {"version": 1}                      → 404  // não encontrado
```

## Implementação

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // Verificação de tipo inteiro estrito — "1" (string) é rejeitado
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← habilitar retry sem GET
        ]);
    }

    // UPDATE atômico com WHERE version = ?
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Força Bruta de Versão para Sobrescrever ✅ SAFE

**Ataque**: Atacante itera versão `1, 2, 3…` até uma ter sucesso, sobrescrevendo o conteúdo atual.
**Resultado**: SAFE — Força bruta eventualmente encontra a versão atual mas esta é uma escrita legítima, não escalação de privilégio. Autorização de propriedade (não mostrada) previne escritas não autorizadas.

---

### ATK-02 — Bypass de Versão String (`"version": "1"`) 🚫 BLOCKED

**Ataque**: Atacante envia `"version": "1"` (string JSON) esperando que coerção de tipo PHP a trate como inteiro.
**Resultado**: BLOCKED — `is_int($version)` retorna false para strings. Retorna 400.

---

### ATK-03 — Versão Float (`"version": 1.0`) 🚫 BLOCKED

**Ataque**: Enviar `"version": 1.0` para corresponder via comparação solta.
**Resultado**: BLOCKED — `is_int(1.0)` é false no PHP (é um float). Retorna 400.

---

### ATK-04 — Versão Ausente → Forçar Escrita Cega 🚫 BLOCKED

**Ataque**: Omitir campo `version`, esperando que o servidor aceite a atualização por padrão.
**Resultado**: BLOCKED — `version` ausente (null) falha na verificação `is_int()`. Retorna 400.

---

### ATK-05 — Versão Negativa 🚫 BLOCKED

**Ataque**: Enviar `"version": -1` para explorar potencial off-by-one na comparação de versão.
**Resultado**: BLOCKED — Versão começa em 1 e apenas incrementa. `-1 !== 1` → conflito 409.

---

### ATK-06 — current_version do 409 Usado para Corrida 🚫 BLOCKED

**Ataque**: Atacante lê `current_version` do 409 e envia imediatamente, correndo com o retry legítimo.
**Resultado**: BLOCKED — O UPDATE atômico `WHERE version = $current` significa que apenas um escritor concorrente pode ter sucesso por versão. O outro obtém 409 novamente. Este é o comportamento pretendido de bloqueio otimista.

---

### ATK-07 — Número de Versão com Overflow 🚫 BLOCKED

**Ataque**: Enviar `"version": 9999999999999999999` para overflow de int.
**Resultado**: BLOCKED — Inteiros JSON grandes podem decodificar como float no PHP; `is_int()` retorna false. Retorna 400.

---

### ATK-08 — Versão Zero 🚫 BLOCKED

**Ataque**: Enviar `"version": 0` para subcortar a versão mínima.
**Resultado**: BLOCKED — Versão começa em 1. `0 !== 1` → conflito 409.

---

### ATK-09 — current_version Falsificado no Corpo da Requisição 🚫 BLOCKED

**Ataque**: Atacante inclui `"current_version": 999` no corpo do PATCH esperando que o servidor use.
**Resultado**: BLOCKED — `current_version` está apenas na *resposta*. O servidor ignora campos desconhecidos da requisição; versão é obtida apenas de `$body['version']`.

---

### ATK-10 — SQL Injection via Campo version 🚫 BLOCKED

**Ataque**: `"version": "1; DROP TABLE articles; --"`.
**Resultado**: BLOCKED — Rejeitado na verificação `is_int()` antes de chegar ao banco de dados. Retorna 400.

---

### ATK-11 — Replay de Versão com Sucesso para Re-executar 🚫 BLOCKED

**Ataque**: Registrar um PATCH bem-sucedido (versão N → N+1), depois fazer replay da mesma requisição.
**Resultado**: BLOCKED — Após atualização, artigo está na versão N+1. Fazer replay `version: N` retorna 409.

---

### ATK-12 — Escritas Concorrentes Causam Ambas a Ter Sucesso 🚫 BLOCKED

**Ataque**: Duas requisições PATCH idênticas enviadas simultaneamente com a mesma `version`.
**Resultado**: BLOCKED — `UPDATE … WHERE version = ?` é atômico. Banco de dados serializa escritas concorrentes; segundo UPDATE corresponde a 0 linhas → aplicação detecta e retorna 409.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Força bruta de versão | ✅ SAFE (preocupação de autorização) |
| ATK-02 | Bypass de versão string | 🚫 BLOCKED |
| ATK-03 | Versão float | 🚫 BLOCKED |
| ATK-04 | Escrita cega sem versão | 🚫 BLOCKED |
| ATK-05 | Versão negativa | 🚫 BLOCKED |
| ATK-06 | Exploração de corrida com current_version | 🚫 BLOCKED |
| ATK-07 | Versão com overflow | 🚫 BLOCKED |
| ATK-08 | Versão zero | 🚫 BLOCKED |
| ATK-09 | current_version falsificado no corpo | 🚫 BLOCKED |
| ATK-10 | SQL injection via versão | 🚫 BLOCKED |
| ATK-11 | Replay de versão com sucesso | 🚫 BLOCKED |
| ATK-12 | Escritas concorrentes ambas com sucesso | 🚫 BLOCKED |

**11 BLOCKED, 1 SAFE, 0 EXPOSED** — Nenhuma descoberta crítica.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar `"version": "1"` (string) | Comparação solta PHP `"1" == 1` é true; ataque de confusão de tipo |
| Omitir `current_version` do 409 | Cliente deve fazer GET extra; maior latência, mais requisições em conflito |
| Usar verificação somente no nível da aplicação (sem cláusula WHERE) | Condição de corrida entre leitura e escrita da versão |
| Retornar 200 em versão ausente | Sobrescrita incondicional — perda de atualização |
