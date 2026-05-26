# How-To: Multi-Currency Wallet

Per-user currency balances with deposit/withdraw/transfer operations.
Field trial: FT198 (`../NENE2-FT/walletlog/`). Includes VULN-A~L security audit.

## Key patterns
- Balance stored as minor units (cents) — avoids float precision issues
- Self-transfer rejected before any DB operation (422)
- Insufficient funds checked before UPDATE (409)
- IDOR: `WHERE user_id = :uid` on every wallet and transaction query
- Currency validated against allow-list (not user-supplied)

## VULN-A~L: ALL PASS
