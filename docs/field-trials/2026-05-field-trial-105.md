# Field Trial 105 — Optimistic Locking (optlocklog)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/optlocklog/`
**NENE2 version:** 1.5.38
**Theme:** 楽観的ロック（Optimistic Locking）— バージョンフィールドを使った競合する更新の検出と 409 Conflict 応答。`execute()` の戻り値（affected rows）でロック競合を判定するパターン。

---

## What was built

記事の作成・取得・更新 API を実装した。更新 API は楽観的ロックを実装しており、クライアントが読み取ったバージョンと DB の現在バージョンが一致しない場合に 409 Conflict を返す。

---

## Findings

### 1. `execute()` の戻り値で競合を検出（摩擦なし）

楽観的ロックの核心は `WHERE id = ? AND version = ?` の UPDATE で affected rows を確認すること:

```php
$affected = $this->executor->execute(
    'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
    [$title, $body, $now, $id, $expectedVersion],
);

if ($affected === 0) {
    // 0 rows updated = either not found OR version conflict
    $current = $this->findById($id);
    if ($current === null) {
        throw new \RuntimeException("Article {$id} does not exist.");
    }
    throw new ConflictException($id, $expectedVersion); // バージョン不一致
}
```

`DatabaseQueryExecutorInterface::execute()` が affected rows 数を `int` で返すことが PHPDoc に明記されており、楽観的ロックパターンに自然に使える。

**重要:** 0 rows の理由が「レコードなし」か「バージョン不一致」かを区別するため、追加の `findById()` が必要。これは2回のクエリになるが、競合パスのみで実行されるため通常は問題ない。

---

### 2. 「バージョンなし UPDATE」が起こす失われた更新（高リスク・最重要）

楽観的ロックなしの危険なパターン:

```php
// ❌ バージョンチェックなし — 失われた更新が起きる
$this->executor->execute(
    'UPDATE articles SET title = ?, body = ? WHERE id = ?',
    [$title, $body, $id],
);
```

**シナリオ:**
1. ユーザーA と ユーザーB が同時に記事を読む（どちらも version=1）
2. ユーザーA が「タイトルを修正」して保存 → DB は version=1 のまま
3. ユーザーB が「本文を修正」して保存 → ユーザーAの修正が上書きされる（消える）

楽観的ロックがあれば:
- ユーザーAの保存後、version=2 になる
- ユーザーBが version=1 で保存しようとすると 409 → ユーザーBはリロードして再編集

---

### 3. 409 レスポンスに `current_version` を含める（UX 向上）

クライアントが 409 を受け取った後、最新バージョンを知るために GET が不要になる:

```php
return $this->problems->create(
    $request,
    'conflict',
    'Optimistic lock conflict.',
    409,
    $e->getMessage(),
    $current !== null ? ['current_version' => $current->version] : [],
);
```

テストで確認:

```php
$data = $this->json($conflictRes);
$this->assertSame(2, $data['current_version']); // クライアントはこれを使って再試行できる
```

---

### 4. `version` が文字列 `"1"` と整数 `1` の区別（PHPStan 貢献）

JSON ボディで `"version": "1"`（文字列）と `"version": 1`（整数）は PHP の `is_int()` で区別できる:

```php
if (!is_int($body['version'])) {
    return $this->problems->create(..., 400);
}
```

`json_decode` で PHP は数値 JSON を `int` または `float` にデコードするため、クライアントが `"1"` を送ると `is_int()` が false になって 400 が返る。テストで確認済み。

---

## Test results

12 tests, 24 assertions — all pass.

Key behaviors confirmed:
- 作成時の version は 1
- 正常更新で version が 2、3 と順に増える
- 同時書き込みで 2番目が 409 Conflict を受け取る
- 409 後にリフェッチ＋再試行で成功する
- 409 レスポンスに `current_version` が含まれる
- 競合後もデータが勝者の内容で保持される
- 存在しない記事への PATCH → 404
- バージョンフィールドなし → 400
- バージョンが文字列型 → 400
- GET で現在状態を返す

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1年・PHP 独学中）

**楽観的ロックの概念理解:** 「悲観的ロック（SELECT FOR UPDATE）」と「楽観的ロック」の違いを知らないと、「なぜ UPDATE に WHERE version = ? を付けるのか」が謎に見える。「2人が同時に編集したらどうなるか」という問いから入れば理解しやすい。

**affected rows の認識:** `execute()` が affected rows を返すことを活用する発想が出てこない可能性がある。更新が成功したかどうかは「例外が出なければ成功」と思いがちで、0 rows の確認が抜ける。

**事故リスク:** 高。バージョンフィールドを追加し忘れる、または追加したが WHERE 句に含め忘れる（`UPDATE ... WHERE id = ?` だけにする）という事故が起きやすい。この場合コードは動くが競合が検出されない。

---

### ペルソナ2: ロースキル経験者（PHP 歴3〜4年・受託 Web 開発）

**コピペ可能性:** `ArticleRepository::update()` のパターンをそのままコピーすれば動く。ただし「なぜ WHERE version = ? が必要か」を理解しないと、パフォーマンスチューニングで「不要な WHERE 句を削る」と間違えて削ってしまう。

**暗黙の前提:** `execute()` が affected rows を返すことを知らない場合、`if ($affected === 0)` のチェックが「なぜ必要か」わからずコピーする。後から「エラーになることないし、削除しよう」となる可能性がある。

**事故リスク:** 中。コピペで正しく動いても、機能追加時にパターンを崩しやすい。

---

### ペルソナ3: フロントエンド寄り経験者（JS/TS 歴4年・フルスタック転向中）

**409 レスポンスの UX:** `current_version` が 409 に含まれているのは良い。クライアントは追加の GET なしに最新バージョンを知れる。ただし「何が競合したか」（タイトルか本文か）は分からない。マージ UI を実装するには全フィールドの比較が必要なため、クライアントは GET してから差分表示する必要がある。

**`version` フィールドをフロントで管理する負担:** フロントは編集フォームに `version` を hidden field または state として持ち、更新リクエストに含める必要がある。これを忘れると 400 が返る。API のドキュメント（OpenAPI）に `version` が必須と明記されていることが重要。

**再試行 UX:** `409` を受け取った後の UX 設計が難しい。「最新版を表示して再編集を促す」か「自動マージを試みる」かはドメインによる。NENE2 は競合を検出するだけで、マージは呼び出し元に委ねる設計が良い。

---

### ペルソナ4: バックエンド経験者（Laravel/Symfony 歴5年）

**他フレームワークとの差異:** Laravel の Eloquent は `optimistic_lock` パッケージや `Model::lockForUpdate()` のような悲観的ロックを提供する。楽観的ロックを明示的に実装するには Eloquent のイベントフックや手動 SQL が必要。NENE2 の `execute()` は affected rows を返すという設計が楽観的ロックと相性が良く、明示的に実装しやすい。

**`version = version + 1` のアトミック性:** SQL の `version = version + 1` はアトミックな操作なので、2つの UPDATE が同時に `version = 1` を読んでも、一方は WHERE version = 1 が true で成功し、もう一方は（先に更新されたため）WHERE version = 1 が false になって 0 rows になる。SELECT → 計算 → UPDATE のパターン（`$newVersion = $old + 1; UPDATE ... SET version = $newVersion`）はレースコンディションが残るため使わない。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・10年超）

**コードレビューポイント:**
1. `WHERE id = ? AND version = ?` — バージョン条件が抜けていないか
2. `execute()` の戻り値をチェックしているか（0 rows を無視していないか）
3. 0 rows の理由を「not found」と「version conflict」で正しく区別しているか
4. `version = version + 1` をアプリ側で計算していないか（競合の温床）
5. 409 レスポンスに十分な情報が含まれているか

**スケール時の問題:** SQLite の単一接続では実際の競合をテストできない。MySQL/PostgreSQL では複数接続から同時に UPDATE することで真の競合を再現できる。楽観的ロックはハイコンテンション環境では悲観的ロック（SELECT FOR UPDATE）より再試行コストが高くなる。コンフリクト率が高い場合は悲観的ロックの検討が必要。

**`current_version` の情報漏洩リスク:** 409 に `current_version` を返すことは一般的に問題ないが、オブジェクトの存在を証明することになる。IDOR と組み合わせると「このIDのレコードが存在する」という情報になる。NENE2 では認証がある前提で設計されているため、通常は問題ない。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- CLAUDE.md「フレームワークマジックでコントロールフローを隠さない」方針と整合: `WHERE version = ?` の仕組みが明示的
- `execute()` → affected rows → `ConflictException` の流れが追いやすい
- Problem Details (RFC 9457) 形式で 409 を返している（正しい HTTP セマンティクス）

**`execute()` の PHPDoc 充実度:** `execute()` が affected rows を返すことは PHPDoc に書かれているが、楽観的ロックのユースケースが例示されていない。`execute()` の PHPDoc に「楽観的ロックパターンの例」を追加すると発見しやすくなる。

**設計上のギャップ:**
1. `execute()` の PHPDoc に楽観的ロックの例示が欲しい
2. howto: `docs/howto/optimistic-locking.md` — バージョンフィールドパターン・失われた更新問題・409 応答設計・再試行パターン
3. 0 rows の区別（not found vs conflict）は定型パターンなので howto に明示すべき

---

## Issues / PRs

- Issue: `docs/howto/optimistic-locking.md` — バージョンフィールドパターン・失われた更新問題・409 応答設計・再試行フロー
