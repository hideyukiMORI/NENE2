# How-to: Mehrwährungs-Wallet

Benutzerspezifische Währungsguthaben mit Einzahlungs-/Abhebungs-/Überweisungsoperationen.
Field Trial: FT198 (`../NENE2-FT/walletlog/`). Enthält VULN-A~L Sicherheitsaudit.

## Schlüsselmuster
- Guthaben als Kleinsteinheiten (Cent) gespeichert — vermeidet Float-Präzisionsprobleme
- Selbstüberweisung vor jedem DB-Vorgang abgelehnt (422)
- Unzureichendes Guthaben vor UPDATE geprüft (409)
- IDOR: `WHERE user_id = :uid` bei jeder Wallet- und Transaktionsabfrage
- Währung gegen Allow-List validiert (nicht benutzerseitig angegeben)

## VULN-A~L: ALLE BESTANDEN
