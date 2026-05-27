# Como Fazer: API de Placar e Rastreamento de Pontuação

Este guia mostra como construir um sistema de placar com envio de pontuação, rankings top-N usando
agregação de melhor pontuação por usuário e histórico de pontuações pessoais usando NENE2.
Padrão demonstrado pelo field trial **leaderboardlog** (FT206).

## Funcionalidades

- Envio de pontuação por usuário por jogo (header `X-User-Id`)
- Placar top-N: melhor pontuação por usuário rankeada decrescentemente (`MAX(score) GROUP BY user_id`)
- Histórico de pontuações pessoais para qualquer combinação usuário/jogo
- Query de recorde pessoal
- Limite configurável (limitado 1–100)

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
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/scores` | Usuário | Enviar uma pontuação |
| `GET` | `/leaderboard?game=<jogo>` | Público | Placar top-N |
| `GET` | `/scores/{userId}?game=<jogo>` | Público | Histórico de pontuações do usuário |

## Envio de Pontuação

```php
$game  = trim((string) ($body['game'] ?? ''));
$score = $body['score'] ?? null;

if ($game === '' || strlen($game) > 64) {
    return $this->problem(422, 'validation-failed', 'game required (max 64 chars).');
}
if (!is_int($score) || $score < 0) {
    return $this->problem(422, 'validation-failed', 'score must be a non-negative integer.');
}
```

Pontos principais:
- `is_int($score)` — verificação estrita; rejeita floats (`1.5`) e strings do JSON
- Nome do jogo limitado a 64 chars — previne DoS por nome de jogo excessivamente grande
- Pontuação não-negativa — previne injeção de pontuação negativa

Retorna o recorde pessoal atualizado no 201:

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## Query de Ranking Top-N

Ranking de melhor pontuação por usuário com atribuição de rank denso em PHP:

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

    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

- `MAX(score) GROUP BY user_id` — uma linha por usuário, seu recorde pessoal
- `ORDER BY best_score DESC` — maior pontuador primeiro
- Vinculação `PDO::PARAM_INT` para LIMIT — seguro contra SQL injection

Exemplo de resposta:

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## Limitação de Limit

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

Limites inválidos ou fora do range silenciosamente assumem o valor padrão 10 — nunca confie em inteiros fornecidos pelo cliente para LIMIT.

## Padrões de Validação

| Entrada | Verificação | Motivo |
|-------|-------|--------|
| `score` | `is_int($score) && $score >= 0` | Rejeita floats, strings, negativos |
| `game` | `strlen($game) <= 64` | Previne entrada excessivamente grande |
| `limit` | `ctype_digit()` + limitação de range | Seguro contra ReDoS, limitado |
| `userId` (caminho) | `ctype_digit()` + `> 0` | Valida antes da query ao BD |
