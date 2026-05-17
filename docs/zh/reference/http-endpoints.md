# HTTP 端点

NENE2 示例应用公开的所有端点。
所有 JSON 响应均遵循 `docs/openapi/openapi.yaml` 中定义的模式。

## 健康检查与诊断

| 方法 | 路径 | 认证 | 响应 |
|---|---|---|---|
| `GET` | `/health` | 无 | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | 无 | `200` `{ message }` |
| `GET` | `/` | 无 | `200` HTML 欢迎页面 |

## Notes（笔记）

| 方法 | 路径 | 认证 | 成功 | 错误 |
|---|---|---|---|---|
| `GET` | `/examples/notes` | 无 | `200` 列表 | — |
| `POST` | `/examples/notes` | 无 | `201` 笔记 | `422` |
| `GET` | `/examples/notes/{id}` | 无 | `200` 笔记 | `404` |
| `PUT` | `/examples/notes/{id}` | 无 | `200` 笔记 | `404`、`422` |
| `DELETE` | `/examples/notes/{id}` | 无 | `204` | `404` |

## Tags（标签）

| 方法 | 路径 | 认证 | 成功 | 错误 |
|---|---|---|---|---|
| `GET` | `/examples/tags` | 无 | `200` 列表 | — |
| `POST` | `/examples/tags` | 无 | `201` 标签 | `422` |
| `GET` | `/examples/tags/{id}` | 无 | `200` 标签 | `404` |
| `PUT` | `/examples/tags/{id}` | 无 | `200` 标签 | `404`、`422` |
| `DELETE` | `/examples/tags/{id}` | 无 | `204` | `404` |

## 受保护端点（机器客户端）

| 方法 | 路径 | 认证 | 成功 | 错误 |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` 或 `Bearer` | `200` JSON | `401` |

错误响应遵循 [RFC 9457 Problem Details](./problem-details-types)。
