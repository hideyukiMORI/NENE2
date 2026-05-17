# Endpoints HTTP

Todos os endpoints expostos pela aplicação de exemplo do NENE2.
Cada resposta JSON segue os esquemas definidos em `docs/openapi/openapi.yaml`.

## Saúde e diagnósticos

| Método | Caminho | Auth | Resposta |
|---|---|---|---|
| `GET` | `/health` | Nenhuma | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | Nenhuma | `200` `{ message }` |
| `GET` | `/` | Nenhuma | `200` Página HTML de boas-vindas |

## Notes (Notas)

| Método | Caminho | Auth | Sucesso | Erros |
|---|---|---|---|---|
| `GET` | `/examples/notes` | Nenhuma | `200` lista | — |
| `POST` | `/examples/notes` | Nenhuma | `201` nota | `422` |
| `GET` | `/examples/notes/{id}` | Nenhuma | `200` nota | `404` |
| `PUT` | `/examples/notes/{id}` | Nenhuma | `200` nota | `404`, `422` |
| `DELETE` | `/examples/notes/{id}` | Nenhuma | `204` | `404` |

## Tags (Etiquetas)

| Método | Caminho | Auth | Sucesso | Erros |
|---|---|---|---|---|
| `GET` | `/examples/tags` | Nenhuma | `200` lista | — |
| `POST` | `/examples/tags` | Nenhuma | `201` tag | `422` |
| `GET` | `/examples/tags/{id}` | Nenhuma | `200` tag | `404` |
| `PUT` | `/examples/tags/{id}` | Nenhuma | `200` tag | `404`, `422` |
| `DELETE` | `/examples/tags/{id}` | Nenhuma | `204` | `404` |

## Protegido (cliente máquina)

| Método | Caminho | Auth | Sucesso | Erros |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` ou `Bearer` | `200` JSON | `401` |

Erros seguem [RFC 9457 Problem Details](./problem-details-types).
