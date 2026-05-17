# HTTP Endpoints

All endpoints exposed by the NENE2 example application.
Every JSON response follows the schemas in `docs/openapi/openapi.yaml`.

## Health and diagnostics

| Method | Path | Auth | Response |
|---|---|---|---|
| `GET` | `/health` | None | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | None | `200` `{ message }` |
| `GET` | `/` | None | `200` HTML welcome page |

## Notes

| Method | Path | Auth | Success | Errors |
|---|---|---|---|---|
| `GET` | `/examples/notes` | None | `200` list | — |
| `POST` | `/examples/notes` | None | `201` note | `422` |
| `GET` | `/examples/notes/{id}` | None | `200` note | `404` |
| `PUT` | `/examples/notes/{id}` | None | `200` note | `404`, `422` |
| `DELETE` | `/examples/notes/{id}` | None | `204` | `404` |

## Tags

| Method | Path | Auth | Success | Errors |
|---|---|---|---|---|
| `GET` | `/examples/tags` | None | `200` list | — |
| `POST` | `/examples/tags` | None | `201` tag | `422` |
| `GET` | `/examples/tags/{id}` | None | `200` tag | `404` |
| `PUT` | `/examples/tags/{id}` | None | `200` tag | `404`, `422` |
| `DELETE` | `/examples/tags/{id}` | None | `204` | `404` |

## Protected (machine client)

| Method | Path | Auth | Success | Errors |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` or `Bearer` token | `200` JSON | `401` |

Requests to protected endpoints must include either the `X-NENE2-API-Key` header or a valid `Authorization: Bearer <token>` header.

## Response shapes

**Collection envelope** (shared by Notes and Tags):

```json
{ "items": [...], "limit": 20, "offset": 0 }
```

**Note object**:

```json
{ "id": 1, "title": "My note", "body": "Content here" }
```

**Tag object**:

```json
{ "id": 1, "name": "backend" }
```

Error responses follow [RFC 9457 Problem Details](./problem-details-types).
