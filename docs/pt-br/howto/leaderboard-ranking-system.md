# Como Construir um Placar (Sistema de Ranking) com NENE2

Este guia percorre a construção de um placar onde usuários enviam pontuações, veem rankings e verificam seu próprio rank. Apenas a melhor pontuação por usuário por placar é mantida.

**Field Trial**: FT141  
**Versão do NENE2**: ^1.5  
**Tópicos abordados**: padrão UPDATE de melhor pontuação, cálculo de rank com COUNT(*), verificação de propriedade de pontuação, limitação de parâmetro de query, avaliação de vulnerabilidades

---

## O que estamos construindo

- `POST /leaderboards` — criar um placar
- `POST /leaderboards/{id}/scores` — enviar uma pontuação (mantida apenas se for um novo recorde pessoal)
- `GET /leaderboards/{id}/rankings` — top N rankings (pontuação decrescente, `?limit=N`)
- `GET /leaderboards/{id}/rankings/me` — rank e pontuação do próprio chamador
- `DELETE /leaderboards/{id}/scores/{userId}` — deletar própria pontuação (apenas proprietário)

---

## Schema do banco de dados

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — uma linha de pontuação por usuário por placar; atualizações a substituem.

---

## Padrão UPDATE de melhor pontuação

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // Não é um novo recorde pessoal
}
```

Retorna `true` quando a pontuação é um novo recorde pessoal (útil para feedback da UI), `false` quando ignorada.

---

## Cálculo de rank com COUNT(*)

Em vez de uma função de janela (`RANK()` não está disponível em todas as versões do SQLite), conte quantas pontuações são maiores:

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

Se 0 usuários têm pontuação maior, o rank é 1. Se 5 usuários são maiores, o rank é 6. Empates recebem o mesmo rank.

---

## Verificação de propriedade de pontuação (prevenção de IDOR)

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

Sempre verifique a identidade do chamador contra o usuário alvo antes de DELETE. Sem esta verificação, qualquer usuário autenticado poderia deletar qualquer pontuação.

---

## Limitação de parâmetro de query

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

Limite o valor para prevenir que `?limit=99999` varra a tabela inteira.

---

## Avaliação de vulnerabilidades (FT141)

| ID | Ataque | Esperado | Resultado |
|----|--------|----------|--------|
| VULN-A | IDOR: deletar pontuação de outro usuário | 403 | Pass |
| VULN-B | Enviar pontuação para outro usuário | 200 (permitido) | Pass |
| VULN-C | SQL injection no nome do placar | 201 (verbatim) | Pass |
| VULN-D | X-User-Id ausente em /rankings/me | 400 | Pass |
| VULN-E | X-User-Id não numérico | não 200 | Pass |
| VULN-F | ID de placar negativo | não 200 | Pass |
| VULN-G | PHP_INT_MAX como pontuação | 200 (int válido) | Pass |
| VULN-H | Pontuação float (confusão de tipo) | 422 | Pass |
| VULN-I | Pontuação string (confusão de tipo) | 422 | Pass |
| VULN-J | X-User-Id ausente no DELETE | 400 | Pass |
| VULN-K | user_id=0 no envio de pontuação | 422 | Pass |
| VULN-L | `?limit=99999` (limite grande) | 200 + limitado | Pass |

Todos os 12 testes de vulnerabilidade passam. Nenhuma vulnerabilidade encontrada.

---

## Armadilhas comuns

| Armadilha | Correção |
|---------|-----|
| Armazenar todos os envios em vez da melhor pontuação | Verificação `findScore()` antes do INSERT; UPDATE se maior |
| Usar RANK() que pode não existir no SQLite | `COUNT(*) WHERE score > ?` dá rank equivalente |
| IDOR no DELETE de pontuação | Verificar `$actorId !== $userId` → 403 |
| Parâmetro limit sem limite causa varredura de tabela | Limitar `limit` ao range 1–100 |
| Pontuação float/string ignora `is_int()` | `!is_int($score)` rejeita floats e strings no JSON decode do PHP 8 |
