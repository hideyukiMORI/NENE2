# Como Fazer: API de Placar de Jogo e Ranking

Este guia demonstra a construção de uma API de placar onde jogadores enviam pontuações por jogo, e o sistema rastreia recordes pessoais e produz placares rankeados.

## Visão Geral do Padrão

- Jogadores enviam pontuações via `POST /scores` (identificados pelo header `X-User-Id`).
- Cada envio é armazenado; o recorde pessoal (maior pontuação de sempre) é retornado.
- `GET /leaderboard?game=<nome>` retorna os top-N jogadores rankeados pelo recorde pessoal.
- `GET /scores/{userId}?game=<nome>` lista todas as pontuações brutas de um jogador específico.
- Jogos são totalmente isolados — pontuações em "tetris" nunca afetam "snake".

## Schema

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_scores_game ON scores (game, score DESC);
```

Todas as tentativas são armazenadas (sem upsert), o que permite histórico de pontuação por jogo. O recorde pessoal é derivado com `MAX(score)` no momento da query.

## Envio de Pontuação

```php
public function submit(int $userId, string $game, int $score): void
{
    $this->pdo->prepare(
        'INSERT INTO scores (user_id, game, score, created_at) VALUES (:uid, :game, :score, :now)'
    )->execute([':uid' => $userId, ':game' => $game, ':score' => $score, ':now' => $this->now()]);
}

public function bestScore(int $userId, string $game): ?int
{
    $stmt = $this->pdo->prepare(
        'SELECT MAX(score) FROM scores WHERE user_id = :uid AND game = :game'
    );
    $stmt->execute([':uid' => $userId, ':game' => $game]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (int) $val : null;
}
```

O handler retorna o recorde pessoal junto com a confirmação:

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## Query de Placar

Melhor pontuação por usuário, ordenada decrescentemente, com rank adicionado em PHP:

```php
public function leaderboard(string $game, int $limit): array
{
    $stmt = $this->pdo->prepare(
        'SELECT user_id, MAX(score) AS best_score
         FROM scores
         WHERE game = :game
         GROUP BY user_id
         ORDER BY best_score DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':game', $game, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

Resposta:

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## Regras de Validação

| Campo | Regra |
|---|---|
| Header `X-User-Id` | Obrigatório para POST; `ctype_digit`, 1–18 chars, valor > 0 |
| `game` corpo/query | Obrigatório, não-vazio, máx 64 chars |
| `score` corpo | Apenas inteiro ≥ 0 (`is_int($score) && $score >= 0`) |
| Parâmetro de caminho `userId` | `ctype_digit`, máx 18 chars, valor > 0; caso contrário 404 |
| `limit` query | 1–100, padrão 10; valores inválidos silenciosamente limitados |

Pontuações string (`"100"`) são rejeitadas com 422 porque `is_int()` retorna false para strings mesmo quando o valor é numérico.

Zero é uma pontuação válida — útil para jogos onde um jogador pode não pontuar.

## Rotas

```
POST   /scores              Enviar uma pontuação (X-User-Id obrigatório)
GET    /leaderboard         Jogadores top-N rankeados (?game= obrigatório, ?limit= opcional)
GET    /scores/{userId}     Todas as pontuações de um jogador (?game= obrigatório)
```

## Isolamento de Jogo

A coluna `game` age como um namespace. Sempre inclua `WHERE game = :game` em toda query. Um jogador que pontua em "tetris" nunca aparecerá no placar de "snake".

## Veja Também

- Fonte FT206: `../NENE2-FT/leaderboardlog/`
- Relacionado: `docs/howto/rate-limiting.md` (FT200), `docs/howto/coupon-redemption.md` (FT204)
