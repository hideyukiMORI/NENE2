# ハウツー: 習慣トラッカー API

> **FT リファレンス**: FT24 (`NENE2-FT/habitlog`) — ストリーク計算付き習慣トラッキング API
> **ATK**: FT224 — クラッカーマインドセット攻撃テスト（ATK-01〜ATK-12）

ストリーク計算、重複完了保護（409 Conflict）、頻度の許可リストを備えた習慣トラッキング REST API を実演します。ATK セクションは、クラッカーマインドセットが発見するすべての攻撃面を記録し、各々が防御されているか露出しているかを示します。

---

## ルート

| メソッド | パス | 説明 |
|----------|-------------------------------|------------------------------------|
| `GET`    | `/habits`                     | すべての習慣を一覧表示（`?frequency=`） |
| `POST`   | `/habits`                     | 習慣を作成する |
| `GET`    | `/habits/{id}`                | 1 件の習慣を取得する |
| `DELETE` | `/habits/{id}`                | 習慣を削除する（カスケード） |
| `POST`   | `/habits/{id}/completions`    | 完了を記録する（日付レベルで冪等） |
| `GET`    | `/habits/{id}/completions`    | 習慣の完了一覧を表示する |
| `GET`    | `/habits/{id}/streak`         | 現在のストリーク（`?today=YYYY-MM-DD`） |

---

## 習慣の作成

```php
// POST /habits
$body = [
    'name'        => 'Morning Run',        // 必須、空でない文字列
    'description' => 'Run 5 km',           // 任意
    'frequency'   => 'daily',              // 'daily' | 'weekly' | 'monthly'
];
```

`frequency` は明示的な許可リストでバリデーションされます。その他の値は 422 を返します。

```php
private function createHabit(ServerRequestInterface $req): mixed
{
    $body      = JsonRequestBodyParser::parse($req);
    $name      = isset($body['name']) ? trim((string) $body['name']) : '';
    $frequency = isset($body['frequency']) ? (string) $body['frequency'] : 'daily';

    $errors = [];
    if ($name === '') {
        $errors[] = new ValidationError('name', 'Name must not be empty.', 'required');
    }

    $validFrequencies = ['daily', 'weekly', 'monthly'];
    if (!in_array($frequency, $validFrequencies, true)) {
        $errors[] = new ValidationError('frequency', 'Frequency must be daily, weekly, or monthly.', 'invalid_value');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // ...
}
```

---

## 重複保護付きの完了記録

完了は `UNIQUE` 制約によって `(habit_id, completed_on)` でキー付けされます。同じ日付への 2 回目の POST は DB 行に触れずに **409 Conflict** を返します。

```sql
-- schema.sql
UNIQUE(habit_id, completed_on)
```

```php
public function complete(int $habitId, string $completedOn, string $note): Completion
{
    try {
        $this->executor->execute(
            'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
            [$habitId, $completedOn, $note],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new AlreadyCompletedException($habitId, $completedOn);
        }
        throw $e;
    }

    return new Completion($this->executor->lastInsertId(), $habitId, $completedOn, $note);
}
```

コントローラーは `AlreadyCompletedException` → 409 にマップします。NENE2 のグローバルエラーハンドラーに届く前に処理されるため、レスポンスは正しい Problem Details を使用します。

---

## ストリーク計算

ストリークは `$today` から連続した日次完了を遡ってカウントします。

```php
public function currentStreak(int $habitId, string $today): int
{
    $rows = $this->executor->fetchAll(
        'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
        [$habitId],
    );

    $streak   = 0;
    $expected = new \DateTimeImmutable($today);

    foreach ($rows as $row) {
        $date = new \DateTimeImmutable((string) $row['completed_on']);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
            break;
        }
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}
```

`?today=YYYY-MM-DD` で参照日付をオーバーライドできるため、`date()` をモックせずにテストが決定的になります。

---

## 日付フォーマットバリデーション

`completed_on` フィールドはセマンティック解析ではなく正規表現でバリデーションされます:

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    throw new ValidationException([
        new ValidationError('completed_on', 'Date must be in YYYY-MM-DD format.', 'invalid_format'),
    ]);
}
```

これは `"not-a-date"` を正しく拒否しますが、`"2026-02-30"` は受け入れます。厳密なセマンティックバリデーションには `DateTimeImmutable` ラウンドトリップチェックを追加してください:

```php
// 厳格なバリデーション（本番推奨）:
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

## パスパラメーターの安全性

パスの `{id}` は 0 フォールバック付きで `int` にキャストされます:

```php
$id = (int) ($req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id'] ?? 0);
```

数値でない文字列は `0` になります。`id = 0` の習慣は存在しないため、ハンドラーは `null` チェックに移行して 404 を返します。ここでは `ctype_digit()` が不要ですが、`(int) "9abc"` が `9` になることに注意してください — 非数値パスを厳密に拒否する必要があるルートでは `ctype_digit()` を使用してください。

---

## スキーマ: カスケード削除

```sql
CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE(habit_id, completed_on)
);
```

`ON DELETE CASCADE` により、親の習慣が削除されると完了も削除されます。SQLite 使用時は `PRAGMA foreign_keys = ON` で外部キー強制を有効にしてください。

---

## ATK — クラッカー攻撃テスト（FT224）

以下の各結果は、攻撃ベクター、観察結果、および判定を記録します: **BLOCKED**（安全）、**EXPOSED**（実際の脆弱性）、**ACCEPTED BY DESIGN**（文書化された意図的なトレードオフ）。

### ATK-01 — すべてのエンドポイントで認証なし

**攻撃**: 認証情報なしで習慣を作成、読み取り、または削除する。

```http
POST /habits
Content-Type: application/json

{"name": "Attacker habit", "frequency": "daily"}
```

**観察結果**: `201 Created` — トークン、セッション、キーなしで成功。

**判定**: **EXPOSED**（FT24 デモの設計上）。
本番の習慣トラッカーはミューテーションを認証で保護しなければなりません。
NENE2 の `MachineApiKeyMiddleware` または JWT Bearer ミドルウェアがこれをカバーします。

---

### ATK-02 — オーナーシップなし: 任意の習慣を読む/削除する

**攻撃**: 誰の習慣かを知らずに、すべての習慣を列挙して削除する。

```http
GET /habits         → システム内のすべての習慣を一覧表示
DELETE /habits/1    → 誰が作成したかに関わらず習慣 #1 を削除
```

**観察結果**: 一覧表示で `200 OK`、削除で `200 OK`。

**判定**: **EXPOSED**（FT24 デモの設計上）。
`user_id` カラム、書き込みパスでのオーナーシップチェック、未認可アクセスに 404（403 ではなく）を追加してください（IDOR 保護 — FT222 `notificationlog` 参照）。

---

### ATK-03 — パラメーター化されたクエリによる SQL インジェクション

**攻撃**: `name`、`frequency`、`completed_on` 経由で SQL をインジェクションする。

```json
{"name": "x' OR '1'='1", "frequency": "daily"}
{"completed_on": "2026-01-01' OR '1'='1"}
```

**観察結果**: name はそのまま保存される。completion は DB 層に届く前に日付フォーマット正規表現で拒否される。

**判定**: **BLOCKED** — すべてのクエリは PDO パラメーター化ステートメントを使用。頻度の許可リストがアプリケーション層でそのフィールド経由のインジェクションをブロック。

---

### ATK-04 — セマンティック的に無効な日付が受け入れられる

**攻撃**: 構造的には正しいがカレンダー的に無効な日付を送信する。

```json
{"completed_on": "2026-02-30"}
{"completed_on": "2026-13-01"}
{"completed_on": "0000-00-00"}
```

**観察結果**: `201 Created` — 正規表現 `^\d{4}-\d{2}-\d{2}$` が通過。PDO が文字列をそのまま保存。`DateTimeImmutable` が暗黙に正規化（例: `2026-02-30` が `2026-03-02` になる）し、ストリーク計算が壊れる。

**判定**: **EXPOSED** — ラウンドトリップチェックを追加してください:
```php
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

### ATK-05 — 数値でないパス ID

**攻撃**: `{id}` として非数値または負の値を送信する。

```http
GET  /habits/abc
GET  /habits/-1
GET  /habits/0
GET  /habits/1.5
```

**観察結果**: すべて `404 Not Found` を返す。`(int) "abc"` = `0`、`(int) "-1"` = `-1`、`(int) "1.5"` = `1`。これらの ID の習慣は存在しないため、`findById()` は `null` を返す。

**判定**: **BLOCKED**（実際には、ID ≤ 0 の習慣が存在しない）。ただし `(int) "9abc"` = `9` — ID 9 の習慣が存在すれば返される。違いが重要な場合は厳密なパス ID バリデーションに `ctype_digit()` を使用してください。

---

### ATK-06 — 同じ日付の重複完了

**攻撃**: ストリークを水増しするために同じ `(habit_id, completed_on)` を 2 回 POST する。

```http
POST /habits/1/completions {"completed_on": "2026-05-20"}
POST /habits/1/completions {"completed_on": "2026-05-20"}
```

**観察結果**: 2 回目のリクエストが `409 Conflict` を返す — DB 層で UNIQUE 制約が発動し、`AlreadyCompletedException` がキャッチされ、Problem Details レスポンスが返される。

**判定**: **BLOCKED** — DB 制約が権威あるガード。アプリケーション層がそれを適切な 409 にマップする。

---

### ATK-07 — name/note への XSS ペイロード

**攻撃**: `name` または `note` にスクリプトタグを保存する。

```json
{"name": "<script>alert(document.cookie)</script>", "frequency": "daily"}
```

**観察結果**: `201 Created`。ペイロードはそのまま保存され、JSON レスポンスでそのまま返される。

**判定**: **ACCEPTED BY DESIGN** — これは JSON API。エスケープはレンダリングクライアントの責任です。サーバーはこれらのフィールドから HTML を生成しません。この契約を API 仕様に明確に記載してください。

---

### ATK-08 — 極端に長い習慣名

**攻撃**: ストレージを枯渇させたり低速なシリアライゼーションを引き起こすために、数万文字の名前を送信する。

```php
'name' => str_repeat('A', 50_000)
```

**観察結果**: `201 Created` — アプリケーション層で長さ制限が強制されない。SQLite TEXT は上限なし。行が挿入される。

**判定**: **EXPOSED** — コントローラーのバリデーションブロックに最大長チェック（例: 200 文字）を追加して 422 を返してください:
```php
if (mb_strlen($name) > 200) {
    $errors[] = new ValidationError('name', 'Name must not exceed 200 characters.', 'max_length');
}
```

---

### ATK-09 — 空白のみの習慣名

**攻撃**: すべて空白の名前を送信する。

```json
{"name": "   "}
```

**観察結果**: `422 Unprocessable Entity` — `trim()` が値を `''` に折りたたみ、`required` バリデーションエラーをトリガーする。

**判定**: **BLOCKED** — 空文字列チェック前の `trim()` がこれをカバーする。

---

### ATK-10 — `?today=` クエリパラメーターによるストリーク操作

**攻撃**: 参照日付をオーバーライドして過去のストリークを主張する。

```http
GET /habits/1/streak?today=2099-12-31
GET /habits/1/streak?today=not-a-date
```

**観察結果**: `today=2099-12-31` → streak = 0（未来に完了なし）。
`today=not-a-date` → PHP の `DateTimeImmutable` が不正な値で内部例外をスロー（デフォルトエラーハンドラーで 500 になる）。

**判定**: **PARTIALLY EXPOSED** — `currentStreak()` に渡す前に `today` を正規表現またはラウンドトリップチェックでバリデーションしてください:
```php
$today = QueryStringParser::string($req, 'today') ?? date('Y-m-d');
$dt    = DateTimeImmutable::createFromFormat('Y-m-d', $today);
if ($dt === false || $dt->format('Y-m-d') !== $today) {
    $today = date('Y-m-d'); // サーバー日付にフォールバック
}
```

---

### ATK-11 — 存在しない習慣の完了

**攻撃**: 存在しない習慣 ID の完了を POST する。

```http
POST /habits/99999/completions
{"completed_on": "2026-05-20"}
```

**観察結果**: `404 Not Found` — `findById(99999)` が `null` を返し、コントローラーが INSERT を試みる前に not-found レスポンスを返す。

**判定**: **BLOCKED** — 存在チェックが DB 書き込みより先に行われる。

---

### ATK-12 — クエリパラメーターでのパストラバーサル/インジェクション

**攻撃**: `frequency` フィルターでパストラバーサルまたはシェルインジェクション文字列をインジェクションする。

```http
GET /habits?frequency=../../../etc/passwd
GET /habits?frequency='; DROP TABLE habits; --
```

**観察結果**: どちらも空の `habits` 配列で `200 OK` を返す。`frequency` 値は保存された値に対する厳密な `===` 比較で `array_filter` のみで使用される。そこから SQL クエリは構築されない。

**判定**: **BLOCKED** — フィルター-クエリパラメーターは生の SQL `WHERE` 句としてではなく PHP メモリで適用される。ファイル I/O やシェル実行はトリガーされない。

---

## ATK サマリー

| # | ベクター | 判定 |
|---|--------|---------|
| ATK-01 | 認証なし | EXPOSED（設計上） |
| ATK-02 | オーナーシップなし / IDOR | EXPOSED（設計上） |
| ATK-03 | SQL インジェクション | BLOCKED |
| ATK-04 | セマンティック的に無効な日付 | EXPOSED |
| ATK-05 | 数値でないパス ID | BLOCKED |
| ATK-06 | 重複完了 | BLOCKED |
| ATK-07 | XSS ペイロード保存 | ACCEPTED BY DESIGN |
| ATK-08 | 名前長さ無制限 | EXPOSED |
| ATK-09 | 空白のみの名前 | BLOCKED |
| ATK-10 | `?today=` 操作 | PARTIALLY EXPOSED |
| ATK-11 | 存在しない習慣の完了 | BLOCKED |
| ATK-12 | QS でのパストラバーサル/インジェクション | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01/02** — 認証とオーナーシップを追加する
2. **ATK-04** — セマンティック日付バリデーション（`DateTimeImmutable` 経由のラウンドトリップ）を追加する
3. **ATK-08** — `name`/`note` に `mb_strlen()` 最大長チェックを追加する
4. **ATK-10** — ビジネスロジックに渡す前に `?today=` をバリデーションする

---

## 関連ハウツー

- [`notification-inbox.md`](notification-inbox.md) — IDOR 保護パターン（未認可読み取りに 404）
- [`expense-tracker.md`](expense-tracker.md) — 厳格な `is_int()` 型チェックと ISO 日付ラウンドトリップバリデーション
- [`session-management.md`](session-management.md) — このパターンの上に追加する認証レイヤー
