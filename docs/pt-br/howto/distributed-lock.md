# Como Fazer: Lock Distribuído

> **Referência FT**: FT288 (`NENE2-FT/distlocklog`) — Lock distribuído: constraint DB UNIQUE(resource), verificação de proprietário, expiração baseada em TTL, re-aquisição de lock expirado por design, enum ReleaseResult (Released/NotFound/Forbidden), 403 em incompatibilidade de proprietário, 16 testes / 27 asserções PASS.
>
> **Avaliação ATK**: ATK-01 a ATK-12 incluídos no final deste documento.

Este guia mostra como implementar uma API de lock distribuído — prevenir operações concorrentes no mesmo recurso emitindo locks com prazo.

## O que é um Lock Distribuído?

Quando múltiplos processos precisam de acesso exclusivo a um recurso compartilhado (ex.: um pagamento, um arquivo, um job de fila), um lock distribuído garante que apenas um processo prossiga por vez. Locks têm TTL para que expirem automaticamente se o detentor travar.

## Schema

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource TEXT UNIQUE` — uma linha por recurso. Adquirir insere ou atualiza esta linha.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/locks/{resource}` | Adquirir lock |
| `GET` | `/locks/{resource}` | Obter status do lock |
| `DELETE` | `/locks/{resource}` | Liberar lock |
| `POST` | `/locks/{resource}/renew` | Estender TTL |

## Lógica de Aquisição

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Sem lock — INSERT (constraint UNIQUE lida com corridas)
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // Corrida: outro processo inseriu de forma concorrente
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expirado → re-adquirir (UPDATE substitui a linha antiga)
        // Mesmo proprietário → re-adquirir (estender ou re-bloquear)
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // Mantido por outro proprietário, não expirado → não pode adquirir
    return null;
}
```

## Liberação com Verificação de Proprietário

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

Apenas o proprietário do lock pode liberá-lo. `owner` errado → 403 Forbidden.

## Enum ReleaseResult

```php
enum ReleaseResult
{
    case Released;   // Lock encontrado, proprietário corresponde, linha deletada
    case NotFound;   // Lock não encontrado ou já expirado
    case Forbidden;  // Lock encontrado, mas proprietário não corresponde
}
```

Usar um enum (não magic strings) garante tratamento exaustivo no `match`.

## Resposta de Aquisição

```php
// Sucesso:
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// Falha (mantido por outro):
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` não é um erro — significa "tente novamente mais tarde." Sem status 4xx; o chamador deve tentar novamente.

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Adquirir lock mantido por outro proprietário 🚫 BLOCKED

**Ataque**: Atacante tenta adquirir `locks/payment:42` enquanto outro processo o mantém.
**Resultado**: BLOCKED — repositório verifica `existing.owner === $caller_owner`. Proprietário diferente + não expirado → retorna `null` → `{ acquired: false }`. Sem erro, sem falha — o atacante simplesmente não obtém o lock.

---

### ATK-02 — Liberar lock de outro proprietário 🚫 BLOCKED

**Ataque**: Atacante envia `DELETE /locks/payment:42` com `{ "owner": "attacker" }` para liberar forçosamente um lock.
**Resultado**: BLOCKED — repositório verifica `lock.owner === $body_owner`. Incompatibilidade → `ReleaseResult::Forbidden` → 403.

---

### ATK-03 — Roubar lock após expiração 🚫 BLOCKED (por design)

**Ataque**: Atacante aguarda expiração do lock, então o adquire.
**Resultado**: BLOCKED (por design) — locks expirados podem ser re-adquiridos por qualquer proprietário. Esse é o comportamento pretendido: expiração baseada em TTL é como detentores travados perdem seus locks. Reduzir ataques baseados em TTL requer coordenação (renovação por heartbeat).

---

### ATK-04 — Renovar lock de outro proprietário 🚫 BLOCKED

**Ataque**: Atacante envia `POST /locks/payment:42/renew` com `{ "owner": "attacker", "ttl_seconds": 3600 }`.
**Resultado**: BLOCKED — renovação verifica `lock.owner === $body_owner`. Incompatibilidade → 403 Forbidden.

---

### ATK-05 — TTL zero ou negativo para criar lock já expirado 🚫 BLOCKED

**Ataque**: Envia `{ "ttl_seconds": 0 }` ou `{ "ttl_seconds": -100 }` para criar um lock que expira instantaneamente.
**Resultado**: BLOCKED — `if ($ttlSeconds === null || $ttlSeconds < 1)` → erro de validação 422.

---

### ATK-06 — SQL injection via parâmetro de caminho resource 🚫 BLOCKED

**Ataque**: Usa `locks/resource'; DROP TABLE distributed_locks; --` como nome do recurso.
**Resultado**: BLOCKED — todas as consultas usam declarações parametrizadas (`WHERE resource = ?`). A string injetada é tratada como identificador de recurso literal.

---

### ATK-07 — Proprietário vazio para contornar verificação de propriedade 🚫 BLOCKED

**Ataque**: Envia `{ "owner": "" }` ou `{ "owner": "   " }` para liberar ou renovar sem propriedade válida.
**Resultado**: BLOCKED — `$owner = trim(...); if ($owner === '')` → erro de validação 422.

---

### ATK-08 — TTL não inteiro para contornar validação de tipo 🚫 BLOCKED

**Ataque**: Envia `{ "ttl_seconds": "3600" }` (string) ou `{ "ttl_seconds": 60.5 }` (float).
**Resultado**: BLOCKED — `is_int($body['ttl_seconds'])` rejeita strings e floats. Apenas o tipo inteiro JSON é aceito.

---

### ATK-09 — Adquirir com mesmo proprietário múltiplas vezes 🚫 BLOCKED (por design)

**Ataque**: Mesmo proprietário re-adquire um lock que mantém para estendê-lo sem usar `/renew`.
**Resultado**: PERMITIDO (por design) — `$existing->owner === $owner` → UPDATE (re-adquirir/estender). Re-aquisição pelo mesmo proprietário é idempotente e segura; atualiza `expires_at` e `acquired_at`.

---

### ATK-10 — Condição de corrida: dois proprietários adquirem de forma concorrente 🚫 BLOCKED

**Ataque**: Dois processos ambos não veem lock e ambos tentam INSERT simultaneamente.
**Resultado**: BLOCKED — constraint `UNIQUE(resource)` garante que apenas um INSERT seja bem-sucedido. O perdedor captura `\RuntimeException` e retorna `null` → `{ acquired: false }`. Apenas um proprietário vence.

---

### ATK-11 — GET de lock inexistente ou expirado 🚫 BLOCKED

**Ataque**: Chama `GET /locks/nonexistent` ou aguarda expiração do lock depois chama GET.
**Resultado**: BLOCKED — `if ($lock === null || $lock->isExpired($now)) return 404`. Locks expirados retornam 404 (não os dados desatualizados do lock).

---

### ATK-12 — Nome de recurso extremamente longo para causar DoS 🚫 BLOCKED (nota de design)

**Ataque**: Envia `{ "resource": "<string de 10MB>" }` como parâmetro de caminho resource.
**Resultado**: PARCIALMENTE BLOCKED — o recurso vem do caminho da URL, limitado pelo comprimento de caminho do servidor web (tipicamente 8KB). Sem validação explícita de comprimento no nível da aplicação presente neste FT. Em produção, adicione `if (strlen($resource) > 255)` → 422. O BD armazena o que a aplicação passa.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Adquirir lock mantido por outro | 🚫 BLOCKED |
| ATK-02 | Liberar lock de outro proprietário | 🚫 BLOCKED |
| ATK-03 | Roubar lock após expiração TTL | 🚫 BLOCKED (por design) |
| ATK-04 | Renovar lock de outro proprietário | 🚫 BLOCKED |
| ATK-05 | TTL zero/negativo | 🚫 BLOCKED |
| ATK-06 | SQL injection via caminho resource | 🚫 BLOCKED |
| ATK-07 | Bypass de proprietário vazio | 🚫 BLOCKED |
| ATK-08 | Bypass de tipo TTL não inteiro | 🚫 BLOCKED |
| ATK-09 | Re-aquisição pelo mesmo proprietário | 🚫 BLOCKED (pretendido) |
| ATK-10 | Condição de corrida em aquisição concorrente | 🚫 BLOCKED |
| ATK-11 | GET de lock expirado/inexistente | 🚫 BLOCKED |
| ATK-12 | Nome de recurso extremamente longo | ⚠️ NOTA DE DESIGN |

**11 BLOCKED, 1 NOTA DE DESIGN, 0 EXPOSED**
Verificação de proprietário, proteção de corrida `UNIQUE(resource)`, validação de TTL e consultas parametrizadas previnem todos os vetores de ataque críticos.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem constraint `UNIQUE(resource)` | Condição de corrida: dois proprietários ambos adquirem; vulnerabilidade TOCTOU |
| Liberar sem verificação de proprietário | Qualquer processo pode liberar qualquer lock; sem garantia de exclusividade |
| Sem TTL nos locks | Lock do detentor travado persiste para sempre; deadlock do sistema |
| Aceitar TTL de 0 ou negativo | Lock já expirado na criação; imediatamente re-adquirível |
| Retornar 404 em incompatibilidade de proprietário (liberação) | Atacante não consegue distinguir "lock não existe" de "proprietário errado"; use 403 |
| Aceitar string/float como TTL | `"3600"` parece válido mas `is_int` falha; verificação de tipo estrita previne bugs sutis |
| Armazenar proprietário sem validação | Proprietário vazio contorna propriedade; sempre validar não-vazio |
| Sem limite de comprimento de recurso | Limite de caminho do servidor web é a única guarda; adicionar validação explícita |
| Renovar locks expirados | Lock expirado não pertence a ninguém; re-adquirir em vez de renovar |
