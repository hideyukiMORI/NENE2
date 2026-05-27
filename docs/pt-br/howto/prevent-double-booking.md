# Como Prevenir Reserva Dupla (aplicação de reservas e capacidade)

Os sistemas de reserva têm dois modos distintos de falha que devem ser tratados separadamente:

1. **Reserva duplicada** — o mesmo usuário tenta reservar o mesmo horário duas vezes
2. **Excesso de capacidade** — o número de reservas excederia o limite do horário

Ambos resultam em um INSERT rejeitado, mas requerem respostas de erro diferentes.
Este guia mostra como distingui-los e proteger contra conflitos concorrentes.

---

## 1. Schema: restrição UNIQUE + coluna de capacidade

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- guarda de último recurso contra reservas duplicadas
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

A restrição `UNIQUE(slot_id, user_id)` é a rede de segurança — ela previne reservas duplicadas
mesmo que a lógica da aplicação tenha um bug. Mas não pode dizer *por que* o INSERT falhou.

---

## 2. Distinguir duplicata de excesso de capacidade com uma verificação explícita

`DatabaseConstraintException` não carrega informações no nível de coluna sobre qual restrição
foi acionada. Para retornar respostas 409 diferentes, verifique cada condição antes do INSERT:

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. Verificar reserva duplicada primeiro
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. Verificar capacidade restante
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // chamador mapeia null → 409 slot-full
    }

    // 3. INSERT — restrição UNIQUE é a guarda final
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

Use uma **exceção de domínio** (`AlreadyReservedException`) para a regra de negócio voltada ao usuário,
não `DatabaseConstraintException` — que sinaliza um evento na camada do banco, não uma condição de negócio.

---

## 3. Handler: mapear para respostas 409 distintas

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. Calcular disponibilidade no SQL (evitar N+1)

Contar reservas na mesma query que a busca do horário:

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

Então `available = capacity - reserved`. Nunca buscar todas as reservas para contá-las no PHP.

---

## 5. Concorrência: TOCTOU e quando importa

O padrão de verificar-depois-inserir tem uma **janela TOCTOU (Time-of-Check / Time-of-Use)**:
duas requisições concorrentes podem ambas passar pela verificação de capacidade e então ambas tentar inserir.

| Banco de Dados | Comportamento |
|---|---|
| **SQLite** | Serialização de escrita por banco: apenas uma escrita roda por vez. O segundo INSERT atinge a restrição UNIQUE e lança `DatabaseConstraintException`. Seguro. |
| **PostgreSQL** sob alta concorrência | Duas requisições com `user_id` diferentes podem ambas passar pela verificação `available > 0` e ambas fazer INSERT, excedendo brevemente a capacidade em 1. A restrição UNIQUE não é acionada (usuários diferentes). |

**Correção para PostgreSQL**: envolva a verificação e o INSERT em uma transação `SERIALIZABLE`, ou use
`SELECT ... FOR UPDATE` na linha do horário para bloqueá-la antes de ler:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // Todas as queries neste closure compartilham o mesmo snapshot serializável
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

Para SQLite, a restrição UNIQUE sozinha é proteção suficiente.

---

## 6. Testar cenários de estilo concorrente

Testes sequenciais não podem reproduzir verdadeira concorrência, mas podem verificar a intenção:

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // chega "simultaneamente"

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // horário cheio

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // Após cancelar, bob pode reservar
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## Notas

- A restrição UNIQUE é uma **guarda de último recurso** — ela pega bugs na lógica da aplicação.
  Nunca dependa dela como principal aplicador de capacidade, porque não consegue distinguir usuário-duplicado
  de excesso-de-capacidade.
- **Cancelar e reservar novamente**: quando um usuário cancela, exclua de `reservations`. A contagem de capacidade
  decrementa automaticamente via query `COUNT(r.id)`. Não é necessária atualização explícita de "liberar horário".
- **Cancelamento idempotente**: `DELETE WHERE slot_id = ? AND user_id = ?` retorna 0 linhas se
  a reserva não existir — mapeie isso para 404, não 500.
