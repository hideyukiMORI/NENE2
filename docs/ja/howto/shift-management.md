# ハウツー: シフト管理 API

> **FT リファレンス**: FT43 (`NENE2-FT/shiftlog`) — 従業員シフトスケジューリング API
> **VULN**: FT225 — セキュリティ/脆弱性アセスメント（V-01〜V-12）

重複検出、トランザクションスコープのチェック、ISO 8601 日付比較、ドメインエラーのカスタム例外ハンドラーを備えた従業員シフトスケジューリング API を実演します。
VULN セクションでは、すべての攻撃面を体系的にアセスメントし、各発見を記録します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `GET`    | `/employees`                  | 従業員を一覧表示する（ページネーション付き）          |
| `POST`   | `/employees`                  | 従業員を作成する                                     |
| `GET`    | `/employees/{id}`             | 単一の従業員を取得する                               |
| `GET`    | `/employees/{id}/shifts`      | 従業員のシフトを一覧表示する（ページネーション付き） |
| `POST`   | `/shifts`                     | シフトをスケジュールする（重複チェック付き）          |
| `GET`    | `/shifts/{id}`                | 単一のシフトを取得する                               |
| `DELETE` | `/shifts/{id}`                | シフトを削除する                                     |
| `GET`    | `/schedule`                   | 日付ウィンドウ内のシフト（`?from=&to=`）             |
| `GET`    | `/summary/weekly`             | 従業員ごとの週ごとの時間数                           |
| `GET`    | `/summary/overtime`           | 時間しきい値を超えた従業員                           |

---

## 従業員の作成

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // 必須、空でない文字列
    'role'        => 'Barista',  // 必須、空でない文字列
    'hourly_rate' => 18.50,      // 必須、0 より大きい数値
];
```

`is_int()` / `is_string()` の厳密な JSON 型チェックが適用されます。空文字列は `trim()` 後に拒否されます。

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **注**: スキーマには DB レベルでの深層防御バックストップとして `CHECK(hourly_rate > 0)` もあります。適切な 422 を返すためにアプリレイヤーで最初にバリデーションしてください。

---

## 重複検出付きのシフトスケジューリング

重複検出は競合状態を防ぐためにデータベーストランザクション内で実行されます:

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // 重複: [$startsAt, $endsAt) と交差する既存のシフト
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $startsAt, $endsAt, $location, $now],
        );
        // ...
    },
);
```

重複条件 `starts_at < $endsAt AND ends_at > $startsAt` は、4 つすべての重複構成（左から部分、右から部分、内包、包含）を正しく処理します。

**トランザクションが必要な理由:** トランザクションなしでは、2 つの同時リクエストが両方とも重複チェックをパスし、競合するシフトを作成することがあります。トランザクションが読み取り-チェック-書き込みシーケンスをシリアライズします。

---

## ends_at > starts_at バリデーション

アプリケーションは DB の前に時間順序を検証します:

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

スキーマはバックストップとして `CHECK(ends_at > starts_at)` を追加します。2 つのレイヤーを合わせることで、無効な範囲がデータストアに到達しないことを保証します。

---

## ISO 8601 日付文字列比較

シフト時刻は ISO 8601 文字列（`2026-05-27T09:00:00+09:00`）として保存され、SQL で辞書順に比較されます。これは**すべての時刻が同じタイムゾーンオフセットまたは UTC を使用している場合にのみ**正しく機能します。混在オフセット比較は誤った結果を生む可能性があります:

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → 誤り（同じ瞬間）
```

**推奨**: 保存前にすべての日時を UTC に正規化してください:

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## カスタム例外 → HTTP レスポンスマッピング

ドメイン例外はハンドラーを経由して構造化 Problem Details レスポンスにマップされます:

```php
final readonly class ShiftOverlapExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $exception instanceof ShiftOverlapException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->create(
            $request,
            'shift-overlap',
            'Shift overlaps with an existing shift.',
            409,
            $exception->getMessage(),
        );
    }
}
```

`ShiftNotFoundException` → 404、`EmployeeNotFoundException` → 404、`ShiftOverlapException` → 409 のための別々のハンドラーが存在します。これらを `RuntimeApplicationFactory` に登録することで、コントローラーが `try/catch` ボイラープレートから解放されます。

---

## 集計クエリ: 週次サマリーと残業

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

残業しきい値はデフォルト 40 時間:

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

注: `QueryStringParser::int()` を最初に使用し（非数値文字列を拒否）、その後 `float` にキャストします。これにより `NaN` / `Infinity` がビジネスレイヤーに到達することを防ぎます。

---

## スキーマ: カスケード削除と DB レベル制約

```sql
CREATE TABLE employees (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    role        TEXT    NOT NULL,
    hourly_rate REAL    NOT NULL CHECK(hourly_rate > 0),
    created_at  TEXT    NOT NULL
);

CREATE TABLE shifts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    CHECK(ends_at > starts_at)
);
```

`ON DELETE CASCADE` は従業員が削除されたときにそのシフトも削除します。
DB レベルの `CHECK` 制約は深層防御バックストップであり、主要なバリデーションレイヤーではありません — DB INSERT の前にアプリレベルのバリデーションで 422 を返さなければなりません。

---

## VULN — セキュリティアセスメント（FT225）

各発見は攻撃ベクター、観察された結果、および判定を記録します:
**BLOCKED**（セキュア）、**EXPOSED**（実際の脆弱性）、**PARTIALLY EXPOSED**、
または **ACCEPTED BY DESIGN**。

### V-01 — いかなるエンドポイントにも認証なし

**攻撃**: 認証情報なしで従業員の作成、シフトのスケジュール、シフトの削除を行う。

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**観察**: 両方が成功します。トークン、セッション、API キーは不要です。

**判定**: **EXPOSED**（FT43 デモ用に設計）。
本番のスケジューリングシステムはミューテーションを認証でゲートしなければなりません。
`MachineApiKeyMiddleware`（env: `NENE2_MACHINE_API_KEY`）または JWT Bearer を使用してください。

---

### V-02 — 認可なし: 誰でも任意のシフトを削除できる

**攻撃**: オーナーシップチェックなしで別の従業員のシフトを削除する。

```http
DELETE /shifts/1   # 認証済みまたは未認証の呼び出し元に対して成功
```

**観察**: 呼び出し元の身元に関わらず `204 No Content`。

**判定**: **EXPOSED**（FT43 デモ用に設計）。
削除前にマネージャー/管理者ロールチェックを追加するか、シフトをリクエストユーザーに紐付けてください。

---

### V-03 — パラメーター化クエリによる SQL インジェクション

**攻撃**: `name`、`role`、`starts_at`、または `location` を通じて SQL をインジェクトする。

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**観察**: インジェクション文字列を名前として従業員が作成されます。シフトの `starts_at` はパラメーター化クエリで使用されるため SQL インジェクションは発生しません。

**判定**: **BLOCKED** — すべてのクエリは PDO パラメーター化ステートメントを使用します。保存された文字列は DB では無害です; 唯一のリスクは後から HTML としてレンダリングされた場合です。

---

### V-04 — シフト重複検出の競合状態

**攻撃**: 同じ従業員に対して重複するウィンドウを持つ 2 つの同時 `POST /shifts` リクエストを送信する。

**観察**: 重複チェックは `transactional()` 内で実行されます。SQLite は WAL モードロックで書き込みをシリアライズします; MySQL/PostgreSQL はトランザクションマネージャーが正しく設定されている場合、`REPEATABLE READ` または `SERIALIZABLE` 分離を使用します。2 つの同時インサートの両方が重複チェックをパスすることはできません。

**判定**: **BLOCKED** — トランザクション重複チェックが並行性下での二重予約を防ぎます。分離レベルが DB エンジンと一致していることを確認してください; SQLite の WAL デフォルトは単一ノードデプロイに十分です。

---

### V-05 — ends_at ≤ starts_at が受け入れられる

**攻撃**: 終了時刻が開始時刻より前または等しいシフトを送信する。

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**観察**: `422 Unprocessable Entity` — アプリは挿入前に文字列を比較します（`$endsAt <= $startsAt`）。DB の `CHECK(ends_at > starts_at)` はバックストップです。

**判定**: **BLOCKED** — 2 層バリデーション（アプリ + DB 制約）。

---

### V-06 — hourly_rate バリデーションのギャップ

**攻撃**: `hourly_rate` に負、ゼロ、または文字列値を送信する。

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**観察**:
- 負/ゼロ: アプリケーションはコントローラーレイヤーで `hourly_rate > 0` を検証しません。負の値はアプリチェックをバイパスして DB の `CHECK(hourly_rate > 0)` に到達し、DB 例外を発生させます。明示的なハンドラーなしでは 500 になります。
- 文字列 `"free"`: `is_numeric()` が false を返すため 422 で拒否されます。

**判定**: **PARTIALLY EXPOSED** — DB INSERT の前にアプリレイヤーバリデーションを追加してください:
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — 意味的に無効な ISO 8601 日時

**攻撃**: 構造的には妥当だがカレンダー的に無効な日時でシフトを送信する。

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**観察**: 受け入れられて保存されます。アプリケーションは `trim() === ''` を確認しますが日付をパースしません。`DateTimeImmutable` は `2026-02-30` を `2026-03-02` にサイレントに正規化し、保存値を破損します。

**判定**: **EXPOSED** — `starts_at` と `ends_at` の両方にラウンドトリップチェックを追加してください:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — 集計クエリの無制限日付範囲

**攻撃**: 任意の大きな日付範囲でサマリーをリクエストしてメモリを枯渇させたり低速クエリを引き起こす。

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**観察**: クエリはテーブルのすべての行で実行されます。大きなデータセットでは、過剰なメモリ使用や複数秒のレスポンスが発生する可能性があります。

**判定**: **EXPOSED** — コントローラーレイヤーで最大許容範囲（例: 90 日）をキャップしてください:
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — 無制限の従業員名/ロール長

**攻撃**: 数万文字の名前またはロールで従業員を作成する。

```json
{"name": "AAAA... (50000 文字)", "role": "Y", "hourly_rate": 10}
```

**観察**: `201 Created` — SQLite TEXT は無制限; 行が挿入されます。

**判定**: **EXPOSED** — `mb_strlen()` チェックを追加して 422 を返してください:
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — 無制限のロケーション文字列

**攻撃**: 任意の長さのロケーション文字列でシフトをスケジュールする。

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 文字)"}
```

**観察**: `201 Created` — 長さ制限が強制されていません。

**判定**: **EXPOSED** — `mb_strlen($location) <= 200` チェックを追加してください。

---

### V-11 — 名前/ロール/ロケーションへの XSS ペイロード

**攻撃**: 任意のフリーテキストフィールドに `<script>` タグを保存する。

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**観察**: `201 Created`。値は JSON レスポンスでそのまま返されます。

**判定**: **ACCEPTED BY DESIGN** — これは JSON API です; エスケープは HTML レンダリングクライアントの責任です。サーバーはこれらのフィールドから HTML を発行しません。OpenAPI スペックで契約を文書化してください。

---

### V-12 — 非数値パス ID

**攻撃**: `{id}` として非数字または負の値を渡す。

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**観察**: 各ケースで `404 Not Found`。`(int) "abc"` = `0`; ID 0 または負のシフト/従業員は存在しないため、`findShiftById(0)` は `ShiftNotFoundException` をスローし、ハンドラーが 404 にマップします。

**判定**: 実際には **BLOCKED**。注: `(int) "9abc"` = `9` — ID 9 のレコードが存在する場合は返されます。差異が重要な場合はパス ID の厳密なバリデーションに `ctype_digit()` を使用してください。

---

## VULN サマリー

| # | 攻撃ベクター | 判定 |
|---|------------|------|
| V-01 | 認証なし | EXPOSED（設計上） |
| V-02 | 認可なし / 任意のシフトを削除可能 | EXPOSED（設計上） |
| V-03 | SQL インジェクション | BLOCKED |
| V-04 | 重複競合状態 | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | 負の hourly_rate がアプリチェックをバイパス | PARTIALLY EXPOSED |
| V-07 | 意味的に無効な ISO 8601 日時 | EXPOSED |
| V-08 | 集計クエリの無制限日付範囲 | EXPOSED |
| V-09 | 無制限の従業員名/ロール | EXPOSED |
| V-10 | 無制限のロケーション文字列 | EXPOSED |
| V-11 | XSS ペイロードの保存 | ACCEPTED BY DESIGN |
| V-12 | 非数値パス ID | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **V-01/02** — 認証とロールベースの認可を追加する
2. **V-06** — アプリレイヤーで `hourly_rate > 0` バリデーションを追加する
3. **V-07** — 日時フィールドの ISO 8601 ラウンドトリップバリデーションを追加する
4. **V-08** — 集計エンドポイントの最大日付範囲をキャップする（例: 90 日）
5. **V-09/10** — すべてのフリーテキストフィールドに `mb_strlen()` 最大長チェックを追加する

---

## 関連 howto

- [`notification-inbox.md`](notification-inbox.md) — IDOR 保護パターン（未認可の読み取り/書き込みに 404）
- [`prevent-double-booking.md`](prevent-double-booking.md) — トランザクション二重予約防止
- [`expense-tracker.md`](expense-tracker.md) — ISO 8601 ラウンドトリップ日付バリデーション
- [`resource-booking.md`](resource-booking.md) — 日付範囲キャップと時間ウィンドウクエリ
