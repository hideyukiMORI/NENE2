# Content Report & Moderation

コンテンツ（記事）の通報・モデレーションシステムの実装ガイド。
RBAC（ロールベースアクセス制御）・IDOR 防止・冪等通報・一方向ステータス遷移を解説する。

## 概要

- ユーザーが記事を通報（冪等: 同じ記事を再通報しても 200）
- モデレーターのみが通報一覧を参照・解決・却下できる
- 通報者は自分の通報のみ参照可能（IDOR 防止）
- ステータスは `pending → resolved / dismissed` の一方向のみ

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/reports` | 記事を通報（冪等） |
| `GET` | `/reports` | 通報一覧（モデレーター専用） |
| `GET` | `/reports/{id}` | 通報詳細（自分の通報 or モデレーター） |
| `PUT` | `/reports/{id}/resolve` | 通報を解決（モデレーター専用） |
| `PUT` | `/reports/{id}/dismiss` | 通報を却下（モデレーター専用） |

## データベース設計

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

`UNIQUE (reporter_id, article_id)` が冪等追加の基盤。
`CHECK` 制約で有効なステータス・通報理由をDB層で保証。

## 冪等通報

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
$report = $this->repository->findReportById($id);

return $this->responseFactory->create($this->formatReport($report ?? []), 201);
```

戻り値 `201` = 新規通報、`200` = 既存通報（呼び出し元がステータスで判別）。

## RBAC — ロールチェック

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

モデレーター専用エンドポイントはハンドラー先頭でロールを検証する。

## IDOR 防止

```php
$isModerator = $actor !== null && $actor['role'] === 'moderator';
$isReporter  = (int) $report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

`GET /reports/{id}` は「自分の通報」か「モデレーター」だけが参照可能。
`reporter_id` はリクエストボディから取得せず、常に `X-User-Id` ヘッダーから設定する。

## ステータス遷移（一方向）

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

`resolved` や `dismissed` に一度遷移した通報は再操作できない。
DB の `CHECK` 制約がアプリ層のバリデーション漏れをバックアップする。

## パスパラメータ取得

NENE2 Router は path params を `nene2.route.parameters` 属性に格納する。

```php
// 正しい取得方法
$id = (int) Router::param($request, 'id');

// NG（直接 getAttribute('id') では取得できない）
$id = (int) $request->getAttribute('id');
```

## reporter_id のセキュリティ

```php
// createReport: actorId は X-User-Id ヘッダーから確定済み
$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
```

`reporter_id` はリクエストボディの `reporter_id` フィールドを無視し、
認証された `X-User-Id` を使用する。これにより他ユーザーへのなりすましを防ぐ。

## POST /reports レスポンス例

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "This article contains repeated spam links",
  "status": "pending",
  "resolved_by": null,
  "resolved_at": null,
  "resolution_note": null,
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## PUT /reports/{id}/resolve レスポンス例

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "...",
  "status": "resolved",
  "resolved_by": 3,
  "resolved_at": "2026-05-21T13:00:00+00:00",
  "resolution_note": "Article removed for TOS violation",
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## GET /reports レスポンス例（モデレーター）

```json
{
  "reports": [
    {
      "id": 2,
      "reporter_id": 2,
      "article_id": 5,
      "reason": "harassment",
      "status": "pending",
      ...
    }
  ],
  "count": 1
}
```
