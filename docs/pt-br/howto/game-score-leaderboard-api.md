# Como Fazer: API de Pontuação e Ranking de Jogo

> **Referência FT**: FT259 (`NENE2-FT/scorelog`) — Envio de pontuação de jogo com inserção em lote (máx. 100, tudo-ou-nada), ranking por jogador com agregação de `best_score` e `play_count`, prevenção de pontuação negativa, paginação, 20 testes PASS.

Este guia mostra como construir um sistema de pontuação de jogo: registrar pontuações individuais, importar resultados em lote e calcular rankings por jogo.

## Schema

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- data ISO 8601: YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` previne pontuações negativas no nível do BD. Índices em `game` e `player` suportam listagens filtradas e consultas de ranking.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/scores` | Enviar uma única pontuação |
| `POST` | `/scores/bulk` | Envio em lote de até 100 pontuações |
| `GET` | `/scores` | Listar pontuações (filtro + paginação) |
| `GET` | `/scores/leaderboard` | Ranking por jogador de um jogo |
| `GET` | `/scores/{id}` | Obter uma única pontuação |
| `DELETE` | `/scores/{id}` | Deletar pontuação |

## Enviar uma Pontuação

```php
POST /scores
{
  "player":    "Alice",
  "game":      "tetris",
  "score":     1500,
  "played_at": "2026-01-15"
}

→ 201
{
  "id": 1,
  "player": "Alice",
  "game": "tetris",
  "score": 1500,
  "played_at": "2026-01-15",
  "created_at": "..."
}
```

Múltiplas pontuações por jogador por jogo são permitidas — cada partida é um registro separado.

### Validação

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player é obrigatório

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // pontuação deve ser >= 0

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at deve estar no formato YYYY-MM-DD

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // pontuação = 0 é válida
```

## Listar Pontuações

```php
// Todas as pontuações
GET /scores
→ 200  {"items": [...], "total": 10}

// Filtrar por jogo
GET /scores?game=tetris
→ 200  {"items": [/* apenas pontuações de tetris */], "total": 3}

// Filtrar por jogador
GET /scores?player=Alice
→ 200  {"items": [/* apenas pontuações de Alice */], "total": 2}

// Paginação
GET /scores?limit=2&offset=1
→ 200  {"items": [/* 2 itens, a partir do índice 1 */], "total": 5}
```

## Envio em Lote

```php
POST /scores/bulk
{
  "scores": [
    {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
    {"player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16"},
    {"player": "Carol", "game": "snake",  "score": 500,  "played_at": "2026-01-15"}
  ]
}

→ 201
{
  "created": 3,
  "scores": [
    {"id": 1, "player": "Alice", ...},
    {"id": 2, "player": "Bob",   ...},
    {"id": 3, "player": "Carol", ...}
  ]
}
```

### Regras de Validação em Lote

```php
// Array vazio
POST /scores/bulk  {"scores": []}
→ 422  // pelo menos 1 entrada obrigatória

// Qualquer entrada inválida falha o lote inteiro
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" não pode ser vazio — nenhum registro é inserido

// Mais de 100 entradas
POST /scores/bulk  {"scores": [...101 entradas...]}
→ 422  // máx. 100 entradas por requisição em lote
```

**Tudo-ou-nada**: validar todas as entradas antes de inserir qualquer uma. Usar transação de BD para garantir atomicidade.

### Implementação do Lote

```php
public function bulkSubmit(array $entries): array
{
    // Validar todas as entradas primeiro
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // Inserir todas em uma transação
    $this->db->beginTransaction();
    try {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $this->repo->insert($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
        }
        $this->db->commit();
        return $this->repo->findByIds($ids);
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

## Ranking

```php
GET /scores/leaderboard?game=tetris

→ 200
{
  "game":    "tetris",
  "top":     10,
  "entries": [
    {"rank": 1, "player": "Alice", "best_score": 3000, "play_count": 2},
    {"rank": 2, "player": "Bob",   "best_score": 2000, "play_count": 1},
    {"rank": 3, "player": "Carol", "best_score": 500,  "play_count": 1}
  ]
}
```

Cada jogador aparece **uma vez** — com sua melhor pontuação (mais alta) em todas as partidas, mais um `play_count`.

### Limite Top-N

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3 jogadores...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top deve ser >= 1

GET /scores/leaderboard          // game faltando
→ 422
```

### SQL do Ranking

```sql
SELECT
    player,
    MAX(score)   AS best_score,
    COUNT(*)     AS play_count,
    RANK() OVER (ORDER BY MAX(score) DESC) AS rank
FROM scores
WHERE game = ?
GROUP BY player
ORDER BY best_score DESC
LIMIT ?
```

`RANK() OVER (ORDER BY MAX(score) DESC)` atribui o mesmo rank para jogadores empatados (com lacunas nos ranks subsequentes). Se preferir sem lacunas, use `DENSE_RANK()`.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Permitir pontuações negativas apenas na camada de aplicação | A constraint `CHECK(score >= 0)` do BD é a guarda final; validação de aplicação pode ser contornada |
| Inserir entradas em lote uma a uma sem transação | Falha parcial deixa metade do lote no BD; impossível distinguir comprometido do não comprometido |
| Validar entradas em lote dentro do loop de inserção | Primeiras N entradas são inseridas antes da falha de validação; dados parciais no BD |
| Usar `score = MAX(score)` sem GROUP BY | Agrega a tabela inteira sem agrupamento por jogador; resultados de ranking incorretos |
| Retornar todos os jogadores no ranking sem LIMIT | Varredura e ordenação de tabela completa sem limite; risco de DoS para tabelas de pontuação grandes |
| Calcular `best_score` buscando todas as pontuações em PHP | O(N) por jogador; usar agregação `MAX()` do SQL |
