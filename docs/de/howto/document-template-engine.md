# How-To: Dokument-Template-Engine

Demonstriert Template-CRUD mit `{{Variable}}`-Substitution und admin-gesicherten Schreibvorgängen.
Field Trial: FT197 (`../NENE2-FT/templatelog/`).

## Muster-Zusammenfassung
- `UNIQUE(name)`-Constraint auf Templates → 409 bei Duplikat
- List-Endpunkt schließt `body` aus, um den Payload zu reduzieren
- `POST /templates/{id}/render` akzeptiert ein `vars`-Objekt, substituiert `{{schlüssel}}`-Platzhalter
- Unbekannte Variablen bleiben unverändert (kein Fehler)
- Admin-Key sichert Create/Update/Delete; Rendern ist öffentlich
