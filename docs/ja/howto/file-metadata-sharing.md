# ファイルメタデータ管理・共有 API の実装ガイド

## 概要

このガイドでは NENE2 を使ってファイルメタデータ管理 API を実装する方法を説明します。
実際のファイル保存は行わず、メタデータ（名前・サイズ・MIME タイプ・説明・公開設定）を管理し、
ユーザー間での共有（view/edit 権限）をサポートします。

---

## DB スキーマ

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

**設計ポイント**

- `visibility CHECK (visibility IN ('private', 'public'))` — DB レベルで有効値を制約
- `can_edit CHECK (can_edit IN (0, 1))` — SQLite の boolean は INTEGER 0/1
- `UNIQUE (file_id, shared_with_user_id)` — 同一ユーザーへの二重共有を防止

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| `GET` | `/files` | アクセス可能ファイル一覧（自分のもの + 共有されたもの） |
| `POST` | `/files` | ファイルメタデータ作成 |
| `GET` | `/files/{fileId}` | ファイル取得（所有者・公開・共有先のみ） |
| `PUT` | `/files/{fileId}` | 更新（所有者 or edit 共有） |
| `DELETE` | `/files/{fileId}` | 削除（所有者のみ） |
| `POST` | `/files/{fileId}/shares` | ユーザーと共有 |
| `DELETE` | `/files/{fileId}/shares/{userId}` | 共有解除（所有者のみ） |

---

## アクセス制御設計

### 3段階のアクセスレベル

```
所有者 (user_id = X-User-Id)
  → 全操作可能
  
edit 共有 (file_shares.can_edit = 1)
  → GET / PUT 可能
  → visibility 変更不可（所有者のみ）
  → DELETE 不可
  
view 共有 (file_shares.can_edit = 0) または public ファイル
  → GET のみ可能
```

### 存在非公開（IDOR 防止）

他ユーザーのプライベートファイルには **404** を返す（403 ではない）。
403 は「ファイルは存在するがアクセス権がない」を暗示し、ID 推測攻撃を助長するため。

```php
if ((int) $file['user_id'] !== $userId) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->json->create(['error' => 'File not found'], 404); // 403 ではなく 404
    }
}
```

---

## アクセス可能ファイル一覧クエリ

```php
return $this->db->fetchAll(
    'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
            f.visibility, f.created_at, f.updated_at,
            u.name AS owner_name,
            CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
            CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
     FROM files f
     JOIN users u ON u.id = f.user_id
     LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
     WHERE f.user_id = ? OR fs.shared_with_user_id = ?
     ORDER BY f.created_at DESC, f.id DESC',
    [$userId, $userId, $userId, $userId, $userId]
);
```

- `LEFT JOIN` で共有テーブルを結合し、`WHERE` で「自分のもの OR 共有されたもの」を取得
- 公開ファイルはリストに含めない（閲覧は GET で個別に可能）
- `CASE WHEN` で所有者フラグと編集権限を計算

---

## visibility エスカレーション防止

edit 権限の共有者でも `visibility` は変更できない。所有者のみが変更できる。

```php
// Only owner can change visibility
if ($ownerId !== $userId) {
    $visibility = (string) $file['visibility']; // Override with current value
}

$this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
```

---

## ファイル削除時の共有エントリのクリーンアップ

```php
public function delete(int $id): void
{
    $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
    $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
}
```

FK 制約があるため、`file_shares` を先に削除してから `files` を削除する。

---

## バリデーション設計

```php
// name: 必須、255文字以内
if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
    $errors[] = new ValidationError('name', 'name is required', 'required');
} elseif (mb_strlen($body['name']) > 255) {
    $errors[] = new ValidationError('name', 'name is too long', 'too_long');
}

// size: 整数型必須、0以上
if (!isset($body['size']) || !is_int($body['size'])) {
    $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
}

// visibility: 列挙値チェック
if (!in_array($body['visibility'], ['private', 'public'], true)) {
    $errors[] = new ValidationError('visibility', 'visibility must be private or public', 'invalid_value');
}
```

---

## 脆弱性診断結果（FT156）

| ID | 脆弱性 | 結果 |
|---|---|---|
| VULN-A | IDOR: 他ユーザーのプライベートファイルへの直接アクセス | Pass（404 を返す） |
| VULN-B | IDOR: 他ユーザーのファイル削除 | Pass（404 を返す） |
| VULN-C | IDOR: 他ユーザーのファイル更新 | Pass（404 を返す） |
| VULN-D | 権限昇格: view 共有者が edit 操作 | Pass（403 を返す） |
| VULN-E | 所有権インジェクション: body の user_id | Pass（無視される） |
| VULN-F | 共有削除なりすまし: 共有相手が自分の共有を削除 | Pass（404 を返す） |
| VULN-G | SQL インジェクション: ファイル名 | Pass（パラメータ化クエリ） |
| VULN-H | 長すぎる name: 300文字 | Pass（422 を返す） |
| VULN-I | 型混乱: size にフロート | Pass（422 を返す） |
| VULN-J | 可視性エスカレーション: 編集共有者が visibility 変更 | Pass（無視される） |
| VULN-K | 存在推測: 403 vs 404 | Pass（404 を返す） |
| VULN-L | 認証バイパス: X-User-Id=0 / 負値 | Pass（401 を返す） |

---

## クラッカー攻撃試験結果（FT156）

| ID | 攻撃シナリオ | 結果 |
|---|---|---|
| ATK-01 | なりすまし: 他ユーザーのファイルを GET | Pass（404 を返す） |
| ATK-02 | なりすまし: 他ユーザーのファイルを DELETE | Pass（404 を返す） |
| ATK-03 | view 共有者が PUT で編集試行 | Pass（403 を返す） |
| ATK-04 | body に user_id を注入してオーナー偽装 | Pass（無視される） |
| ATK-05 | パストラバーサル: `../../etc/passwd` | Pass（404 を返す） |
| ATK-06 | 文字列 ID でアクセス試行 | Pass（404 を返す） |
| ATK-07 | X-User-Id ヘッダーを空文字で送信 | Pass（401 を返す） |
| ATK-08 | SQL インジェクション: mime_type フィールド | Pass（パラメータ化クエリ） |
| ATK-09 | 超長 description（10000文字）送信 | Pass（保存されるが切り捨てなし・255超 name は 422） |
| ATK-10 | edit 共有者が visibility を public に昇格 | Pass（無視される） |
| ATK-11 | 共有相手が自分への共有を削除試行 | Pass（404 を返す） |
| ATK-12 | 存在プロービング: 他人のファイル ID を推測 | Pass（404 を返す） |

---

## テストのポイント

```php
// 他ユーザーのプライベートファイルは 404（403 ではない）
$res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
$this->assertSame(404, $res->getStatusCode());

// 編集共有者は visibility を変更できない
$this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
    'name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'public',
]);
$check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
$this->assertSame('private', $this->json($check)['visibility']);

// body の user_id は無視される（X-User-Id から取得）
$res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'test.txt', 'size' => 1, 'mime_type' => 'text/plain', 'user_id' => 2]);
$this->assertSame(1, $this->json($res)['user_id']);
```
