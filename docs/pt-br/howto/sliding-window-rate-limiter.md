# Como Fazer: Rate Limiter de Janela Deslizante

## Visão Geral

Este guia cobre a construção de um rate limiter de janela deslizante por usuário e por endpoint com o NENE2. As requisições são contadas dentro de uma janela de tempo deslizante; uma vez que o limite é atingido, requisições adicionais são rejeitadas com `429 Too Many Requests`.

**Implementação de referência**: `../NENE2-FT/ratelog/`

---

## Design do Schema

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

O índice em `(user_id, endpoint, created_at)` torna a query COUNT rápida em escala.

---

## Tabela de Rotas

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/rate/check` | Usuário | Registrar uma requisição; retorna 429 se acima do limite |
| `GET` | `/rate/status` | Usuário | Uso atual para um usuário/endpoint |
| `DELETE` | `/rate/reset/{userId}` | Admin | Redefinir contadores para um usuário |

---

## Algoritmo Central

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60s
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**Janela deslizante**: cada `check()` olha exatamente `WINDOW_SECONDS` a partir do momento atual, então eventos antigos naturalmente caem fora do escopo.

---

## Reset Admin com Padrão Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // fail-closed: chave não configurada bloqueia todo acesso admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Resetar todos os contadores de um usuário (todos os endpoints):
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

Resetar para um endpoint específico:
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## Extração de Parâmetro de Caminho (sem Router::param())

Quando `Router::param()` não está disponível na versão instalada, use o atributo diretamente:

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## Validação

- `endpoint`: string não vazia, máximo 128 chars
- `X-User-Id`: `ctype_digit()` + inteiro positivo
- `userId` no caminho: `ctype_digit()` + inteiro positivo (falha → 404)
- Chave admin: comparação com `hash_equals()` (falha → 403)

---

## Códigos de Status HTTP

| Situação | Status |
|----------|--------|
| Requisição permitida | 200 |
| Status recuperado | 200 |
| Contador redefinido | 200 |
| Sem X-User-Id | 400 |
| Sem corpo | 400 |
| Endpoint vazio / ausente | 422 |
| Endpoint muito longo | 422 |
| Sem chave admin | 403 |
| Chave admin errada | 403 |
| userId inválido no caminho | 404 |
| Limite de taxa excedido | 429 |

---

## Padrões de Ataque ATK Cobertos

| ATK | Padrão | Defesa |
|-----|--------|--------|
| ATK-01 | X-User-Id ausente | 400 com mensagem |
| ATK-02 | String de endpoint vazia | Validação 422 |
| ATK-03 | Endpoint com 129 chars (DoS) | Limite de comprimento 422 |
| ATK-04 | Injeção SQL no endpoint | Queries parametrizadas |
| ATK-05 | Tentativa de reset sem admin | 403 fail-closed |
| ATK-06 | Chave admin errada | 403 hash_equals() |
| ATK-07 | userId negativo no caminho | 404 |
| ATK-08 | userId zero | 404 |
| ATK-09 | userId não-dígito (`abc`) | 404 ctype_digit |
| ATK-10 | Status sem parâmetro endpoint | 422 |
| ATK-11 | Check sem corpo | 400 |
| ATK-12 | Corpo sem chave endpoint | 422 |

---

## Notas

- **Concorrência**: A janela deslizante tem uma pequena janela TOCTOU. Para uso em produção de alta concorrência, considere contadores atômicos (Redis INCR + EXPIRE) ou bloqueio no nível do banco de dados.
- **Desvio de relógio**: Todos os timestamps devem usar UTC para evitar surpresas de DST ou fuso horário.
- **Crescimento do armazenamento**: Eventos antigos acumulam. Adicione um job periódico de limpeza: `DELETE FROM rate_events WHERE created_at < :cutoff`.
