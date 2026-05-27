# Lock Distribuído

Um lock distribuído impede que processos concorrentes executem uma seção crítica ao mesmo tempo. Locks baseados em banco de dados trocam throughput por simplicidade — não é necessário Redis, e o mesmo DB que armazena seus dados armazena seus locks.

## Conceitos fundamentais

- **Recurso**: o nome da coisa sendo bloqueada (ex.: `job:42`, `report:monthly-2026-05`)
- **Proprietário**: um token que identifica o detentor do lock — apenas o proprietário pode liberar ou renovar
- **Expiração (TTL)**: locks expiram automaticamente para que um proprietário travado não possa manter um lock para sempre
- **Reivindicação de lock obsoleto**: um lock expirado pode ser tomado por um novo proprietário

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

A constraint `UNIQUE` em `resource` garante que exista apenas uma linha por recurso. INSERTs concorrentes são serializados no nível do DB.

## Lógica de aquisição

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Sem lock — INSERT (pode falhar em corrida; chamador recebe null e tenta novamente)
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expirado (obsoleto) ou mesmo proprietário re-adquirindo — UPDATE para reivindicar
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // Mantido por outro proprietário e ainda válido — não pode adquirir
    return null;
}
```

Convenções do valor de retorno:
- Retorna um `LockRecord` em caso de sucesso (`acquired: true` na resposta da API)
- Retorna `null` quando o lock é mantido por outro proprietário (`acquired: false`)

## Liberação com verificação de proprietário

Apenas o proprietário pode liberar. Retornar 403 (não 404) quando o proprietário não corresponde informa ao chamador que o lock existe mas ele não o detém:

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## Renovação de TTL

Tarefas de longa duração precisam estender seu lock antes que ele expire. Apenas o proprietário atual pode renovar — uma renovação por proprietário errado retorna 409 (não 403) porque sinaliza um conflito de estado, não uma negação de permissão:

```php
if ($existing->isExpired($now)) {
    return null; // → 409: não é possível renovar um lock expirado (outro pode agora detê-lo)
}
if ($existing->owner !== $owner) {
    return null; // → 409: proprietário errado
}
// Estender expires_at
```

## Detecção de lock obsoleto

`LockRecord::isExpired()` compara o horário atual com `expires_at`:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

Isso significa que `GET /locks/{resource}` retorna 404 para locks expirados (tratando expirado como inexistente), e `POST /locks/{resource}` permite que um novo proprietário reivindique um lock expirado.

## Decisões de design

**Por que não Redis SETNX?**
Redis fornece SETNX atômico com TTL em um único comando e é o padrão de produção para locking de alto throughput. O locking baseado em DB é mais simples de implantar (sem serviço adicional), consistente com o restante dos dados transacionais e suficiente para cenários de contenção baixa a média (jobs em background, geração de relatórios, processamento em lote).

**Por que não DELETE+INSERT no re-acquire?**
UPDATE preserva o ID da linha e é atômico. DELETE+INSERT criaria uma breve janela onde nenhuma linha de lock existe, permitindo que um processo concorrente faça INSERT e roube o lock.

**Por que separar `acquired_at` de `expires_at`?**
`acquired_at` é o timestamp de quando a propriedade foi estabelecida pela última vez (útil para auditoria). `expires_at` muda na renovação. Mantê-los separados evita ambiguidade.

**Não-bloqueante por design**
O endpoint de lock retorna imediatamente com `acquired: false` em vez de bloquear até que o lock esteja disponível. Os chamadores implementam sua própria estratégia de retry (backoff exponencial, fila de dead letter, etc.) com base em seus requisitos de timeout.
