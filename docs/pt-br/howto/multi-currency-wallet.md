# Como Fazer: Carteira Multi-Moeda

Saldos de moeda por usuário com operações de depósito/saque/transferência.
Field trial: FT198 (`../NENE2-FT/walletlog/`). Inclui auditoria de segurança VULN-A~L.

## Padrões principais
- Saldo armazenado como unidades menores (centavos) — evita problemas de precisão float
- Auto-transferência rejeitada antes de qualquer operação no banco de dados (422)
- Fundos insuficientes verificados antes do UPDATE (409)
- IDOR: `WHERE user_id = :uid` em toda query de carteira e transação
- Moeda validada contra lista de permissões (não fornecida pelo usuário)

## VULN-A~L: TODOS APROVADOS
