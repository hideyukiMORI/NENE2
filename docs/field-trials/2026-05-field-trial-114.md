# Field Trial 114 — Audit Trail Pattern

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/auditlog/`
**NENE2 version:** 1.5.47
**Theme:** 監査ログ（Audit Trail）— 誰がいつ何を変更したかの記録・before/after スナップショット・監査レコードの immutability・JWT クレームからのアクター取得・所有権チェックの組み合わせ。

---

## What was built

タスク管理 API に全操作の監査ログを自動記録する実装。

- `POST /auth/login` — JWT 発行
- `GET /tasks` — 自分のタスク一覧（actor_id フィルタ済み）
- `POST /tasks` — タスク作成（監査: created + スナップショット）
- `PUT /tasks/{id}` — タスク更新（監査: updated + before/after）
- `DELETE /tasks/{id}` — タスク削除（監査: deleted + 削除前スナップショット）
- `GET /audit` — 監査ログ一覧（actor_id / action / resource_type でフィルタ）
- `GET /audit/{resource_type}/{resource_id}` — 特定リソースの変更履歴

---

## Findings

### 1. 監査はハンドラレイヤーで記録する（UseCase 相当）

監査をどのレイヤーに置くかは設計の核心。Repository に入れると「何のビジネス操作」か分からなくなる。Middleware に入れるとルートに強く依存する。ハンドラ（UseCase 相当）に入れることで、ビジネス操作の意味と監査が対応する:

```php
$task = $this->tasks->create($title, $text, $actorId);

// Audit: record creation — do not include actor_id in payload (redundant)
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

更新操作では before/after 両方をスナップショットとして記録することで、差分追跡が容易になる:

```php
$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

---

### 2. 監査レコードは immutable にする（UPDATE/DELETE API を作らない）

監査ログが改ざんできたら監査の意味がない。`AuditLog/RouteRegistrar` は GET のみ登録し、書き込みエンドポイントを公開しない:

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST/PUT/DELETE は意図的に登録しない
}
```

テーブル設計でも ON DELETE CASCADE を排除し、主体レコード（tasks）が削除されても監査ログが残るように FK を貼らない:

```sql
-- No FK constraints: audit records must survive their subjects
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    ...
);
```

---

### 3. アクターは JWT クレームから取得する（リクエストボディを信頼しない）

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` は `BearerTokenMiddleware` が設定した検証済みクレーム。リクエストボディの `actor_id` フィールドを参照しない。

---

### 4. ペイロードに機密フィールドを含めない

audit payload には「誰が何を変えたか」の事実のみ。`actor_id` は監査レコード自体に記録されるため payload に含めない。`password_hash` などの機密フィールドは絶対に含めない:

```php
// ❌ actor_id を payload に含めると冗長かつ情報漏洩リスク
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'actor_id' => $actorId,  // 不要
    ...
]);

// ✅ payload はビジネス上の属性のみ
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

---

### 5. ORDER BY id DESC — occurred_at は秒精度で衝突する

監査ログを `ORDER BY occurred_at DESC` のみでソートすると、同一秒内の操作順序が不定になる。`id` は自動インクリメントで挿入順序を保証するため、`ORDER BY id DESC` が確実:

```sql
-- ❌ 同秒操作で順序不定
SELECT * FROM audit_log WHERE resource_type = ? AND resource_id = ?
ORDER BY occurred_at DESC LIMIT ?

-- ✅ id で挿入順序を保証
SELECT * FROM audit_log WHERE resource_type = ? AND resource_id = ?
ORDER BY id DESC LIMIT ?
```

---

## Security Assessment（FT114 脆弱性診断 — 3サイクル目）

### 発見・修正した問題

| 重大度 | 問題 | 修正 |
|---|---|---|
| Critical | ダミーハッシュが不正形式 → KDF をスキップ → タイミング攻撃でユーザー列挙可能 | 正規 Argon2id ハッシュに置き換え |
| High | タスクの所有権チェックなし → 他ユーザーのタスクを誰でも更新・削除可能 | `actorId` チェックを update/delete に追加 |
| High | `GET /tasks` が全ユーザーのタスクを返す | `findByActor()` で actor_id フィルタ |
| Medium | `limit=-1` が SQLite で全件返却になる | `max(1, min(..., 100))` でサニタイズ |

### 確認済み — 問題なし

| 項目 | 根拠 |
|---|---|
| SQL インジェクション | 全クエリ `?` プレースホルダバインド |
| JWT alg 検証 | `alg: none` を拒否 |
| 監査ログの改ざん API | GET のみ登録。UPDATE/DELETE エンドポイントなし |
| アクター詐称 | JWT クレームのみ参照。ボディの actor_id を無視 |
| 定数時間比較 | `hash_equals()` 使用 |

### 残存する設計上の考慮事項

- **IDOR（監査ログの横断参照）** — 認証済みユーザーが他ユーザーのリソース監査ログを参照できる設計。本番では管理者ロールのみに制限することを推奨（RBAC との組み合わせ）
- **監査テーブルのインデックス** — `actor_id`, `(resource_type, resource_id)` にインデックスが未設定。本番規模では追加が必要

---

## Test results

17 tests, 40 assertions — all pass.  
PHPStan level 8 clean. PHP-CS-Fixer clean.

Key behaviors confirmed:
- タスク作成で `created` 監査エントリが生成される
- タスク更新で `updated` 監査エントリが生成され、before/after が記録される
- タスク削除後も監査ログが保持される（immutability）
- 他ユーザーのタスクを更新しようとすると 404（所有権チェック）
- 他ユーザーのタスクを削除しようとすると 404
- `GET /tasks` は自分のタスクのみ返す
- actor_id は JWT クレームから取得される
- payload に actor_id / password などの機密フィールドが含まれない
- 認証なしで全エンドポイント → 401

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**「いつ監査ログを書けばいい？」:** DB への INSERT が監査だと思いがちだが、Repository に `audit->record()` を書くと「どんな操作か」が分からなくなる。「保存した後に何をしたか」をハンドラに書く、という考え方が難しい。「ショッピングカートに追加した」「ユーザーが退会した」という業務上の言葉で監査を書く場所がハンドラ（UseCase）、という説明で理解できる。

**事故リスク:** 中。監査を忘れるより、監査を wrong レイヤーに書きがち。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**「所有権チェックを毎回書くのが面倒」:** `findById` のたびに `actorId !== $task->actorId` を書くのが辛い。「フレームワークが自動でやってくれないのか」という発想が出る。NENE2 の「マジックなし」哲学と衝突する部分。スコープ付きリポジトリパターン（`findByIdForActor()` のような命名）で意識的に書かせる方法が有効。

**ダミーハッシュの罠:** `dummysalt...` のような文字列でも動くと思いがち。「なぜ正規のハッシュが必要か」をタイミング攻撃の具体例で説明すると腹落ちする。

**事故リスク:** 高。所有権チェック漏れは監査があっても後の祭り。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**「監査ログをフロントエンドに見せる」:** 変更履歴 UI（Notion の「変更履歴を見る」的なもの）を作りたいとき、`GET /audit/{resource_type}/{id}` のレスポンス形式が重要。before/after の JSON 構造は型付きフェッチラッパーとの相性を考慮する必要がある。

**`payload` の型が `mixed`:** フロントで `payload.before.title` と書きたいが、`before` が存在しないエントリ（created エントリ）では undefined になる。Union 型で `CreatedPayload | UpdatedPayload | DeletedPayload` に型付けするパターンが必要。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**Laravel ActivityLog との比較:** `spatie/laravel-activitylog` は Model Observer で自動記録するが、NENE2 では手動でハンドラに書く。「書き忘れ」リスクはあるが、「意図しない操作が記録される」リスクはない。どちらが良いかはトレードオフ。

**トランザクション境界:** 監査記録と業務操作が別トランザクションになっている場合、業務操作は成功したが監査は失敗するケースがある。本番では同一トランザクションに入れることを検討すべき。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. 全 write 操作（create/update/delete）に `audit->record()` が呼ばれているか
2. `actor_id` が JWT クレームから取得されているか（ボディを参照していないか）
3. payload に `password_hash` や `token` などの機密フィールドが含まれていないか
4. 監査テーブルに DELETE エンドポイントがないか
5. 所有権チェックが update/delete の前に行われているか
6. `ORDER BY id DESC` で挿入順序が保証されているか

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- 監査をハンドラレイヤーに置く設計は「コントローラーは薄く: UseCase 呼び出し」と整合
- `actor_id` を JWT クレームから取得する設計は `nene2.auth.claims` の正しい使用方法

**設計上のギャップ:**
1. 監査ログの IDOR（横断参照）は RBAC との組み合わせが前提 → docs/howto/audit-trail.md で言及

---

## Issues / PRs

- Issue #730: このトライアルの起票 → `docs/howto/audit-trail.md` で解消
