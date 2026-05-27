# How-to : Portefeuille multi-devises

Soldes de devises par utilisateur avec opérations de dépôt/retrait/transfert.
Field trial : FT198 (`../NENE2-FT/walletlog/`). Inclut l'audit de sécurité VULN-A~L.

## Patterns clés
- Solde stocké en unités mineures (centimes) — évite les problèmes de précision float
- Auto-transfert rejeté avant toute opération DB (422)
- Fonds insuffisants vérifiés avant UPDATE (409)
- IDOR : `WHERE user_id = :uid` sur chaque requête de portefeuille et transaction
- Devise validée contre une liste d'autorisation (pas fournie par l'utilisateur)

## VULN-A~L : TOUS PASS
