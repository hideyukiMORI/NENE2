# Como Fazer: Reações com Emoji com Toggle e Contagens Agrupadas

> **Referência FT**: FT263 (`NENE2-FT/reactionlog`) — Reações com emoji: toggle (adicionar/remover), contagens agrupadas, lista de reações por usuário

Demonstra uma API de reações onde cada usuário pode reagir a qualquer alvo (post, comentário, etc.) com
qualquer emoji ou tipo de reação. Um único endpoint `PUT` alterna a reação: adiciona se não estiver presente,
remove se já estiver presente. Contagens agrupadas por tipo de reação são retornadas em uma consulta de resumo.
Uma constraint `UNIQUE` composta impõe uma reação por usuário por tipo, e
`DatabaseConstraintException` lida com corridas de toggle concorrentes.

---

## Rotas

| Método   | Caminho                                               | Descrição                                   |
|----------|-------------------------------------------------------|---------------------------------------------|
| `PUT`    | `/reactions/{targetType}/{targetId}`                  | Alternar uma reação (adicionar ou remover)  |
| `DELETE` | `/reactions/{targetType}/{targetId}/{reactionType}`   | Remover explicitamente uma reação específica |
| `GET`    | `/reactions/{targetType}/{targetId}`                  | Obter resumo de reações (contagens agrupadas) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` impõe um registro por combinação única
(alvo, usuário, reação). Uma tentativa de inserir uma duplicata gera uma violação de constraint,
que a aplicação captura como `DatabaseConstraintException`.

`target_type` permite que o mesmo sistema de reações sirva múltiplos tipos de entidade (`post`, `comment`,
`message`) sem tabelas separadas.

---

## Padrão de toggle

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // reação foi removida
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // Condição de corrida: toggle concorrente do mesmo usuário — tratar como removido
        return false;
    }

    return true;   // reação foi adicionada
}
```

**Fluxo**:
1. `SELECT` para verificar se a reação existe.
2. Se encontrado: `DELETE` → retorna `false` (removido).
3. Se não encontrado: `INSERT` → retorna `true` (adicionado).
4. Se o `INSERT` falhar com uma violação UNIQUE (`DatabaseConstraintException`): uma requisição
   concorrente inseriu a mesma linha entre nosso `SELECT` e `INSERT`. Tratar como "removido"
   (o toggle concorrente ganhou) → retorna `false`.

**Por que `SELECT` então `INSERT`?** Uma alternativa é `INSERT OR IGNORE` e verificar `changes() == 0`
para detectar quando a linha já existia. A abordagem explícita com `SELECT` torna a intenção
mais clara e produz um valor de retorno mais limpo (adicionado vs removido) sem exigir uma consulta subsequente.

---

## Controller: 201 na adição, 200 na remoção

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

`201 Created` quando a reação é adicionada; `200 OK` quando é removida. O campo `added`
no corpo da resposta permite aos clientes distinguir os dois casos sem verificar o código de status.

**Por que `PUT` para toggle?** `PUT` é idempotente por semântica HTTP. Um toggle de usuário único é
idempotente em efeito (dois `PUT` idênticos retornam ao estado original). Alternativamente,
`POST` é aceitável para um toggle não-idempotente; a escolha depende da convenção da equipe.

---

## Resumo de contagens agrupadas

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

Duas consultas:
1. Contagens agrupadas: `GROUP BY reaction_type ORDER BY cnt DESC` — mais populares primeiro.
2. Reações por usuário (se `$userId` for fornecido): quais tipos de reação este usuário aplicou.

`ORDER BY cnt DESC` coloca as reações mais usadas primeiro, correspondendo à prioridade de exibição típica.

---

## Exemplo de resposta de resumo

**Requisição**: `GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` é um mapa de tipo de reação para contagem. `user_reactions` é a lista de reações
que `alice` aplicou. O cliente pode destacar `👍` para indicar a reação ativa de alice.

---

## Endpoint de remoção explícita

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

`DELETE /reactions/{targetType}/{targetId}/{reactionType}` com `user_id` no corpo remove
uma reação específica sem semântica de toggle. Útil quando o cliente quer remover um tipo específico
de reação independentemente do estado atual.

Retorna 404 se nenhuma reação correspondente for encontrada (`$count == 0`).

---

## Constraint UNIQUE composta como rede de segurança

A constraint `UNIQUE(target_id, target_type, reaction_type, user_id)`:
- **Imposição primária**: previne reações duplicadas no nível do DB.
- **Benefício secundário**: captura condições de corrida que escapam da verificação `SELECT`.
- **Lógica de aplicação**: `toggle()` captura `DatabaseConstraintException` e a trata como uma remoção.

Sem a constraint, uma corrida entre duas requisições `PUT` concorrentes do mesmo usuário
inseriria duas linhas idênticas. A constraint + handler de exceção mantém o invariante
(uma linha por usuário por tipo de reação) mesmo sob concorrência.

---

## Notas de design

| Decisão | Escolha | Justificativa |
|---|---|---|
| Endpoint de toggle | `PUT` | Semanticamente apropriado; idempotente |
| Identidade da reação | Chave composta de 4 colunas | Não é necessária tabela separada de tipos de reação |
| `target_type` | Parâmetro PATH | Permite que um endpoint sirva múltiplos tipos de entidade |
| `user_id` no corpo da requisição | Campo obrigatório | Evita exigir middleware de auth para este FT |
| `user_id` no resumo | Parâmetro de consulta | Opcional — resumo é público; detalhe por usuário é opt-in |

---

## Howtos relacionados

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — tabela de junção M:N com INSERT OR IGNORE para deduplicação de tags
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — chaves únicas compostas como redes de segurança no nível do DB
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — operações atômicas quando múltiplas escritas devem ter sucesso ou falhar juntas
