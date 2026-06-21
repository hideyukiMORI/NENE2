# ハウツー: 一括並べ替え（ドラッグ&ドロップ順序）API

ドラッグ&ドロップ UI は、リストの*全体の*新しい順序を 1 リクエストで送信します: `[itemC, itemA, itemD, itemB]`。素朴なサーバーはアイテムごとに `UPDATE` を 1 回ずつ実行します — N 回のラウンドトリップと、1 つが失敗したときの中途半端に適用された順序を招きます。

正しい形は、所有者のボードにスコープされた、サーバーが割り当てた値ですべての位置を書き換える**1 つのトランザクション**です。その書き方は 1 つのことに依存します: **`position` が `UNIQUE (board_id, position)` 制約を持つかどうか。**

> **検証済みの落とし穴（FT352）**。SQLite は `UPDATE` が適用される際に `UNIQUE` を**行ごとに**チェックします。したがって位置を入れ替える*いかなる*ステートメント — すべての行にわたる単一の `CASE WHEN` であっても — も、一時的に 2 つの行を同じ位置に置き、`UNIQUE constraint failed: items.board_id, items.position` で失敗します。単一のステートメントで十分なのは **`position` が `UNIQUE` 制約を持たない場合だけ**です（§1）。制約がある場合は、トランザクション内で 2 フェーズの書き込みが必要です（§1.1）。実行可能な証明は [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog) にあります。

**前提条件**: 親（`board_id`、`list_id`、…）にスコープされた整数の `position` カラムを持つテーブル。単一アイテムのケースは [コンテンツのピン留め](content-pinning.md) を参照してください。

---

## 1. 単一ステートメント（`position` に `UNIQUE` 制約なし）

クライアントは*順序付けされた id のリスト*だけを送信します。サーバーは配列インデックスから位置を導出します — クライアントが提供した位置番号を決して信頼しません。`position` が単なるインデックス付きカラム（`UNIQUE` なし）の場合、単一のステートメントで十分です:

```php
/**
 * @param list<int> $orderedIds  新しい表示順序での id
 * @return int  実際に更新された行数
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = 配列インデックス、クライアント入力ではない
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

SQLite で検証済み — `[1,2,3,4]` を id `[3,1,4,2]` へ単一ステートメントで並べ替え:

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

位置は配列インデックスから `0..n-1` で再割り当てされるため、クライアントが何を送ってきても結果は常に連続します。

---

## 1.1. `position` が `UNIQUE` の場合の 2 フェーズ書き込み

`UNIQUE (board_id, position)` があなたの順序を保護している場合（推奨 — データベースレベルで重複位置を止めます）、上記の単一ステートメントは 2 つの行を入れ替えた瞬間に失敗します。まずすべての位置を衝突しない範囲にシフトし、その後最終値を割り当ててください — 中間状態が決して観測されないよう、両方のステップを**1 つのトランザクション**で行います:

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // フェーズ 1: すべての位置を一意な負の値に移動する（衝突なし）。
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // フェーズ 2: 配列インデックスから最終位置を割り当てる。
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` は `0,1,2,…` を `-1,-2,-3,…` にマッピングします — 最終的な `0..n-1` と衝突し得ない別個の値です。`transactional()` のルール（リポジトリをコールバック*内部*でインスタンス化する）は [トランザクションを使う](use-transactions.md) を参照してください。`reorderlog` の `testReorderAdjacentSwapDoesNotCollide` は、単一ステートメントを壊すまさにその入れ替えを実行します。

---

## 2. 影響を受けた行数が整合性チェックになる

`execute()` は `WHERE board_id = ? AND id IN (...)` にマッチした行数を返します。これをリクエストサイズと比較してください:

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // クライアントがこのボードに存在しない（または存在しない）id を参照した。
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

この単一のチェックが、以下のほとんどの攻撃面を打ち負かします: 別のボードに属する id、または存在しない id は単に `WHERE` にマッチしないため、件数が不足し、並べ替え全体が拒否されます。

> 関連する行も変更する場合は、件数チェックと `UPDATE` を `transactional()` でラップしてください。単一の `UPDATE` 自体はすでにアトミックです。[トランザクションを使う](use-transactions.md) を参照してください。

---

## ATK アセスメント — クラッカー視点の攻撃テスト

ターゲット: ボディ `{ "ids": [...] }` を持つ `PUT /boards/{boardId}/order`、認証済み、`board_id` は呼び出し元にスコープされている。

### ATK-01 — 所有していないボードの並べ替え（IDOR）🚫 BLOCKED

**Attack**: 有効な `ids` 配列を送るが、`boardId` は別のユーザーのもの。
**Result**: BLOCKED — 所有権はクエリの前にチェックされ（`board.owner_id === caller`）、`404` を返します。スキップされたとしても、`WHERE board_id = ?` は呼び出し元の id が属する行にマッチしないため、影響件数は 0 となりリクエストは拒否されます。

---

### ATK-02 — 外部アイテムを順序に密輸する 🚫 BLOCKED

**Attack**: 別のボードの `id` を含めて、それを移動/漏洩させる。
**Result**: BLOCKED — `WHERE board_id = ? AND id IN (...)` が外部 id を除外します。影響件数 < リクエストサイズ → `422`、部分的な書き込みなし。

---

### ATK-03 — 部分的な順序（id を省いてギャップを作る）🚫 BLOCKED

**Attack**: ボードの id の半分だけ送り、残りを古い位置に残す。
**Result**: BLOCKED — ハンドラーは送信されたセットがボードの現在の id セットと等しいこと（件数 + メンバーシップ）を要求し、不完全なペイロードを拒否します。

---

### ATK-04 — 明示的な位置番号を注入する 🚫 BLOCKED

**Attack**: サーバーがそれを尊重することを期待して `{ "ids": [...], "positions": [99, -1, ...] }` を送る。
**Result**: BLOCKED — サーバーはクライアントの位置をすべて無視します。`position` は配列インデックスです。余分なボディフィールドは readonly DTO によって落とされます。

---

### ATK-05 — id / position 経由の SQL インジェクション 🚫 BLOCKED

**Attack**: `ids: ["1); DROP TABLE items;--", ...]`。
**Result**: BLOCKED — すべての id と position はバインドパラメーターです。`CASE`/`IN` プレースホルダーは件数によって生成され、文字列連結によることは決してありません。

---

### ATK-06 — 重複した id で位置を破壊する 🚫 BLOCKED

**Attack**: `ids: [5, 5, 5]` で 1 つの行が複数の `CASE` アームを得るようにする。
**Result**: BLOCKED — DTO が id の一意性を検証します。SQLite はいずれにせよ最後にマッチした `WHEN` を適用しますが、件数チェック（`distinct ids` vs ボードサイズ）が先に失敗します。

---

### ATK-07 — 過大なペイロード（DoS）🚫 BLOCKED

**Attack**: 1,000,000 個の id を投稿して巨大な `CASE` を構築する。
**Result**: BLOCKED — `RequestSizeLimitMiddleware` がボディを上限で制限し、ハンドラーはボードの行数より大きい配列を拒否します。

---

### ATK-08 — 非整数 / 負の id 🚫 BLOCKED

**Attack**: `ids: ["abc", -1, 1.5]`。
**Result**: BLOCKED — DTO バリデーションは、SQL が実行される前に各エントリを正の整数として強制/検証します（失敗時 `422`）。

---

### ATK-09 — 並行並べ替えレース 🚫 BLOCKED

**Attack**: 2 つの並べ替えを同時に発火させて位置をインターリーブする。
**Result**: BLOCKED — 各並べ替えは 1 つのトランザクションで実行されます。最後の書き込み者が完全に一貫した `0..n-1` の順序で勝ち、インターリーブされた混合になることは決してありません。2 フェーズ書き込み（§1.1）が中間状態をトランザクション内部に留めるため、並行読み取り者が部分的または衝突した順序を見ることはありません。

---

### ATK-10 — 位置のオーバーフロー / 非連続な結果 🚫 BLOCKED

**Attack**: 繰り返しの並べ替えで位置が巨大またはまばらな値にドリフトすることを期待する。
**Result**: BLOCKED — すべての並べ替えは位置を `0` から書き換えるため、カラムは常に密で行数によって有界です。

---

### ATK-11 — 空の順序で位置を消去する 🚫 BLOCKED

**Attack**: `ids: []`。
**Result**: BLOCKED — 空配列はバリデーションに失敗します（`min 1`）。空の `IN ()` は決して実行されない構文エラーになります。

---

### ATK-12 — テナント間のボード id 列挙 🚫 BLOCKED

**Attack**: `boardId` を反復してレスポンスの違いから存在を発見する。
**Result**: BLOCKED — 未知のボードと未所有のボードはどちらも同一の `404` を返します。件数やタイミングのオラクルが両者を区別することはありません。

---

### ATK サマリー

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | 未所有ボードの並べ替え（IDOR） | 🚫 BLOCKED |
| ATK-02 | 外部アイテムの密輸 | 🚫 BLOCKED |
| ATK-03 | 部分的な順序 / ギャップ | 🚫 BLOCKED |
| ATK-04 | 明示的な位置の注入 | 🚫 BLOCKED |
| ATK-05 | SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | 重複 id | 🚫 BLOCKED |
| ATK-07 | 過大なペイロード | 🚫 BLOCKED |
| ATK-08 | 非整数 / 負の id | 🚫 BLOCKED |
| ATK-09 | 並行並べ替えレース | 🚫 BLOCKED |
| ATK-10 | 位置のオーバーフロー / まばらさ | 🚫 BLOCKED |
| ATK-11 | 空の順序 | 🚫 BLOCKED |
| ATK-12 | ボード id 列挙 | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED.** 重大な発見なし。*サーバーが割り当てる位置*（クライアント入力ではなく配列インデックス）と、ボードにスコープされた `WHERE` に対する*影響件数 / id セット整合性チェック*の組み合わせが並べ替え面を閉じます。唯一の*正しさ*の罠（セキュリティ上の発見ではない）は `UNIQUE (board_id, position)` 制約です: これは単一の `CASE` ステートメントを任意の入れ替えで失敗させるため、§1.1 の 2 フェーズトランザクション書き込みを使ってください — [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog) で検証済みです。

---

## 関連

- [コンテンツのピン留め](content-pinning.md) — 単一アイテムの位置管理
- [ピン / ブックマークの順序](pin-bookmark-ordering.md) — ユーザーごとの順序
- [トランザクションを使う](use-transactions.md) — マルチテーブルの並べ替えをアトミックにラップする
