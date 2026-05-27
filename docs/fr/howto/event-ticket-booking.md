# How-to : Réservation de billets d'événement

Démontre la gestion de capacité d'événement avec achat de billets par utilisateur.
Essai sur le terrain : FT196 (`../NENE2-FT/ticketlog/`). Inclut un test d'attaque cracker ATK-01~12.

## Résumé des patterns

| Problématique | Approche |
|---|---|
| Suivi de capacité | `remaining = capacity - COUNT(tickets)` calculé à la lecture |
| Complet | 409 Conflict quand `remaining <= 0` |
| Achat en double | `UNIQUE(event_id, user_id)` → détecte les doubles achats concurrents |
| Annulation IDOR | Vérification de propriété `user_id` → 403 si non correspondance |
| Clé admin | `hash_equals()` fermé par défaut |

## Résultats ATK-01~12 : TOUS PASS
