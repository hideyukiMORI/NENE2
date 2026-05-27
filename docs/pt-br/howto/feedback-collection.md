# Como Fazer: API de Coleta de Feedback

## Visão Geral

Um sistema de feedback onde usuários enviam uma pontuação (1-5) e um comentário para uma entidade alvo. Admin pode listar todos os feedbacks; endpoint público de estatísticas mostra médias agregadas.

**Implementação de referência**: `../NENE2-FT/feedbacklog/`

## Schema

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## Rotas

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/feedback` | Usuário | Enviar feedback |
| `GET` | `/feedback` | Admin | Listar todos os feedbacks |
| `GET` | `/feedback/stats` | Nenhuma | Estatísticas agregadas |

## Prevenção de Duplicatas

`UNIQUE (user_id, target)` garante um feedback por usuário por alvo no nível do BD. Verificação no nível de aplicação primeiro:

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## Validação de Pontuação

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## Agregação de Estatísticas

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

Retornar média `null` quando a contagem for zero para evitar `NaN` no JSON.

## Códigos de Status HTTP

| Situação | Status |
|----------|--------|
| Feedback enviado | 201 |
| Estatísticas / lista | 200 |
| Sem X-User-Id | 400 |
| Target vazio / pontuação inválida | 422 |
| Sem chave de admin | 403 |
| Feedback duplicado | 409 |
