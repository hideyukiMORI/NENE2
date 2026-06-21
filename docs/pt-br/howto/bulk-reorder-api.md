# Como Fazer: API de Reordenação em Massa (Ordenação Drag-and-Drop)

Uma UI de arrastar e soltar envia a *nova ordem completa* de uma lista em uma única requisição: `[itemC, itemA, itemD, itemB]`. O servidor ingênuo faz um `UPDATE` por item — N round-trips e uma ordem aplicada pela metade se um deles falhar.

A forma correta é **uma transação** que reescreve cada posição com valores atribuídos pelo servidor, com escopo no board do dono. Como ela é escrita depende de uma única coisa: **se `position` carrega uma constraint `UNIQUE (board_id, position)`.**

> **Armadilha verificada (FT352).** O SQLite verifica `UNIQUE` **por linha** conforme um `UPDATE` é aplicado. Então *qualquer* statement que troque posições — mesmo um único `CASE WHEN` sobre todas as linhas — coloca transitoriamente duas linhas na mesma posição e falha com `UNIQUE constraint failed: items.board_id, items.position`. Um único statement basta **apenas quando `position` não tem constraint `UNIQUE`** (§1). Com a constraint, você precisa de uma escrita em duas fases dentro de uma transação (§1.1). A prova executável está em [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

**Pré-requisito**: Uma tabela com uma coluna inteira `position` com escopo em um pai (`board_id`, `list_id`, …). Veja [Content pinning](content-pinning.md) para o caso de item único.

---

## 1. Um único statement (sem constraint `UNIQUE` em `position`)

O cliente envia apenas a *lista ordenada de ids*. O servidor deriva as posições a partir do índice do array — ele nunca confia em números de posição fornecidos pelo cliente. Quando `position` é apenas uma coluna indexada (sem `UNIQUE`), um único statement basta:

```php
/**
 * @param list<int> $orderedIds  ids in their new display order
 * @return int  number of rows actually updated
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = array index, not client input
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

Verificado contra o SQLite — reordenando `[1,2,3,4]` para os ids `[3,1,4,2]` em um único statement:

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

As posições são reatribuídas `0..n-1` a partir do índice do array, então o resultado é sempre contíguo, independentemente do que o cliente enviou.

---

## 1.1. Escrita em duas fases quando `position` é `UNIQUE`

Se `UNIQUE (board_id, position)` protege sua ordenação (recomendado — isso impede posições duplicadas no nível do banco de dados), o único statement acima falha no momento em que troca duas linhas. Desloque cada posição para uma faixa livre de colisões primeiro, depois atribua os valores finais — ambos os passos em **uma transação**, para que o estado intermediário nunca seja observável:

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // Phase 1: move every position to a unique negative value (no collisions).
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // Phase 2: assign final positions from the array index.
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` mapeia `0,1,2,…` para `-1,-2,-3,…` — valores distintos que não podem colidir com os finais `0..n-1`. Veja [Use transactions](use-transactions.md) para a regra do `transactional()` (instancie os repositórios *dentro* do callback). O `testReorderAdjacentSwapDoesNotCollide` do `reorderlog` exercita exatamente a troca que quebra um único statement.

---

## 2. A contagem de linhas afetadas é sua verificação de integridade

`execute()` retorna o número de linhas correspondidas por `WHERE board_id = ? AND id IN (...)`. Compare-o com o tamanho da requisição:

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // The client referenced ids that are not in this board (or do not exist).
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

Essa única verificação derrota a maior parte da superfície de ataque abaixo: qualquer id que pertença a outro board, ou que não exista, simplesmente não corresponde ao `WHERE`, então a contagem fica curta e toda a reordenação é rejeitada.

> Envolva a verificação de contagem e o `UPDATE` em `transactional()` se você também mutar linhas relacionadas; o próprio `UPDATE` único já é atômico. Veja [Use transactions](use-transactions.md).

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

Alvo: `PUT /boards/{boardId}/order` com corpo `{ "ids": [...] }`, autenticado, `board_id` com escopo no chamador.

### ATK-01 — Reordenar um board que você não possui (IDOR) 🚫 BLOCKED

**Ataque**: Enviar um array `ids` válido, mas um `boardId` pertencente a outro usuário.
**Resultado**: BLOCKED — a propriedade é verificada antes da query (`board.owner_id === caller`), retornando `404`; mesmo se ignorada, `WHERE board_id = ?` não corresponde a nenhuma linha à qual os ids do chamador pertençam, então a contagem de afetadas é 0 e a requisição é rejeitada.

---

### ATK-02 — Contrabandear um item estrangeiro para dentro da ordem 🚫 BLOCKED

**Ataque**: Incluir um `id` de um board diferente para movê-lo/vazá-lo.
**Resultado**: BLOCKED — `WHERE board_id = ? AND id IN (...)` exclui o id estrangeiro; contagem de afetadas < tamanho da requisição → `422`, sem escrita parcial.

---

### ATK-03 — Ordem parcial (omitir ids para criar lacunas) 🚫 BLOCKED

**Ataque**: Enviar apenas metade dos ids do board para deixar o restante em posições obsoletas.
**Resultado**: BLOCKED — o handler exige que o conjunto enviado seja igual ao conjunto de ids atual do board (contagem + pertencimento), rejeitando payloads incompletos.

---

### ATK-04 — Injetar números de posição explícitos 🚫 BLOCKED

**Ataque**: Enviar `{ "ids": [...], "positions": [99, -1, ...] }` esperando que o servidor os honre.
**Resultado**: BLOCKED — o servidor ignora qualquer posição do cliente; `position` é o índice do array. Campos extras no corpo são descartados pelo DTO readonly.

---

### ATK-05 — SQL injection via id / position 🚫 BLOCKED

**Ataque**: `ids: ["1); DROP TABLE items;--", ...]`.
**Resultado**: BLOCKED — cada id e position é um parâmetro vinculado; os placeholders do `CASE`/`IN` são gerados por contagem, nunca por concatenação de string.

---

### ATK-06 — Ids duplicados para corromper posições 🚫 BLOCKED

**Ataque**: `ids: [5, 5, 5]` para que uma linha receba vários braços do `CASE`.
**Resultado**: BLOCKED — o DTO valida a unicidade dos ids; o SQLite em todo caso aplicaria o último `WHEN` correspondente, e a verificação de contagem (`distinct ids` vs tamanho do board) falha primeiro.

---

### ATK-07 — Payload superdimensionado (DoS) 🚫 BLOCKED

**Ataque**: Postar 1.000.000 de ids para construir um `CASE` gigante.
**Resultado**: BLOCKED — `RequestSizeLimitMiddleware` limita o corpo, e o handler rejeita arrays maiores que a contagem de linhas do board.

---

### ATK-08 — Ids não inteiros / negativos 🚫 BLOCKED

**Ataque**: `ids: ["abc", -1, 1.5]`.
**Resultado**: BLOCKED — a validação do DTO coage/valida cada entrada como um inteiro positivo (`422` em caso de falha) antes que qualquer SQL rode.

---

### ATK-09 — Condição de corrida em reordenação concorrente 🚫 BLOCKED

**Ataque**: Disparar duas reordenações simultaneamente para intercalar posições.
**Resultado**: BLOCKED — cada reordenação roda em uma transação; o último escritor vence com uma ordenação totalmente consistente `0..n-1`, nunca uma mistura intercalada. A escrita em duas fases (§1.1) mantém o estado intermediário dentro da transação, então um leitor concorrente nunca vê uma ordem parcial ou colidente.

---

### ATK-10 — Overflow de posição / resultado não contíguo 🚫 BLOCKED

**Ataque**: Esperar que reordenações repetidas façam as posições derivarem para valores enormes ou esparsos.
**Resultado**: BLOCKED — cada reordenação reescreve as posições a partir de `0`, então a coluna é sempre densa e limitada pela contagem de linhas.

---

### ATK-11 — Ordem vazia para apagar posições 🚫 BLOCKED

**Ataque**: `ids: []`.
**Resultado**: BLOCKED — arrays vazios falham na validação (`min 1`), e um `IN ()` vazio seria um erro de sintaxe que nunca executa.

---

### ATK-12 — Enumeração de board id cross-tenant 🚫 BLOCKED

**Ataque**: Iterar `boardId` para descobrir quais existem via respostas distintas.
**Resultado**: BLOCKED — boards desconhecidos e não pertencentes retornam ambos um `404` idêntico; nenhum oráculo de contagem ou timing os distingue.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Reordenar board não pertencente (IDOR) | 🚫 BLOCKED |
| ATK-02 | Contrabandear item estrangeiro | 🚫 BLOCKED |
| ATK-03 | Ordem parcial / lacunas | 🚫 BLOCKED |
| ATK-04 | Injetar posições explícitas | 🚫 BLOCKED |
| ATK-05 | SQL injection | 🚫 BLOCKED |
| ATK-06 | Ids duplicados | 🚫 BLOCKED |
| ATK-07 | Payload superdimensionado | 🚫 BLOCKED |
| ATK-08 | Ids não inteiros / negativos | 🚫 BLOCKED |
| ATK-09 | Condição de corrida em reordenação concorrente | 🚫 BLOCKED |
| ATK-10 | Overflow / esparsidade de posição | 🚫 BLOCKED |
| ATK-11 | Ordem vazia | 🚫 BLOCKED |
| ATK-12 | Enumeração de board id | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED.** Nenhuma descoberta crítica. A combinação de *posições atribuídas pelo servidor* (índice do array, nunca entrada do cliente) e a *verificação de integridade de contagem afetada / conjunto de ids* contra um `WHERE` com escopo no board fecha a superfície de reordenação. A única armadilha de *corretude* (não uma descoberta de segurança) é a constraint `UNIQUE (board_id, position)`: ela faz um único statement `CASE` falhar em qualquer troca, então use a escrita transacional em duas fases da §1.1 — verificada em [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

---

## Relacionados

- [Content pinning](content-pinning.md) — gerenciamento de posição de item único
- [Pin / bookmark ordering](pin-bookmark-ordering.md) — ordenação por usuário
- [Use transactions](use-transactions.md) — envolver reordenações multi-tabela atomicamente
