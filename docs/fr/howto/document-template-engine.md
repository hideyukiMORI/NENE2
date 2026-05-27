# How-to : Moteur de templates de documents

Démontre le CRUD de templates avec substitution `{{variable}}` et écriture protégée par clé admin.
Essai terrain : FT197 (`../NENE2-FT/templatelog/`).

## Résumé du pattern
- Contrainte `UNIQUE(name)` sur les templates → 409 en cas de doublon
- L'endpoint de liste exclut `body` pour réduire le payload
- `POST /templates/{id}/render` accepte un objet `vars`, substitue les placeholders `{{clé}}`
- Les variables inconnues sont laissées telles quelles (pas d'erreur)
- La clé admin protège create/update/delete ; render est public
