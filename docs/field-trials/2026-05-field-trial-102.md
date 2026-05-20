# Field Trial 102 — Database Transaction Boundaries (txlog)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/txlog/`
**NENE2 version:** 1.5.35
**Theme:** データベーストランザクション境界 — `DatabaseTransactionManagerInterface::transactional()` を使った原子的な在庫減算 + 注文作成。ロールバック正確性と「インジェクト済みリポジトリ」の罠を検証。

---

## What was built

注文が在庫を原子的に減算する API を実装した。複数商品の注文で1つでも在庫不足があれば、それまでの全減算がロールバックされる。`DatabaseTransactionManagerInterface::transactional()` の正しい使い方と、初心者が踏みやすい罠（コールバック外でインジェクトされたリポジトリはトランザクション外で動く）を検証した。

---

## Findings

### 1. `transactional()` のコールバックスコープルール — 最重要の罠（高）

`DatabaseTransactionManagerInterface::transactional()` の PHPDoc に重要な注意書きがある:

> Repositories injected at construction time use a different connection and execute outside the transaction — rollbacks will not undo their changes.

**正しいパターン（コールバック内でリポジトリをインスタンス化）:**

```php
public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        // コールバック内で $executor を使ってリポジトリを作る — これがトランザクション境界内
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            $inventory->decrement($item['product_id'], $item['quantity']); // 例外 → ロールバック
        }
        return $orders->create($items);
    });
}
```

**誤ったパターン（コンストラクタでインジェクトしたリポジトリをコールバック内で使う）:**

```php
// ❌ これはロールバックされない
public function __construct(
    private readonly InventoryRepository $inventory,  // 別コネクション
    private readonly OrderRepository $orders,         // 別コネクション
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← 別コネクション、トランザクション外！
        }
        return $this->orders->create($items); // ← 同じく外
        // 例外が発生しても $this->inventory の変更は戻らない
    });
}
```

**DX観点:** PHPDoc に書いてあるが、初心者は DI コンテナで全てをインジェクトするのが「正しい設計」と思い込む。この罠を踏むと「トランザクション使ったのに在庫が戻らない」という謎のバグになる。

---

### 2. ロールバック正確性の確認（摩擦なし）

「最後のアイテムが在庫不足」のケースで、先に処理した商品の在庫がロールバックされることをテストで確認:

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// Widget の減算は成功するが、Gadget が在庫不足で例外 → 全ロールバック
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],   // 成功するはずだった
    ['product_id' => 2, 'quantity' => 5],   // 在庫1しかない → 例外
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1)); // Widget は元の10のまま ← ロールバック確認
assertSame(0, $this->orders->count());          // 注文も作成されていない
```

NENE2 の `transactional()` は `Throwable` をキャッチしてロールバック後に rethrow するので、正しいパターンで使えばロールバックは確実。

---

### 3. SQLite の CHECK 制約 vs アプリ層チェック

スキーマに `stock INTEGER NOT NULL CHECK (stock >= 0)` を設定。アプリ層でも在庫チェックをしているが、DB レベルの制約が二重安全網になる。

```sql
CREATE TABLE inventory (
    product_id   INTEGER PRIMARY KEY,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);
```

アプリ層で先にチェックしているため、通常は CHECK 制約は発火しない。ただし並行リクエストでのレースコンディション（チェック後に別トランザクションが減算）では CHECK 制約が最後の防壁になる。今回の FT は SQLite 単一接続なので並行性は検証対象外。

---

### 4. `PdoDatabaseTransactionManager` の可視性（摩擦あり・低）

`PdoDatabaseTransactionManager` クラスに `@internal` タグがついている。インターフェースは公開 API だが、実装クラスを直接テストコードで `new PdoDatabaseTransactionManager($factory)` とインスタンス化するのは内部実装に依存することになる。

FT では直接インスタンス化を使ったが、本番コードでは DI コンテナ経由でインターフェースを受け取るのが正しい。

---

## Test results

11 tests, 25 assertions — all pass.

Key behaviors confirmed:
- 成功注文: 全商品の在庫が正確に減算される
- 最後のアイテムが在庫不足 → 全ロールバック (Widget 在庫が元の値に戻る)
- 最初のアイテムが在庫不足 → 即ロールバック (後続商品も変化なし)
- 無関係な商品の在庫は影響を受けない
- 連続した複数注文は正しく蓄積される
- 在庫ちょうど注文（境界値）
- 在庫+1の注文は拒否（境界値）
- バリデーションエラー（items なし・構造不正・quantity = 0）

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

ドキュメントを読みながら実装する段階。DI の概念は理解しているが「なぜコールバック内でリポジトリを new するのか」が腑に落ちない可能性が高い。

**ドキュメント理解:** `transactional()` のシグネチャとコメントを読んで理解できるかは怪しい。「別コネクション」という概念は DB の内部に踏み込んだ知識が必要で、初心者には抽象度が高い。サンプルコードで「こう書くと動かない」「こう書けば動く」を並べて見せれば理解できる。

**事故リスク:** 高い。インジェクトしたリポジトリをコールバック内で使うのが「普通の DI の使い方」に見えるため、罠にはまりやすい。ロールバックが効いていないバグはテストで再現しにくく（単体テストでは検出不能）、発見が遅れる。

**規約の使いやすさ:** `transactional()` のコールバック引数 `$executor` から自分でリポジトリを new するパターンは、一度理解すれば機械的に書けるが、最初に「なぜ？」を解決するまでが険しい。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

既存プロジェクトのコードをコピーして使うスタイル。DI コンテナに慣れているが深くは理解していない。

**コピペ可能性:** howto にサンプルコードがあれば `OrderService` のパターンをそのままコピーして使える。ただし「このプロジェクトではコールバックに書いてあるから」という理由でコピーするため、why を理解していない。次回新機能を足すとき（例：メールを送る処理を足す）に誤ったパターンで拡張する危険性がある。

**セキュリティ的な罠:** 直接的なセキュリティリスクではないが、「在庫が二重減算される」「注文が作成されたのに在庫が戻らない」という金銭的な事故につながりうる。EC サイトで踏むと深刻。

**事故リスク:** 中。コピペで正しく書けても、後から「外側にあったからここに書いても同じだろう」と`$this->repo->...`に変えてしまう可能性がある。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

API クライアントとして使う側でもあり、API のエラーレスポンスの質を気にする。

**エラーレスポンスの質:** 在庫不足の `422 insufficient-stock` は Problem Details 形式で返るため、フロントから `error.type` で分岐できる。`detail` に「どの商品の在庫が何個不足しているか」が入るのも良い。ただし複数商品が同時に不足している場合、最初に失敗した商品しか報告されない（fail-fast になっている）。全商品の在庫不足を一括チェックするパターンのほうがフロントの UX は良い。

**PHP 固有の学習コスト:** `transactional()` のコールバックスコープは JavaScript の Promise チェーンに近い感覚で理解できる。ただし「PDO コネクション」という概念はフロントエンド出身者には馴染みが薄い。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

Laravel の `DB::transaction()` と比較しながら NENE2 を評価する。

**他フレームワークとの差異:**
Laravel では `DB::transaction(function () { $this->inventoryRepo->decrement(...); })` のようにインジェクト済みモデルをそのまま使える。NENE2 では「コールバック内でリポジトリを new する」という違いがある。Laravel は内部でコネクションを共有しているため機能するが、NENE2 の `PdoConnectionFactory` はリクエストごとに新しいコネクションを返す設計（テストでも毎回インスタンス化）のため、コールバック内での new が必要になる。

この差異を知らずに Laravel 感覚で実装するとサイレントに壊れる。PHPStan でも検出できない（型エラーではなく設計上の罠）。

**NENE2 の薄さ評価:** `transactional()` のシンプルな API は好ましい。ただしコールバックの「スコープルール」のドキュメントが interface のコメントだけでは発見しにくい。howto があれば許容範囲。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

チームで NENE2 を使う場合のリスクを評価する。

**セキュリティ事故リスク:** トランザクション外での在庫更新は金銭的損害に直結する（EC サイトでの在庫マイナス・重複注文）。「動くけど間違っている」コードが混入するリスクが高い。コードレビューで毎回チェックするより、`transactional()` が自動でスコープを強制するような API 設計（例: リポジトリファクトリをコールバック引数として渡す）のほうが安全。

**スケール時の問題:** 単一 SQLite コネクションでは問題が顕在化しにくいが、MySQL/PostgreSQL でコネクションプールを使う環境では「どのコネクションのトランザクションか」が更に重要になる。

**チームでの安全な共有:** パターンが一度確立されれば linter や PHPStan でガードするルールを追加できる。しかし NENE2 のデフォルト tooling ではルール追加が必要（カスタム PHPStan ルール等）。howto + コードテンプレートで補うのが現実的。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `DatabaseTransactionManagerInterface` は公開 API（ADR 0009 対象）として正しく設計されている
- `PdoDatabaseTransactionManager` が `@internal` なのは適切
- CLAUDE.md の「フレームワークマジックでコントロールフローを隠さない」方針と整合: transactional() のスコープルールは明示的だが、**明示性が高すぎて罠になっている**

**「初心者でも安全な API が設計できるか」達成度:** 低〜中。`transactional()` の API 自体はシンプルだが、スコープルールが interface コメントにしか書かれていない。howto で補えば中程度まで上がる。

**設計上の負債・ギャップ:**
1. コールバック内でリポジトリを new する必要があるため、単一責任が崩れやすい（サービスがリポジトリの具体クラスを知る必要がある）
2. `transactional()` を呼ぶ前に全在庫を事前チェックして fail-fast する「pre-validation + atomic decrement」パターンのほうが UX が良い（今回の実装は fail-fast だが複数アイテムの全エラー収集ではない）
3. howto: `docs/howto/transactions.md` — スコープルールの罠・正しいパターン・pre-validation パターンを追加すべき

---

## Issues / PRs

- Issue: `docs/howto/transactions.md` — `transactional()` の正しい使い方・スコープルールの罠（インジェクトリポジトリはトランザクション外）・pre-validation + atomic operation パターン
