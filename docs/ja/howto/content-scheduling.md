# コンテンツスケジューリング — ライフサイクル状態を持つ時刻ベース公開

`publish_at` カラム、ステートマシン（`draft → scheduled → published → archived`）、および期日になった記事を公開するために cron ジョブが呼び出す**公開トリガー**エンドポイントを使って、コンテンツを将来の日時に公開するようスケジュールします。

**参照実装:** `FT172 pubschedulelog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## ステータスライフサイクル

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        （また: scheduled → draft（unschedule 経由））
```

| 現在 | 許可される遷移 |
|---|---|
| `draft` | `scheduled`、`published`、`archived` |
| `scheduled` | `published`、`draft`、`archived` |
| `published` | `archived` |
| `archived` | _（なし）_ |

---

## スキーマ

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601; スケジュール時に設定; それ以外は NULL
    published_at TEXT,    -- 実際に公開された時に設定
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/articles` | X-User-Id | ドラフトを作成する |
| `GET` | `/articles` | オプション | 一覧表示（`?status=published` はパブリック; 他のステータスは認証 + 自分の記事のみ） |
| `GET` | `/articles/{id}` | オプション | 記事を取得する（published = パブリック、draft/scheduled = オーナーのみ） |
| `PUT` | `/articles/{id}` | X-User-Id | title/body を更新する（draft または scheduled のみ） |
| `POST` | `/articles/{id}/schedule` | X-User-Id | `publish_at` を設定 → `scheduled` に移行 |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | スケジュールをキャンセル → `draft` に戻す |
| `POST` | `/articles/{id}/publish` | X-User-Id | 即座に公開する |
| `POST` | `/articles/{id}/archive` | X-User-Id | アーカイブする |
| `POST` | `/articles/publish-due` | X-Admin-Key | 期日になった全 scheduled 記事を一括公開する |

---

## コアパターン

### 遷移ガード付きステータス Enum

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### スケジュール: 未来の日時のみバリデーション

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### 公開デュートリガー（cron セーフ、冪等）

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

cron ジョブから毎分呼び出します。冪等: `publish_at` は公開時に `NULL` にクリアされるため、すぐに再実行しても新たな期日の記事は見つかりません。

### IDOR 防止

ドラフトとスケジュール済みの記事は**オーナーのみ**です — 存在を漏洩しないよう 403 ではなく 404 を返します:

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404、403 ではない
}
```

### 管理キー — タイミングセーフな比較

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

シークレットの比較には `!==` を使わず — タイミング攻撃を防ぐために `hash_equals()` を使用します。

---

## セキュリティノート

| リスク | 軽減策 |
|---|---|
| 過去の `publish_at` インジェクション | `strtotime($publishAt) <= strtotime($now)` → 422 |
| クロスユーザーの状態変更 | 全遷移前にオーナーシップチェック; 404（403 ではない） |
| ボディ経由の著者 ID インジェクション | `authorId` は `X-User-Id` ヘッダーからのみ取得 |
| ボディ経由のステータスインジェクション | PUT ボディの `status` フィールドは無視; 遷移は専用アクションエンドポイント経由 |
| 管理キーへのタイミング攻撃 | `!==` の代わりに `hash_equals()` を使用 |
| 未公開記事の列挙 | パブリック一覧は常に `status = published` でフィルター; 非公開は認証 + 自分の記事のみ |
| 公開後の編集 | PUT は非 draft/scheduled 記事を 422 で拒否 |
| 二重アーカイブ | 遷移ガードが無効な遷移に 409 を返す |

---

## Cron インテグレーション

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

大量ワークロードの場合は、ジョブキュー（[job-queue.md](./job-queue.md) 参照）に移行し、キューワーカーから `publishDue()` を呼び出してください。

---

## 関連ハウツー

- [コンテンツドラフトライフサイクル](./content-draft-lifecycle.md) — スケジューリングなしの draft/active/archived
- [ジョブキュー](./job-queue.md) — 大量公開トリガーのバックグラウンド処理
- [ソフトデリート](./soft-delete.md) — アーカイブの補完
- [監査証跡](./audit-trail.md) — 誰が何をいつ公開したかの記録
