# Field Trial 108 — Soft Delete (Logical Deletion)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/softdeletelog/`
**NENE2 version:** 1.5.41
**Theme:** ソフトデリート（論理削除）— `deleted_at` フィールドによる論理削除・ゴミ箱エンドポイント・復元・物理削除。`WHERE deleted_at IS NULL` が抜けると削除済みデータが漏洩するセキュリティリスク。

---

## What was built

ノートの CRUD + ソフトデリート API を実装した。

- `POST /notes` — 作成
- `GET /notes` — アクティブなノート一覧（`deleted_at IS NULL`）
- `GET /notes/trash` — 削除済みノート一覧
- `GET /notes/{id}` — 取得（削除済みは 404）
- `DELETE /notes/{id}` — ソフトデリート（`deleted_at = now()`）
- `POST /notes/{id}/restore` — 復元（`deleted_at = NULL`）
- `DELETE /notes/{id}/purge` — 物理削除（トラッシュから完全削除）

---

## Findings

### 1. `WHERE deleted_at IS NULL` の書き忘れ（最重要・高リスク）

ソフトデリートの最大の落とし穴は、クエリに `WHERE deleted_at IS NULL` を書き忘れること:

```php
// ❌ 削除済みレコードが返る — データ漏洩
$rows = $this->executor->fetchAll('SELECT * FROM notes WHERE id = ?', [$id]);

// ✅ 削除済みを除外
$rows = $this->executor->fetchAll(
    'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

コードは動作するが、削除済みのはずのデータが API レスポンスに含まれる。認証済みシステムでも IDOR に繋がるリスクがある（他ユーザーの削除済みデータが見える）。

---

### 2. `includeTrashed` フラグで意図を明示

復元・物理削除ではトラッシュ内のレコードを操作する必要があるため、明示的な `$includeTrashed = false` フラグを使う:

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';
    // ...
}

// 使い方
$this->findById($id);                       // アクティブのみ
$this->findById($id, includeTrashed: true); // トラッシュも含む
```

デフォルトが `false`（除外）なのは「うっかり削除済みを返してしまう」事故を防ぐ設計として重要。

---

### 3. 物理削除はトラッシュ内のみ許可（purge のガード）

`purge` が「アクティブなレコードの物理削除」を許可しないようにガードが必要:

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    // トラッシュに入っていない（アクティブ or 存在しない）場合は拒否
    if ($note === null || !$note->isDeleted()) {
        return false;
    }
    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

このガードがないと、ソフトデリートを経由せずに物理削除ができてしまう（監査ログが残らない）。

---

### 4. `execute()` + `lastInsertId()` vs `insert()` の混乱（摩擦）

最初の実装で `execute()` + `lastInsertId()` を使ってしまった。正しいイディオムは `insert()`:

```php
// ❌ 2回呼ぶ（冗長、後から lastInsertId() を呼ぶ順序に依存）
$this->executor->execute('INSERT INTO ...', [...]);
$id = (int) $this->executor->lastInsertId();

// ✅ insert() は affected rows ではなく insert id を返す
$id = $this->executor->insert('INSERT INTO ...', [...]);
```

PHPDoc に「`execute()` followed by `lastInsertId()` と等価」と書かれているが、`insert()` の存在に気付かずに長パターンを使ってしまうことがある。

---

### 5. `Router::PARAMETERS_ATTRIBUTE` vs `Router::param()` の変遷

古いバージョン（v1.5.20）では `Router::param()` が存在せず、`Router::PARAMETERS_ATTRIBUTE` を使った:

```php
// 旧パターン（v1.5.20 以前）
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id     = (int) ($params['id'] ?? 0);

// 新パターン（v1.5.31+）
$id = (int) Router::param($request, 'id');
```

NENE2 のバージョンアップで `Router::param()` が追加されたが、旧パターンで書いた後にアップグレードしても動作は変わらない。ただし `Router::param()` の方が意図が明確でコードが短い。howto やサンプルを古いパターンで書くと、新バージョンの便利メソッドが伝わらない。

---

## Test results

18 tests, 30 assertions — all pass.

Key behaviors confirmed:
- POST → 201、アクティブ一覧に表示
- GET /notes — `deleted_at IS NULL` のみ返す
- GET /notes/trash — `deleted_at IS NOT NULL` のみ返す
- GET /notes/{id} — 削除済みは 404
- DELETE → `deleted_at` にタイムスタンプ設定、一覧から消える
- 既に削除済みの DELETE → 404
- POST /{id}/restore → `deleted_at = NULL`、一覧に戻る
- アクティブなノートの restore → 404
- DELETE /{id}/purge → トラッシュから完全削除
- 物理削除後の GET → どこからも見つからない
- アクティブなノートの purge → 404
- title なしの POST → 422

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**`WHERE deleted_at IS NULL` の忘れ:** 「削除したのになぜ出てくるのか」が分からず、デバッグに時間がかかる。`SELECT *` + 後で PHP 側でフィルタリング、という誤った実装に走る可能性がある。

**ソフトデリートとハードデリートの違い:** 「なぜ DELETE しないのか」の理由（監査ログ・データ復元・参照整合性）が理解できると、`deleted_at` パターンの意味が分かる。理由を知らないまま実装すると、後から「削除フラグをどうせ使わないから消そう」となる。

**事故リスク:** 高。`findById()` に `deleted_at IS NULL` を入れ忘れた場合、テストで気付きにくい（テストが削除後の取得を試みていなければ）。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**コピペ可能性:** `SqliteNoteRepository` のパターンをそのままコピーすれば動く。ただし「`findById()` の `WHERE deleted_at IS NULL` を消したらどうなるか」を理解していないと、パフォーマンスチューニング時に「不要な条件を削る」という誤操作が起きる。

**`insert()` vs `execute()` + `lastInsertId()`:** PHP/PDO に慣れていると `lastInsertId()` を呼ぶパターンに馴染みがある。`insert()` の存在を知らずに長いパターンを使ってしまう。

**事故リスク:** 中。コピペで正しく動いても、機能追加時にパターンを崩しやすい。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**ゴミ箱 UI:** `GET /notes/trash` でトラッシュ一覧を取得して「ごみ箱を空にする」「復元」ボタンを実装するのは直感的。`deleted_at` フィールドが null か非 null かで表示を切り替える UI は実装しやすい。

**`DELETE /notes/{id}` の意味:** HTTP の `DELETE` がソフトデリート（完全削除ではない）なのは、REST セマンティクスと若干乖離する。「`DELETE` を呼んだのにデータが残る」という驚きがある。設計によっては `POST /notes/{id}/trash` の方が明示的かもしれない。

**事故リスク:** 低。ただし「DELETE = 完全削除」という思い込みで UI を設計しないよう注意。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**Eloquent との差異:** Laravel の `SoftDeletes` trait は `deleted_at` カラムを自動で管理し、クエリスコープも自動適用される。NENE2 では明示的に `WHERE deleted_at IS NULL` を書く必要がある。「フレームワークマジックで隠さない」方針と整合しているが、書き忘れリスクが高い。

**`$includeTrashed` フラグ:** Eloquent の `withTrashed()` に相当する。Laravel は Eloquent モデルに自動でスコープを掛けるが、NENE2 は明示的なフラグ。どちらも正しい設計だが、デフォルトが「除外」というのは同じ。

**物理削除のガード:** Eloquent の `forceDelete()` はソフトデリート済みでも実行できる（ガードなし）。NENE2 の FT 実装は「トラッシュに入っていないと物理削除できない」という追加のガードを設けており、監査証跡の観点では safer。

**事故リスク:** 低。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. すべての SELECT/UPDATE/DELETE クエリに `WHERE deleted_at IS NULL` が必要か確認
2. `findById()` のデフォルトが `includeTrashed = false` になっているか
3. `purge()` が「アクティブなレコードを物理削除できない」ようにガードしているか
4. レスポンスに `deleted_at` が含まれているか（null か非 null でクライアントが状態を判別できる）
5. 全文検索・JOIN・集計クエリにも `deleted_at IS NULL` が付いているか（特に JOIN 先が見落としやすい）

**外部キー制約とソフトデリート:** 削除済みレコードを参照する外部キーが残っていると参照整合性の問題が起きる。物理削除前にすべての参照先を確認するか、カスケードソフトデリート戦略が必要。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `findById()` の `$includeTrashed = false` デフォルトは「明示的」で「フレームワークマジックなし」方針と整合
- `isDeleted()` メソッドを `Note` に持たせることでビジネスルールがドメインに閉じている

**設計上のギャップ:**
1. `insert()` vs `execute()` + `lastInsertId()` の使い分けが howto に未記載
2. `WHERE deleted_at IS NULL` 忘れのリスクを howto のチェックリストに明記すること
3. `GET /notes/trash` vs `GET /notes?include_deleted=true` の設計選択基準が不明確

---

## Issues / PRs

- Issue: `docs/howto/soft-delete.md` — deleted_at パターン・includeTrashed フラグ・WHERE deleted_at IS NULL 忘れリスク・purge ガード・ REST セマンティクスの考慮・チェックリスト
