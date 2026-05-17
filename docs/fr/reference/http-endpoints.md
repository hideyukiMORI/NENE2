# Endpoints HTTP

Tous les endpoints exposés par l'application exemple NENE2.
Chaque réponse JSON suit les schémas définis dans `docs/openapi/openapi.yaml`.

## Santé et diagnostics

| Méthode | Chemin | Auth | Réponse |
|---|---|---|---|
| `GET` | `/health` | Aucune | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | Aucune | `200` `{ message }` |
| `GET` | `/` | Aucune | `200` Page HTML d'accueil |

## Notes

| Méthode | Chemin | Auth | Succès | Erreurs |
|---|---|---|---|---|
| `GET` | `/examples/notes` | Aucune | `200` liste | — |
| `POST` | `/examples/notes` | Aucune | `201` note | `422` |
| `GET` | `/examples/notes/{id}` | Aucune | `200` note | `404` |
| `PUT` | `/examples/notes/{id}` | Aucune | `200` note | `404`, `422` |
| `DELETE` | `/examples/notes/{id}` | Aucune | `204` | `404` |

## Tags

| Méthode | Chemin | Auth | Succès | Erreurs |
|---|---|---|---|---|
| `GET` | `/examples/tags` | Aucune | `200` liste | — |
| `POST` | `/examples/tags` | Aucune | `201` tag | `422` |
| `GET` | `/examples/tags/{id}` | Aucune | `200` tag | `404` |
| `PUT` | `/examples/tags/{id}` | Aucune | `200` tag | `404`, `422` |
| `DELETE` | `/examples/tags/{id}` | Aucune | `204` | `404` |

## Protégé (client machine)

| Méthode | Chemin | Auth | Succès | Erreurs |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` ou `Bearer` | `200` JSON | `401` |

Les erreurs suivent [RFC 9457 Problem Details](./problem-details-types).
