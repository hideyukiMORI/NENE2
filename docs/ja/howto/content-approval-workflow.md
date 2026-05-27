# ハウツー: コンテンツ承認ワークフロー

> **FT リファレンス**: FT248 (`NENE2-FT/flowlog`) — コンテンツ承認ワークフロー API
> **ATK**: FT248 — クラッカーマインドセット攻撃テスト（ATK-01 から ATK-12）

`PostStatus` `BackedEnum` が `canTransitionTo()` を通じて遷移グラフを所有し、無効な遷移が `InvalidTransitionException → 409` をスローし、却下がオプションの理由を持つ投稿公開ライフサイクルを示します。フルのクラッカーマインドセット攻撃アセスメントを含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|----------------------------|----------------------------------------------------------|
| `POST` | `/posts` | 投稿を作成する（常に `draft` として開始） |
| `GET` | `/posts` | 投稿を一覧表示する（ページネーション付き、ステータスでフィルタリング可能） |
| `GET` | `/posts/{id}` | 単一投稿を取得する |
| `POST` | `/posts/{id}/submit` | 遷移: `draft → submitted` |
| `POST` | `/posts/{id}/approve` | 遷移: `submitted → approved` |
| `POST` | `/posts/{id}/reject` | 遷移: `submitted → rejected`（オプションの理由） |

> **パラメータ化の前に静的アクションルート**: `/posts/{id}/submit`、`/approve`、`/reject` は `/posts/{id}` の前に登録してリテラルサブパスがパラメータ化セグメントにキャプチャされないようにする。

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

`status` には安全網として DB レベルの `CHECK` 制約があります。アプリケーションは書き込み前に `PostStatus::canTransitionTo()` でバリデーションします。`reject_reason` は nullable — 却下時のみ設定されます。

---

## `canTransitionTo()` を持つ `PostStatus` BackedEnum

状態遷移グラフは enum 自体が所有します:

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
            self::Rejected  => false,  // 終端状態
        };
    }
}
```

遷移グラフ:
```
draft → submitted → approved（終端）
                 → rejected（終端）
```

`Approved` と `Rejected` は終端状態 — それ以上の遷移は許可されません。すでに承認された投稿を承認しようとすると `InvalidTransitionException` がスローされます。

---

## リポジトリ遷移メソッド

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

`transition()` メソッドは submit、approve、reject で共有されます — 各ハンドラーは異なる `$targetStatus` で呼び出します。`reject_reason` は approve/submit では `null` で、reject ではオプションで提供されます。

---

## `PostStatus::tryFrom()` を使ったステータスフィルター

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

`BackedEnum::tryFrom()` はスローする代わりに未知の文字列値に `null` を返します。明示的な `null` チェックで有効な値を一覧表示した読みやすいエラーメッセージ付きの構造化 `422` が生成されます。

---

## オプションの理由付き却下

`POST /posts/{id}/reject` はオプションの `reason` フィールドを受け入れます:

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

空のボディ `{}` または `reason` フィールドの欠落は両方とも `null` になります。空白のみの reason 文字列も `trim()` を通じて `null` に正規化されます。理由は nullable の `reject_reason` カラムに保存されます。

---

## ATK — クラッカーマインドセット攻撃テスト（FT248）

### ATK-01 — 認証なし: 誰でも任意の投稿を承認または却下できる

**攻撃**: 認証情報なしで投稿を承認または却下する。

```bash
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/reject
```

**観測結果**: 両方とも `200 OK` で成功する。どの呼び出し元も許可された遷移を通じて任意の投稿を押し進められる。

**判定**: **EXPOSED** — 認証とロールベースの認可を追加してください。指定されたレビュアーのみが承認/却下できるようにしてください。提出には投稿の著者の認証が必要にしてください。

---

### ATK-02 — 無効な状態遷移: ドラフトを承認する

**攻撃**: まだ `draft` ステータスの投稿を承認しようとする。

```bash
curl -X POST http://localhost:8080/posts/1/approve
# 投稿 1 は draft 状態
```

**観測結果**: `canTransitionTo(Approved)` が `Draft` に対して `false` を返す → `InvalidTransitionException` → レスポンスに from/to コンテキストを含む `409 Conflict`。

**判定**: **BLOCKED** — enum が所有する遷移グラフが不正な状態ジャンプを防止する。

---

### ATK-03 — 二重承認: すでに承認済みの投稿を承認する

**攻撃**: 投稿を 2 度目に承認する。

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/approve
curl -X POST http://localhost:8080/posts/1/approve  # 2 回目の承認
```

**観測結果**: 3 番目のリクエスト: `canTransitionTo(Approved)` が `Approved` から → `false` → `409 Conflict`。投稿は `Approved` 状態のまま。

**判定**: **BLOCKED** — `Approved` は終端状態。enum は終端状態からのすべての遷移に明示的に `false` を返す。

---

### ATK-04 — タイトルまたはボディを通じた SQL インジェクション

**攻撃**: SQL メタキャラクターを埋め込む。

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**観測結果**: 値はパラメータ化された `?` プレースホルダーでバインドされる。インジェクションペイロードはリテラルテキストとして保存される。

**判定**: **BLOCKED** — パラメータ化クエリが SQL インジェクションを防止する。

---

### ATK-05 — 無効なステータスフィルター値

**攻撃**: 一覧エンドポイントに未知のステータスを渡す。

```
GET /posts?status=hacked
GET /posts?status=published
```

**観測結果**: `PostStatus::tryFrom('hacked')` が `null` を返す → `ValidationException` → 有効なステータスの一覧付きの `422 Unprocessable Entity`。

**判定**: **BLOCKED** — `BackedEnum::tryFrom()` + 明示的な null チェックで未知のステータス値を拒否する。

---

### ATK-06 — 著者なりすまし

**攻撃**: 特権著者であると主張する投稿を作成する。

```json
{"title": "Official announcement", "author": "admin"}
```

**観測結果**: `201 Created` — `author` フィールドは確認なしでリクエストボディから verbatim に取得される。任意の文字列が受け入れられる。

**判定**: **EXPOSED** — `author` は暗号化バインディングなしでユーザーが提供します。本番では、リクエストボディからではなく認証済みセッション/トークンから `author` を導出してください。

---

### ATK-07 — マスアサインメント: 作成時に `status` をインジェクト

**攻撃**: 作成時に `status` を直接 `approved` に設定する。

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**観測結果**: `createPost()` はボディの `status` フィールドを無視します — 常に `PostStatus::Draft->value` を挿入します。余分なキーはサイレントに破棄されます。

**判定**: **BLOCKED** — コントローラーはハードコードされた `PostStatus::Draft->value` 値で INSERT を構築します。ボディフィールドでオーバーライドできません。

---

### ATK-08 — タイトル、ボディ、または著者の XSS ペイロード

**攻撃**: script タグを保存する。

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**観測結果**: コンテンツはそのまま保存され、JSON で verbatim に返される。API は HTML エンコードされた出力をしない。

**判定**: **設計上 ACCEPTED** — JSON API は生のコンテンツを返します。HTML に挿入する前にレンダリングレイヤーがサニタイズする必要があります。

---

### ATK-09 — 非数値の投稿 ID

**攻撃**: `{id}` として文字列または浮動小数点を使用する。

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**観測結果**: `(int) 'abc'` = `0`、`(int) '1.5'` = `1`。
- `abc` → `findById(0)` → 行なし → `PostNotFoundException` → `404 Not Found`。
- `1.5` → `findById(1)` → 投稿 1 が存在する場合、遷移がトリガーされる。

**判定**: **部分的に BLOCKED** — 非数値文字列は 404 にマップされます。浮動小数点文字列はサイレントに切り捨てられます。厳密な ID バリデーションには `ctype_digit()` を追加してください。

---

### ATK-10 — 空のタイトルまたは空の著者

**攻撃**: 空白フィールドで送信する。

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**観測結果**: `trim($body['title']) === ''` と `trim($body['author']) === ''` のチェックが発動 → `ValidationException` → `422`。

**判定**: **BLOCKED** — trim + 空文字列チェックで空と空白のみの値の両方をカバーする。

---

### ATK-11 — 理由なしの却下（オプション）

**攻撃**: 空のボディまたは `reason` フィールドなしで却下する。

```bash
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject -d '{}'
curl -X POST http://localhost:8080/posts/1/reject -d '{"reason": ""}'
```

**観測結果**: 3 つのケースすべてで `reject_reason` が `null` になる。理由なしの却下は受け入れられます — カラムは nullable です。

**判定**: **設計上 ACCEPTED** — `reject_reason` はオプションです。必須の却下理由が必要な本番ワークフローには `if ($reason === null) → 422` を追加してください。

---

### ATK-12 — 却下済み投稿の二重却下

**攻撃**: すでに却下された投稿を却下しようとする。

```bash
curl -X POST http://localhost:8080/posts/1/submit
curl -X POST http://localhost:8080/posts/1/reject
curl -X POST http://localhost:8080/posts/1/reject  # 2 回目の却下
```

**観測結果**: `canTransitionTo(Rejected)` が `Rejected` から → `false` → `409 Conflict`。

**判定**: **BLOCKED** — `Rejected` は終端状態。enum は終端状態からのすべての遷移に明示的に `false` を返す。

---

## ATK まとめ

| # | 攻撃ベクター | 判定 |
|---|---------------|---------|
| ATK-01 | 承認/却下に認証なし | EXPOSED |
| ATK-02 | 無効な遷移（ドラフトを承認） | BLOCKED |
| ATK-03 | 二重承認 | BLOCKED |
| ATK-04 | タイトル/ボディを通じた SQL インジェクション | BLOCKED |
| ATK-05 | 無効なステータスフィルター値 | BLOCKED |
| ATK-06 | 著者なりすまし | EXPOSED |
| ATK-07 | 作成時のステータスのマスアサインメント | BLOCKED |
| ATK-08 | コンテンツ内の XSS ペイロード | 設計上 ACCEPTED |
| ATK-09 | 非数値の投稿 ID | 部分的に BLOCKED |
| ATK-10 | 空のタイトルまたは空の著者 | BLOCKED |
| ATK-11 | 理由なしの却下（オプション） | 設計上 ACCEPTED |
| ATK-12 | 二重却下 | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01** — 認証とロールベースの認可を追加する（承認/却下にはレビュアーロール）
2. **ATK-06** — 確認されたアイデンティティから `author` を導出する。リクエストボディからは取得しない
3. **ATK-09** — ID パスパラメーターに `ctype_digit()` ガードを追加する

---

## 関連ハウツー

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — 監査履歴と InvalidTransitionException を持つ状態遷移
- [`approval-workflow.md`](approval-workflow.md) — 複数の承認者を持つ承認リクエスト
- [`step-workflow-approval.md`](step-workflow-approval.md) — 順序付きステップを持つマルチステップワークフロー
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — ドラフト/公開ライフサイクルパターン
