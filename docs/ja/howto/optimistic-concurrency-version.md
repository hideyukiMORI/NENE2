# ハウツー: 楽観的並行制御（バージョンフィールド）

> **FT リファレンス**: FT323 (`NENE2-FT/optimisticlog`) — PUT ボディにバージョンフィールドを持つドキュメント API、古いバージョンに 409、ロストアップデート防止、18 テスト / 34 アサーション PASS。

このガイドでは、HTTP ETag/If-Match ヘッダーの代替として、リクエストボディに `version` フィールドを渡す楽観的並行制御の実装方法を解説します。

## スキーマ

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/documents` | 作成する（バージョンは 1 から始まる） |
| `GET`  | `/documents` | 一覧表示する |
| `GET`  | `/documents/{id}` | バージョン付きで取得する |
| `PUT`  | `/documents/{id}` | 更新する（ボディにバージョン必須） |

## 作成

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## バージョン付き更新

クライアントは現在の `version` を読み取り、PUT ボディに含めます:

```php
// 読み取り
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// 正しいバージョンで更新
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

バージョンは更新が成功するたびにインクリメントされます。

## 古いバージョン — 409 Conflict

```php
// Alice と Bob の両方がバージョン 1 を読み取る
// Alice が先に更新 → バージョンが 2 になる
// Bob がバージョン 1 で更新しようとする → 拒否される
PUT /documents/1
{"title": "Bob's edit", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob は再読み取りしてバージョン 2 を取得し、再試行する
PUT /documents/1
{"title": "Bob's edit", "version": 2}
→ 200  {"version": 3}
```

## 実装

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

UPDATE クエリの `WHERE version = ?` 句が並行書き込みに対するアトミックなガードです。

## バージョン vs ETag

| 観点 | バージョンフィールド（このガイド） | ETag / If-Match（`optimistic-locking-etag.md` 参照） |
|--------|---------------------------|-----------------------------------------------------|
| プロトコル | ボディフィールド | HTTP ヘッダー |
| クライアント UX | JSON の明示的な `"version": N` | `If-Match: "vN"` ヘッダー |
| 409 ペイロード | `current_version` を返せる | 412 — ボディの標準なし |
| チェック欠落 | 422（`version` なし） | 428（`If-Match` なし） |

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| `version` フィールドなしの PUT を受け付ける | ロストアップデート: 最後の書き込みがサイレントに勝つ |
| 古いバージョンに 200 を返す | 並行変更のサイレントな上書き |
| アプリケーションコードのみでバージョンをチェック（WHERE 句なし） | 読み取りと書き込みの間の競合状態 |
| 409 レスポンスに `current_version` を含めない | クライアントは回復するために再 GET が必要; 高速な再試行のために含める |
