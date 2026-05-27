# Como Fazer: Reserva de Ingressos para Eventos

Demonstra gerenciamento de capacidade de eventos com compra de ingressos por usuário.
Field trial: FT196 (`../NENE2-FT/ticketlog/`). Inclui teste de ataque de cracker ATK-01~12.

## Resumo do padrão

| Preocupação | Abordagem |
|---|---|
| Rastreamento de capacidade | `remaining = capacity - COUNT(tickets)` computado na leitura |
| Esgotado | 409 Conflict quando `remaining <= 0` |
| Compra duplicada | `UNIQUE(event_id, user_id)` → captura compra dupla concorrente |
| Cancelamento IDOR | Verificação de ownership `user_id` → 403 se incompatível |
| Chave de admin | `hash_equals()` fail-closed |

## Resultados ATK-01~12: TODOS PASSAM
