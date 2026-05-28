# How-to: コンテンツ承認ワークフロー

> **FT リファレンス**: FT248 (`NENE2-FT/flowlog`) — Content Approval Workflow API
> **ATK**: FT248 — クラッカー視点の攻撃試験 (ATK-01 から ATK-12)

`PostStatus` の `BackedEnum` が `canTransitionTo()` で遷移グラフを所有し、不正な遷移は
`InvalidTransitionException → 409` を投げ、却下にはオプションの理由を添えられる、
投稿の公開ライフサイクルを示します。クラッカー視点による完全な攻撃評価を含みます。

---

## ルート

| Method | Path                       | 説明                                                       |
|--------|----------------------------|------------------------------------------------------------|
| `POST` | `/posts`                   | 投稿を作成（常に `draft` で開始）                          |
| `GET`  | `/posts`                   | 投稿一覧（ページネーション・ステータスフィルタ可）         |
| `GET`  | `/posts/{id}`              | 単一投稿を取得                                             |
| `POST` | `/posts/{id}/submit`       | 遷移: `draft → submitted`                                  |
| `POST` | `/posts/{id}/approve`      | 遷移: `submitted → approved`                               |
| `POST` | `/posts/{id}/reject`       | 遷移: `submitted → rejected`（理由はオプション）           |

> **静的なアクションルートをパラメーター付きより先に**: `/posts/{id}/submit`、`/approve`、
> `/reject` は `/posts/{id}` より先に登録します。これによりリテラルなサブパスが
> パラメーターセグメントに捕捉されなくなります。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` はセーフティネットとして DB レベルの `CHECK` 制約を持ちますが、アプリケーションは
書き込み前に必ず `PostStatus::canTransitionTo()` で検証します。`reject_reason` は nullable で、
却下時のみ設定されます。

---

## `canTransitionTo()` を持つ `PostStatus` BackedEnum

ステート遷移グラフは enum 自身が所有します。

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // 終端ステート
        };
    }
}
```

遷移グラフ:
```
draft → submitted → approved (終端)
                 → rejected  (終端)
```

`Approved` と `Rejected` は終端ステートで、これ以上の遷移は許可されません。
すでに承認された投稿を再度承認しようとすると `InvalidTransitionException` を投げます。

---

## リポジトリの遷移メソッド

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

`transition()` メソッドは submit / approve / reject で共有され、各ハンドラーが異なる
`$targetStatus` で呼び出します。`reject_reason` は approve / submit では `null` で、
reject ではオプションで渡されます。

---

## `PostStatus::tryFrom()` によるステータスフィルタ

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` は未知の文字列値に対して例外を投げる代わりに `null` を返します。
明示的な `null` チェックで構造化された `422` を生成し、読みやすいエラーメッセージで
有効な値の一覧を返します。

---

## オプション理由付きの却下

`POST /posts/{id}/reject` はオプションの `reason` フィールドを受け付けます。

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

空のボディ `{}` や `reason` フィールドの欠落はどちらも `null` になります。空白のみの
理由文字列も `trim()` により `null` に正規化されます。理由は nullable な
`reject_reason` カラムに格納されます。

---

## ATK — クラッカー視点の攻撃試験 (FT248)

### ATK-01 — 認証なし: 誰でも任意の投稿を承認・却下できる

**Attack**: 認証情報なしで投稿を承認または却下する。

```bash
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/reject
```

**Observed**: どちらも `200 OK` で成功する。任意の呼び出し元が任意の投稿を
任意の許可された遷移を通せる。

**Verdict**: **EXPOSED** — 認証とロールベース認可を追加すべき。承認・却下できるのは
指定されたレビュアーのみとすべき。submit には投稿の著者が認証されていることを
要求すべき。

---

### ATK-02 — 不正なステート遷移: draft を approve

**Attack**: まだ `draft` ステータスの投稿を承認しようとする。

```bash
curl -X POST http://localhost:8080/posts/1/approve
# 投稿 1 は draft
```

**Observed**: `Draft` に対する `canTransitionTo(Approved)` は `false` を返す
→ `InvalidTransitionException` → from/to のコンテキストを添えた `409 Conflict`。

**Verdict**: **BLOCKED** — enum 所有の遷移グラフが不正なステートジャンプを防ぐ。

---

### ATK-03 — 二重承認: すでに承認された投稿を再度承認

**Attack**: 投稿を 2 度承認する。

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/approve  # 2 回目の approve
```

**Observed**: 3 回目のリクエスト: `Approved` からの `canTransitionTo(Approved)` は `false`
→ `409 Conflict`。投稿は `Approved` ステートのまま。

**Verdict**: **BLOCKED** — `Approved` は終端ステートで、enum は終端ステートからの
すべての遷移に対して明示的に `false` を返す。

---

### ATK-04 — title または body 経由の SQL インジェクション

**Attack**: SQL メタ文字を埋め込む。

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**Observed**: 値はパラメーター化された `?` プレースホルダー経由でバインドされる。
インジェクションペイロードはリテラルなテキストとして保存される。

**Verdict**: **BLOCKED** — パラメーター化クエリが SQL インジェクションを防ぐ。

---

### ATK-05 — 不正なステータスフィルタ値

**Attack**: 一覧エンドポイントに未知のステータスを渡す。

```
GET /posts?status=hacked
GET /posts?status=published
```

**Observed**: `PostStatus::tryFrom('hacked')` は `null` を返す → `ValidationException`
→ 有効なステータス一覧を添えた `422 Unprocessable Entity`。

**Verdict**: **BLOCKED** — `BackedEnum::tryFrom()` と明示的な null チェックが
未知のステータス値を拒否する。

---

### ATK-06 — 著者なりすまし

**Attack**: 特権を持つ著者であると主張して投稿を作成する。

```json
{"title": "Official announcement", "author": "admin"}
```

**Observed**: `201 Created` — `author` フィールドは検証なしでリクエストボディから
そのまま取られる。任意の文字列が受け入れられる。

**Verdict**: **EXPOSED** — `author` は暗号学的なバインディングなしでユーザー指定。
本番では `author` を認証済みのセッション/トークンから導出すべきで、リクエストボディ
から取ってはいけない。

---

### ATK-07 — マスアサインメント: 作成時に `status` を注入

**Attack**: 作成時に `status` を直接 `approved` に設定する。

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**Observed**: `createPost()` はボディの `status` フィールドを無視する — 常に
`PostStatus::Draft->value` を INSERT する。余分なキーは静かに破棄される。

**Verdict**: **BLOCKED** — コントローラーはハードコードされた
`PostStatus::Draft->value` 値で INSERT を組み立てる。どのボディフィールドも
これを上書きできない。

---

### ATK-08 — title / body / author への XSS ペイロード

**Attack**: script タグを保存する。

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**Observed**: コンテンツはそのまま保存され、JSON 内でそのまま返される。API は出力を
HTML エンコードしない。

**Verdict**: **ACCEPTED BY DESIGN** — JSON API は生のコンテンツを返す。レンダリング層が
HTML に挿入する前にサニタイズすべき。

---

### ATK-09 — 数値でない投稿 ID

**Attack**: `{id}` に文字列または float を使う。

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**Observed**: `(int) 'abc'` = `0`、`(int) '1.5'` = `1`。
- `abc` → `findById(0)` → 行なし → `PostNotFoundException` → `404 Not Found`。
- `1.5` → `findById(1)` → 投稿 1 が存在すれば、その遷移がトリガーされる。

**Verdict**: **PARTIALLY BLOCKED** — 数値でない文字列は 404 にマップされる。Float 文字列は
静かに切り詰められる。厳格な ID 検証のために `ctype_digit()` を追加すべき。

---

### ATK-10 — 空の title または空の author

**Attack**: 空欄のフィールドで submit する。

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**Observed**: `trim($body['title']) === ''` と `trim($body['author']) === ''` の
チェックが発火 → `ValidationException` → `422`。

**Verdict**: **BLOCKED** — trim と空文字チェックが空文字と空白のみの値の両方を
カバーする。

---

### ATK-11 — 理由を指定せずに reject

**Attack**: 空ボディまたは `reason` フィールドなしで却下する。

```bash
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject -d '{}'
curl -X POST http://localhost:8080/posts/1/reject -d '{"reason": ""}'
```

**Observed**: 3 ケースすべてで `reject_reason` は `null` になる。理由なしの却下は
受け入れられる — カラムは nullable。

**Verdict**: **ACCEPTED BY DESIGN** — `reject_reason` はオプション。理由必須の本番
ワークフローでは `if ($reason === null) → 422` を追加すべき。

---

### ATK-12 — 却下済みの投稿を reject（二重却下）

**Attack**: すでに却下された投稿を再度却下しようとする。

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject  # 2 回目の reject
```

**Observed**: `Rejected` からの `canTransitionTo(Rejected)` は `false` を返す
→ `409 Conflict`。

**Verdict**: **BLOCKED** — `Rejected` は終端ステートで、enum は終端ステートからの
すべての遷移に対して明示的に `false` を返す。

---

## ATK サマリ

| # | 攻撃ベクトル | Verdict |
|---|---------------|---------|
| ATK-01 | approve/reject に認証なし | EXPOSED |
| ATK-02 | 不正な遷移（draft を approve） | BLOCKED |
| ATK-03 | 二重承認 | BLOCKED |
| ATK-04 | title/body 経由の SQL インジェクション | BLOCKED |
| ATK-05 | 不正なステータスフィルタ値 | BLOCKED |
| ATK-06 | 著者なりすまし | EXPOSED |
| ATK-07 | 作成時の status マスアサインメント | BLOCKED |
| ATK-08 | コンテンツへの XSS ペイロード | ACCEPTED BY DESIGN |
| ATK-09 | 数値でない投稿 ID | PARTIALLY BLOCKED |
| ATK-10 | 空の title または空の author | BLOCKED |
| ATK-11 | 理由なしの reject（オプション） | ACCEPTED BY DESIGN |
| ATK-12 | 二重却下 | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01** — 認証とロールベース認可を追加（approve/reject に reviewer ロール）
2. **ATK-06** — `author` を検証済みアイデンティティから導出し、リクエストボディから取らない
3. **ATK-09** — ID パスパラメーターに `ctype_digit()` ガードを追加

---

## 関連 howto

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — 監査履歴付きのステート遷移と InvalidTransitionException
- [`approval-workflow.md`](approval-workflow.md) — 複数承認者による承認リクエスト
- [`step-workflow-approval.md`](step-workflow-approval.md) — 順序付きステップを持つマルチステップワークフロー
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — draft/publish ライフサイクルパターン
