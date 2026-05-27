# ハウツー: スケジュールリマインダー API

> **FT リファレンス**: FT235 (`NENE2-FT/reminderlog`) — スケジュールリマインダー API

タイムゾーン対応の将来日時バリデーション、ヘッダーによる軽量なリクエストごとのユーザー識別、オーナーシップスコープクエリによる IDOR 防止、リマインダーキャンセル時の 404/409 の区別を実演します。

---

## ルート

| メソッド | パス | 説明 |
|---------|------|------|
| `POST`  | `/reminders`              | リマインダーを作成する（将来の `remind_at` 必須）          |
| `GET`   | `/reminders`              | 呼び出し元のリマインダーを一覧表示する（ステータスでフィルター可能） |
| `PATCH` | `/reminders/{id}/cancel`  | 保留中のリマインダーをキャンセルする                       |

すべてのルートで `X-User-Id` ヘッダーが必要です。

---

## ヘッダーによる軽量ユーザー識別

Bearer JWT の代わりに、この API は `X-User-Id` 整数ヘッダーを最小限の認証/識別メカニズムとして使用します:

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` はヘッダー値を検証します:

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false — 空文字列は既に拒否される。
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

主要な特性:
- `ctype_digit()` — ReDoS 耐性、`0`、`-1`、`1.5`、`abc`、空文字列を拒否します。
- `strlen > 18` — `(int)` キャスト前のオーバーフローガード（PHP_INT_MAX は 19 桁）。
- `$id > 0` — パース後の整数ゼロを拒否します。

本番環境では JWT またはセッションバリデーションに置き換えてください。`X-User-Id` パターンは、上流のゲートウェイがすでにユーザーを認証してその ID を転送している内部サービスに適しています。

---

## 将来日時バリデーション（タイムゾーン対応）

`remind_at` は、明示的なタイムゾーンオフセット付きの有効な ISO 8601 日時でなければならず、**かつ**現在時刻より厳密に将来でなければなりません:

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` は 2 つのチェックを組み合わせます:

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // ステップ 1: フォーマット + 範囲バリデーション

    if ($dt === null) {
        return null;
    }

    // ステップ 2: タイムゾーン対応の将来チェック
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // オブジェクト比較は UTC に正規化される
}
```

`V::isoDatetime()` は最初にフォーマットチェックを実行します:

```php
public static function isoDatetime(mixed $raw): ?string
{
    // 厳密な正規表現: ±HH:MM オフセットを要求 — 'Z'、日付のみ、オフセットなしを拒否。
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // タイムゾーンオフセット範囲を検証: 有効な UTC オフセットは −14:00 〜 +14:00。
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... オーバーフロー日（2 月 30 日など）のラウンドトリップバリデーション
}
```

`DateTimeImmutable` オブジェクト比較（`>`）は比較前に両辺を UTC に変換します。そのため `2026-06-01T09:00:00+09:00`（00:00 UTC）は `2026-06-01T01:00:00+01:00`（00:00 UTC）と等しいとして正しく比較されます。

---

## IDOR 防止: オーナーシップスコープの検索

特定のリマインダーに触れるすべての操作は `WHERE id = ? AND user_id = ?` を使用します:

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

リマインダーが別のユーザーに属している場合、`findForUser()` は `null` を返します — 呼び出し元は「リマインダーが存在しない」と区別できない `404 Not Found` を受け取ります。`403 Forbidden` を返すと ID が存在することが確認でき、列挙情報が漏洩します。

---

## 404 vs 409: フェッチファーストのキャンセル

キャンセルハンドラーはステータスを確認する前にリマインダーをフェッチします。この 2 ステップのアプローチにより、各失敗モードに対して正しい HTTP ステータスを返せます:

```php
// 最初にフェッチして 404（見つからない/オーナー違い）と 409（ステータス違い）を区別する
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

DB レベルのキャンセルには安全バックストップとしてステータスガードが含まれています:

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

UPDATE の `WHERE status = 'pending'` により、競合状態（2 つの並行キャンセルリクエスト）が発生しても 1 行のみ更新されることが保証されます。

---

## クエリパラメーターバリデーション（`?limit=` と `?status=`）

`limit` は `V::queryInt()` を使用し、キーが存在しない（デフォルトを使用）と無効な値（422 を返す）を区別します:

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20 — キーが存在しないときに返される
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` は `V::enum()` を使用してバックド enum に対してバリデーションします:

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` は内部で `BackedEnum::tryFrom()` を呼び出し、未知の値に対して `null` を返します。

---

## スキーマ

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- タイムゾーンオフセット付き ISO 8601、そのまま保存
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` は送信者のタイムゾーンオフセット付きの元の ISO 8601 文字列として保存されます（例: `2026-06-01T09:00:00+09:00`）。DB は UTC に正規化しません — アプリケーションが正しい比較に責任を持ちます（`V::futureDatetime()` 参照）。

2 つのインデックス:
- `(user_id, id)` — ユーザーごとの一覧とキャンセル検索をカバーする
- `(status, id)` — 実行すべき `pending` リマインダーをフェッチするポーラークエリをカバーする

---

## ステータス enum

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

`pending` のリマインダーのみキャンセルできます（それ以外は `409`）。`triggered` はリマインダーが発火したときにバックグラウンドジョブが設定します — この API にはトリガーエンドポイントは含まれておらず、HTTP サーバー外のスケジュールタスクで実行されます。

---

## 関連 howto

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601 日時バリデーションパターン
- [`content-scheduling.md`](content-scheduling.md) — 将来の `publish_at` によるスケジュール公開
- [`approval-workflow.md`](approval-workflow.md) — ステータス遷移における 404/409 の区別
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防止パターン
