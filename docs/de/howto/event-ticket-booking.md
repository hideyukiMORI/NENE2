# How-to: Event-Ticket-Buchung

Demonstriert die Kapazitätsverwaltung für Events mit Pro-Benutzer-Ticketkauf.
Field Trial: FT196 (`../NENE2-FT/ticketlog/`). Enthält ATK-01~12 Cracker-Angriffstest.

## Muster-Übersicht

| Thema | Ansatz |
|---|---|
| Kapazitätsverfolgung | `remaining = capacity - COUNT(tickets)` wird beim Lesen berechnet |
| Ausverkauft | 409 Conflict wenn `remaining <= 0` |
| Doppelkauf | `UNIQUE(event_id, user_id)` → fängt gleichzeitige Doppelkäufe ab |
| IDOR-Stornierung | `user_id`-Eigentumsüberprüfung → 403 bei Nichtübereinstimmung |
| Admin-Schlüssel | `hash_equals()` fail-closed |

## ATK-01~12 Ergebnisse: ALLE BESTANDEN
