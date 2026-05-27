# ハウツー: ネストされたリソースを使ったプロジェクト・タスク管理

> **FT リファレンス**: FT241 (`NENE2-FT/projtrack`) — プロジェクト・タスク管理 API

タスクがプロジェクトに属する 2 レベルのネストされたリソース API を実演します。
親存在バリデーション、`array_key_exists()` を使った選択的 PATCH 更新、CHECK 制約を使ったステータス許可リスト、整数としての優先度、DELETE レスポンスでの `204 No Content` を含みます。

---

## ルート

| メソッド | パス | 説明 |
|----------|---------------------------------------|------------------------------------------------------|
| `GET` | `/projects` | プロジェクト一覧（ページネーション） |
| `POST` | `/projects` | プロジェクトを作成する |
| `GET` | `/projects/{id}` | 単一プロジェクトを取得する |
| `DELETE` | `/projects/{id}` | プロジェクトを削除する（タスクにカスケード） |
| `GET` | `/projects/{projectId}/tasks` | プロジェクトのタスク一覧（ページネーション、フィルタリング可） |
| `POST` | `/projects/{projectId}/tasks` | プロジェクト内にタスクを作成する |
| `GET` | `/projects/{projectId}/tasks/{taskId}` | 単一タスクを取得する |
| `PATCH` | `/projects/{projectId}/tasks/{taskId}` | タスクを選択的に更新する（省略フィールドは保持） |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}` | タスクを削除する（`204 No Content`） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'done')),
    priority    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`status` は `CHECK(status IN (...))` で DB レベルにも制約があり、無効な値が紛れ込まないセーフティネットとなっています。`ON DELETE CASCADE` はプロジェクトを削除すると全タスクが自動削除されることを意味します。`priority` のデフォルトは `0` で、高い値が先にソートされます。

---

## ネストされたリソース: 親存在バリデーション

すべてのタスク操作は、タスクに触れる**前**に親プロジェクトが存在することを検証します。プロジェクト ID が不明な場合、`ProjectNotFoundException` が即座にスローされます:

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // プロジェクトの存在を確認（ProjectNotFoundException → 404 をスロー）
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` は `404 Not Found` にマップする例外ハンドラーとして登録されています。つまり、プロジェクト 99 が存在しない場合に `/projects/99/tasks` は `404` を返します — 存在しないタスクと同じステータスです。呼び出し元は Problem Details の `detail` フィールドを読まないと「プロジェクト欠如」と「タスク欠如」を区別できません。

タスクリポジトリも SQL レベルでプロジェクトスコープを強制します:

```php
public function findByProjectAndId(int $projectId, int $taskId): Task
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND project_id = ?',
        [$taskId, $projectId],
    );
    if ($row === null) {
        throw new TaskNotFoundException($projectId, $taskId);
    }
    return $this->hydrate($row);
}
```

`WHERE id = ? AND project_id = ?` はクロスプロジェクトアクセスを防ぎます — プロジェクト 1 のタスク 5 は `/projects/2/tasks/5` では取得できません（タスク 5 が存在しても）。

---

## PATCH: `array_key_exists()` を使った選択的フィールド更新

`PATCH /projects/{projectId}/tasks/{taskId}` は `title`、`status`、`priority` の任意のサブセットを受け付けます。リクエストボディに存在しないフィールドは保持されます。

`isset()` は「キーが存在しない」と「キーが存在して null」を区別できません。PATCH セマンティクスには `array_key_exists()` が正しいツールです:

```php
$title    = null;
$status   = null;
$priority = null;

if (array_key_exists('title', $body)) {
    if (!is_string($body['title']) || trim($body['title']) === '') {
        $errors[] = new ValidationError('title', 'title must be a non-empty string.', 'invalid_value');
    } else {
        $title = trim($body['title']);
    }
}

if (array_key_exists('status', $body)) {
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
        $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
    } else {
        $status = $body['status'];
    }
}

if (array_key_exists('priority', $body)) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

キーが存在しない場合、`$title`、`$status`、`$priority` は `null` のままです。リポジトリは `null` を「変更しない」と解釈します:

```php
public function update(int $projectId, int $taskId, ?string $title, ?string $status, ?int $priority, string $now): Task
{
    $existing    = $this->findByProjectAndId($projectId, $taskId);
    $newTitle    = $title    ?? $existing->title;
    $newStatus   = $status   ?? $existing->status;
    $newPriority = $priority ?? $existing->priority;

    $this->executor->execute(
        'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
        [$newTitle, $newStatus, $newPriority, $now, $taskId, $projectId],
    );

    return $this->findByProjectAndId($projectId, $taskId);
}
```

null 合体演算子 `??` が提供された値と既存レコードをマージします。単一の `UPDATE` が常に実行されます（動的カラムリスト不要）— フィールドが省略された場合は単に既存の値に置き換わります。

---

## priority の `is_int()`: JSON からの浮動小数点数と文字列を拒否する

JSON の `1` は PHP の `int` としてデコードされますが、`1.0` は `float`、`"1"` は `string` としてデコードされます。`is_int()` は整数形式のみを受け付けます:

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` は `"1"` と `1.0` を通過させます — 厳密な整数のみのバリデーションには `is_int()` を使ってください。注意: `priority` は作成時にオプション（デフォルト `0`）; PATCH では同じチェックが `array_key_exists('priority', $body)` ブロック内に適用されます。

---

## ステータス許可リストバリデーション

ステータスは DB に到達する前に明示的な許可リストに対して検証されます:

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` — 厳密比較 — 値が許可された状態の 1 つと等しい文字列であることを確認します。DB の `CHECK` 制約が第 2 の防御層を提供しますが、アプリケーションレベルのチェックにより生の DB エラーではなく意味のあるエラーメッセージを持つ構造化された `422` が得られます。

---

## タスク一覧のステータスフィルター

`GET /projects/{projectId}/tasks?status=open` はステータスでタスクをフィルタリングします。クエリ文字列は `QueryStringParser::string()` で読み取られます:

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` はパラメーターが存在しない場合 `null` を返します — フィルターは適用されません。無効な値はリストを黙って空で返すのではなく `422 Unprocessable Entity` を返します。

リポジトリは WHERE 句を動的に構築します:

```php
public function findByProject(int $projectId, ?string $status = null, int $limit = 20, int $offset = 0): array
{
    $where  = ['project_id = ?'];
    $params = [$projectId];

    if ($status !== null) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT * FROM tasks WHERE ' . implode(' AND ', $where)
        . ' ORDER BY priority DESC, created_at ASC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    return array_map($this->hydrate(...), $this->executor->fetchAll($sql, $params));
}
```

タスクは `priority DESC`（高優先度が先）でソートされ、同じ優先度内では `created_at ASC`（古いタスクが先）でソートされます。

---

## DELETE の `204 No Content`

DELETE レスポンスはボディを持ちません。`JsonResponseFactory::createEmpty(204)` が `204 No Content` レスポンスを生成します:

```php
private function deleteTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId);
    $this->tasks->delete($projectId, $taskId);

    return $this->json->createEmpty(204);
}
```

タスクリポジトリは削除前に存在を検証します:

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // 存在しない場合は TaskNotFoundException をスロー
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

タスクが存在しない（または別のプロジェクトに属する）場合、DELETE が実行される前に `TaskNotFoundException` がスローされ → `404 Not Found` になります。

---

## 使用した NENE2 ビルトイン

| ビルトイン | 目的 |
|---|---|
| `PaginationQueryParser::parse()` | 安全なデフォルト付きで `?limit=` と `?offset=` を読み取る |
| `PaginationResponse` | `{ items, total, limit, offset }` エンベロープを生成する |
| `ValidationException` / `ValidationError` | `errors` 配列付きの構造化された `422` |
| `QueryStringParser::string()` | 名前付きクエリ文字列パラメーターを読み取り、存在しない場合は `null` を返す |
| `JsonRequestBodyParser::parse()` | JSON ボディをデコードする |
| `JsonResponseFactory::create()` | JSON レスポンスをエンコードする |
| `JsonResponseFactory::createEmpty()` | ボディなしレスポンスを生成する（例: `204`） |
| `Router::PARAMETERS_ATTRIBUTE` | リクエストからパスパラメーターを取得する |

---

## 関連ハウツー

- [`note-management-ownership.md`](note-management-ownership.md) — `WHERE id = ? AND owner_id = ?` を使った IDOR 防止
- [`contact-management.md`](contact-management.md) — 多対多アソシエーション、検索フィルタリング
- [`document-versioning.md`](document-versioning.md) — `is_current` フラグを使った追記のみのバージョニング
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId() ヘッダーバリデーションパターン
