# HTTP エンドポイント

NENE2 サンプルアプリが公開するすべてのエンドポイントです。
すべての JSON レスポンスは `docs/openapi/openapi.yaml` のスキーマに準拠します。

## ヘルス・診断

| メソッド | パス | 認証 | レスポンス |
|---|---|---|---|
| `GET` | `/health` | なし | `200` `{ service, status, timestamp }` |
| `GET` | `/examples/ping` | なし | `200` `{ message }` |
| `GET` | `/` | なし | `200` HTML ウェルカムページ |

## Notes（ノート）

| メソッド | パス | 認証 | 成功 | エラー |
|---|---|---|---|---|
| `GET` | `/examples/notes` | なし | `200` リスト | — |
| `POST` | `/examples/notes` | なし | `201` ノート | `422` |
| `GET` | `/examples/notes/{id}` | なし | `200` ノート | `404` |
| `PUT` | `/examples/notes/{id}` | なし | `200` ノート | `404`, `422` |
| `DELETE` | `/examples/notes/{id}` | なし | `204` | `404` |

## Tags（タグ）

| メソッド | パス | 認証 | 成功 | エラー |
|---|---|---|---|---|
| `GET` | `/examples/tags` | なし | `200` リスト | — |
| `POST` | `/examples/tags` | なし | `201` タグ | `422` |
| `GET` | `/examples/tags/{id}` | なし | `200` タグ | `404` |
| `PUT` | `/examples/tags/{id}` | なし | `200` タグ | `404`, `422` |
| `DELETE` | `/examples/tags/{id}` | なし | `204` | `404` |

## 保護されたエンドポイント（マシンクライアント）

| メソッド | パス | 認証 | 成功 | エラー |
|---|---|---|---|---|
| `GET` | `/examples/protected` | `X-NENE2-API-Key` または `Bearer` トークン | `200` JSON | `401` |

保護されたエンドポイントへのリクエストには、`X-NENE2-API-Key` ヘッダーまたは `Authorization: Bearer <token>` ヘッダーのいずれかが必要です。

## レスポンス形式

**コレクション形式**（Notes・Tags 共通）:

```json
{ "items": [...], "limit": 20, "offset": 0 }
```

**Note オブジェクト**:

```json
{ "id": 1, "title": "メモのタイトル", "body": "本文" }
```

**Tag オブジェクト**:

```json
{ "id": 1, "name": "backend" }
```

エラーレスポンスは [RFC 9457 Problem Details](./problem-details-types) に従います。
