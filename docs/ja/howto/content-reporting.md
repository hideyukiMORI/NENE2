# ハウツー: コンテンツ通報システム

> **FT リファレンス**: FT289 (`NENE2-FT/reportlog`) — コンテンツ通報: 許可リスト付き理由（ReportReason enum）、UNIQUE(reporter_id, article_id) による重複時の冪等 200、pending→resolved/dismissed ステートマシン、モデレーター専用の一覧/解決/却下、DB レベルの CHECK 制約、32 テスト / 58 アサーション PASS。

このガイドでは、ユーザーがコンテンツをフラグ付けし、モデレーターが通報をレビューして解決するコンテンツ通報システムの構築方法を示します。

## スキーマ

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

DB レベルの `CHECK` 制約により、アプリケーションのバリデーションが回避されても enum 値が強制されます。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/reports` | `X-User-Id` | 通報を送信する |
| `GET` | `/reports` | モデレーター | 全通報を一覧表示する |
| `GET` | `/reports/{id}` | 通報者またはモデレーター | 通報を取得する |
| `PUT` | `/reports/{id}/resolve` | モデレーター | 通報を解決する |
| `PUT` | `/reports/{id}/dismiss` | モデレーター | 通報を却下する |

## ReportReason Enum

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` は未知の値を拒否します。ハンドラーはエラーレスポンスに有効な理由を含めます:

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## 冪等な通報送信

ユーザーが同じ記事をすでに通報済みの場合、既存の通報を 201 ではなく 200 で返します:

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// 初回: 201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` が DB レベルでこれをバックアップします。アプリケーションは最初にチェックしてフレンドリーなレスポンスを返しますが、UNIQUE 制約がセーフティネットとなります。

## ステータスライフサイクル

```
pending ──→ resolved（モデレーターのアクション）
       └──→ dismissed（モデレーターのアクション）
```

一度 resolved または dismissed になった通報は遷移できません。pending でない通報を変更しようとすると 422 を返します:

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## モデレーターロールチェック

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

ロールは `users` テーブルに保存され、すべての特権操作でチェックされます。DB レベルの `CHECK (role IN ('user', 'moderator'))` が無効なロールの挿入を防止します。

## アクセス制御: 通報者 vs モデレーター

GET `/reports/{id}` は元の通報者とモデレーターの両方がアクセスできます:

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

通報者はステータスを追跡するために自分の通報を参照できます。モデレーターはすべての通報を参照できます。

## 監査証跡付きの解決

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by`（モデレーター ID）、`resolved_at`（タイムスタンプ）、`resolution_note`（オプション）がすべてのモデレーションアクションの監査証跡を作成します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 自由形式の理由文字列を受け入れる | タイポ・インジェクション・無限のカテゴリ: enum 許可リストを使用する |
| `UNIQUE(reporter_id, article_id)` なし | 同じユーザーが同じ記事に大量の通報を送信できる。キューが肥大化する |
| 重複通報に 409 を返す | リトライセーフな冪等性: 重複 → エラーではなく既存通報で 200 |
| resolved/dismissed からの遷移を許可する | 解決済み通報が再オープンされる。監査証跡が信頼できなくなる |
| 一覧/解決のモデレーターロールチェックなし | 任意のユーザーが全通報を読む。プライバシー侵害 + 監査バイパス |
| 他のユーザーに通報者自身の通報を返す | IDOR — 常に通報者 === アクターまたはアクターがモデレーターかチェックする |
| `resolution_note` フィールドなし | モデレーターが通報が dismissed か resolved かの理由を伝えられない |
| `resolved_by` フィールドなし | どのモデレーターがアクションを取ったかを監査できない |
| DB CHECK のみでアプリバリデーションなし | 無効な理由で DB が例外をスロー: ユーザーは 422 の代わりに 500 を受け取る |
