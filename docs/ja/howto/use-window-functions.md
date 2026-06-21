# ハウツー: SQLite ウィンドウ関数を使う

ウィンドウ関数は、行を単一のグループに畳み込む（`GROUP BY` がやるように）ことなく、*現在の行に関連する*行の集合にまたがって値を計算します。**ランキング**、**累積合計**、**前期比較**を行うのに最適なツールであり、これら 3 つのパターンは後から PHP で処理しようとすると面倒で遅くなります。

SQLite は **3.25.0**（2018）以降ウィンドウ関数をサポートしています。NENE2 は PHP 同梱の SQLite を使っており、これは余裕でそのバージョンを上回っています。MySQL 8.0+ と PostgreSQL も同じ構文をサポートするため、これらのクエリは NENE2 が対象とする 3 つのアダプター間で移植可能です。

これらは他の読み取りクエリと同様に `DatabaseQueryExecutorInterface::fetchAll()` を通して実行します — 配線すべき特別なフレームワークサポートはありません。

**前提条件**: `DatabaseQueryExecutorInterface` を背後に持つリポジトリがあること。[データベースバックエンドのエンドポイントを追加する](add-database-endpoint.md) を参照してください。

---

## 1. ウィンドウの構造

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — game ごとにウィンドウをリスタートする（省略するとすべての行を 1 つのウィンドウとして扱う）。
- `ORDER BY points DESC` — パーティション*内*で順序付けする。これが「最初」と「前」の意味を定義する。

複数のカラムが同じウィンドウを再利用する場合、`WINDOW` 句で一度だけ名前を付けます:

```sql
SELECT player, game, points,
       ROW_NUMBER()  OVER w AS rn,
       RANK()        OVER w AS rnk,
       DENSE_RANK()  OVER w AS drnk
FROM scores
WINDOW w AS (PARTITION BY game ORDER BY points DESC)
ORDER BY game, points DESC;
```

---

## 2. ランキング: `ROW_NUMBER` vs `RANK` vs `DENSE_RANK`

この 3 つは同点（タイ）の扱い方だけが異なります。`chess` で 150 点に並んだ 2 人のプレイヤーがいる場合:

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — 常に一意（1, 2, 3）。安定したページネーションカーソルや「グループごとにちょうど 1 つ選ぶ」場合に使う。
- **`RANK`** — 同点は同じランクを共有し、その後スキップする（1, 1, 3）。「同率 1 位」が意味を持つリーダーボードに使う。
- **`DENSE_RANK`** — 同点は同じランクを共有し、ギャップなし（1, 1, 2）。「ティア」/ グレードバケットに使う。

> ランク関数は意図的に選んでください。「ランク 2」のプレイヤーが 2 人いて「ランク 1」が誰もいないリーダーボードは、ほぼ確実に `RANK`/`ROW_NUMBER` の取り違えです。

リポジトリメソッドでは:

```php
/**
 * @return list<array{player: string, game: string, points: int, rank: int}>
 */
public function topRankedByGame(string $game): array
{
    return $this->executor->fetchAll(
        'SELECT player, game, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores
         WHERE game = :game
         ORDER BY points DESC',
        ['game' => $game],
    );
}
```

---

## 3. 累積合計: ウィンドウとしての集約

任意の集約（`SUM`、`AVG`、`COUNT`、…）は、`OVER (...)` 句とフレームを与えると*累積*集約になります:

```sql
SELECT created_at, points,
       SUM(points) OVER (ORDER BY created_at ROWS UNBOUNDED PRECEDING) AS running_total
FROM scores
ORDER BY created_at;
```

| created_at | points | running_total |
|------------|--------|---------------|
| 2026-01-01 | 100    | 100           |
| 2026-01-02 | 150    | 250           |
| 2026-01-03 | 150    | 400           |
| 2026-01-04 | 90     | 490           |

`ROWS UNBOUNDED PRECEDING` は「パーティションの先頭から現在の行までのすべての行」を意味します。明示的なフレームがない場合、デフォルト（`RANGE UNBOUNDED PRECEDING`）は **`ORDER BY` の値が同点になるすべての行**を同じステップに合算します — タイムスタンプが衝突したときに誤った合計を生む微妙な原因です。真の行ごとの累積合計が欲しいときは `ROWS` で明示してください。

---

## 4. 前期比較: `LAG` と `LEAD`

`LAG` はウィンドウ内の*前の*行からカラムを読み取り、`LEAD` は*次の*行を読み取ります。これにより自己結合なしで差分を計算できます:

```sql
SELECT created_at, points,
       points - LAG(points) OVER (ORDER BY created_at) AS delta
FROM scores
ORDER BY created_at;
```

| created_at | points | delta |
|------------|--------|-------|
| 2026-01-01 | 100    | *null* |
| 2026-01-02 | 150    | 50    |
| 2026-01-03 | 150    | 0     |
| 2026-01-04 | 90     | −60   |

最初の行の `delta` は前の行が存在しないため `NULL` です。下流での null 処理を避けるためにデフォルトを与えてください: `LAG(points, 1, 0)` は最初の行に対して `NULL` の代わりに `0` を返します。`NULL` を JSON レスポンスに漏らすのではなく、DTO で型付き値にマッピングしてください。

---

## 5. ウィンドウ結果でのフィルタリング

ウィンドウ関数を `WHERE` 句に入れることは**できません** — ウィンドウは `WHERE` の*後*に評価されます。クエリをサブクエリ（または CTE）でラップし、エイリアスでフィルタリングしてください:

```sql
WITH ranked AS (
    SELECT player, game, points,
           ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC) AS rn
    FROM scores
)
SELECT player, game, points
FROM ranked
WHERE rn <= 3            -- game ごとのトップ 3
ORDER BY game, points DESC;
```

この「グループごとのトップ N」の形は最も一般的な実世界の用途です。`N` 個の別々の `LIMIT` クエリの代わりにこれを使ってください。

---

## 6. 型付きレスポンスとして返す

SQL はリポジトリに留め、コントローラーに到達する前に readonly DTO にマッピングしてください — 生の `array` を境界をまたいで渡さないでください:

```php
final readonly class GameRanking
{
    public function __construct(
        public int $rank,
        public string $player,
        public int $points,
    ) {}
}
```

```php
/** @return list<GameRanking> */
public function topRankedByGame(string $game): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores WHERE game = :game ORDER BY points DESC',
        ['game' => $game],
    );

    return array_map(
        static fn (array $r): GameRanking => new GameRanking(
            rank: (int) $r['rank'],
            player: (string) $r['player'],
            points: (int) $r['points'],
        ),
        $rows,
    );
}
```

SQLite は PDO 経由ですべてのカラム値を文字列として返すため、マッパー内でキャスト（`(int)`、`(float)`）してください — ウィンドウ関数の結果（`rank`、`running_total`）も例外ではありません。

---

## 落とし穴

- **`WHERE` はウィンドウエイリアスを参照できない** — 外側のクエリ/CTE でフィルタリングする（§5）。
- **デフォルトフレームは `ROWS` ではなく `RANGE`** — 累積合計には `ROWS UNBOUNDED PRECEDING` で明示する（§3）。
- **`LAG`/`LEAD` は端で `NULL` を返す** — デフォルトを渡すか型付き値にマッピングする（§4）。
- **移植性** — 上記の構文は標準で、SQLite 3.25+、MySQL 8.0+、PostgreSQL で動作する。古い MySQL（5.7）を対象とする場合、ウィンドウ関数は利用できない。自己結合にフォールバックするか PHP で計算する。
- **`ORDER BY` カラムにインデックスを張る** — ウィンドウの `PARTITION BY` / `ORDER BY` は通常のソートと同じインデックスから恩恵を受ける。

---

## 関連

- [データベーストランザクションを使う](use-transactions.md) — アトミックなマルチステップ書き込み
- [リーダーボードランキング](leaderboard-ranking.md) — ランキングの上に構築されたプロダクトレシピ
- [データベースバックエンドのエンドポイントを追加する](add-database-endpoint.md) — リポジトリ + executor の配線
