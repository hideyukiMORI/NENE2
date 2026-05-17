# HTTP-Endpunkte

Alle Endpunkte der NENE2-Beispielanwendung.
Alle JSON-Antworten folgen den Schemas in `docs/openapi/openapi.yaml`.

## Gesundheit und Diagnose

| Methode | Pfad | Auth | Antwort |
|---|---|---|---|
| `GET` | `/health` | Keine | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | Keine | `200` `{ message }` |
| `GET` | `/` | Keine | `200` HTML-Willkommensseite |

## Notes (Notizen)

| Methode | Pfad | Auth | Erfolg | Fehler |
|---|---|---|---|---|
| `GET` | `/examples/notes` | Keine | `200` Liste | — |
| `POST` | `/examples/notes` | Keine | `201` Notiz | `422` |
| `GET` | `/examples/notes/{id}` | Keine | `200` Notiz | `404` |
| `PUT` | `/examples/notes/{id}` | Keine | `200` Notiz | `404`, `422` |
| `DELETE` | `/examples/notes/{id}` | Keine | `204` | `404` |

## Tags (Schlagwörter)

| Methode | Pfad | Auth | Erfolg | Fehler |
|---|---|---|---|---|
| `GET` | `/examples/tags` | Keine | `200` Liste | — |
| `POST` | `/examples/tags` | Keine | `201` Tag | `422` |
| `GET` | `/examples/tags/{id}` | Keine | `200` Tag | `404` |
| `PUT` | `/examples/tags/{id}` | Keine | `200` Tag | `404`, `422` |
| `DELETE` | `/examples/tags/{id}` | Keine | `204` | `404` |

## Geschützt (Machine-Client)

| Methode | Pfad | Auth | Erfolg | Fehler |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` oder `Bearer` | `200` JSON | `401` |

Fehlerantworten folgen [RFC 9457 Problem Details](./problem-details-types).
