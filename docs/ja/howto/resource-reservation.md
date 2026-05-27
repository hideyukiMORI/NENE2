# ハウツー: リソース予約 / タイムスロットブッキング API

このガイドでは、NENE2 を使った重複防止機能付きのタイムスロットブッキングシステムの構築方法を解説します。**reservationlog** フィールドトライアル（FT216）で実演されたパターンです。

## 機能

- 名前付きリソースの作成（会議室、備品など） — 管理者のみ
- 自動重複検出によるタイムスロット予約
- リソースごと（管理者）またはユーザーごと（自分）の予約一覧表示
- オーナーシップ確認付き予約キャンセル
- 公開レスポンスでは `user_id` を除外（IDOR 防止）
- 管理者ビューには監査用の `user_id` を含める

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- 高速な重複クエリのためのインデックス
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/resources` | 管理者 | リソースを作成する |
| `GET` | `/resources/{id}/bookings` | 管理者 | リソースのすべての予約を一覧表示する |
| `POST` | `/resources/{id}/book` | ユーザー | タイムスロットを予約する |
| `GET` | `/bookings` | ユーザー | 自分の予約を一覧表示する |
| `DELETE` | `/bookings/{id}` | ユーザー | 自分の予約をキャンセルする |

## 重複検出

2 つの時間範囲 `[A.start, A.end)` と `[B.start, B.end)` は次の場合に重複します:

```
A.start < B.end AND A.end > B.start
```

これにより、すべての重複ケース（内包、重複、同一）を正しく処理しながら、隣接スロット（A.end = B.start は OK — 半開区間セマンティクス）を許可します。

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## バリューオブジェクト

ドメインの明確性のために readonly バリューオブジェクトを使用します:

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** 公開ビュー: user_id を除外（IDOR 防止） */
    public function toPublicArray(): array { ... }

    /** 管理者ビュー: 監査用に user_id を含める */
    public function toAdminArray(): array { ... }
}
```

## IDOR 防止

予約は異なるフィールドを持つ公開ビューと管理者ビューを公開します:

```php
// ユーザー: GET /bookings — 公開ビュー（user_id なし）
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// 管理者: GET /resources/{id}/bookings — 管理者ビュー（user_id を含む）
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

ユーザーが他の人の予約をキャンセルしようとした場合、予約 ID はすでに可視なので（存在を隠していない）、キャンセルは 404 ではなく 403 を返します:

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## セキュリティパターン

- **管理者フェイルクローズ**: `hash_equals()` の前に `if ($this->adminKey === '') return false;`
- **`ctype_digit()`**: パス ID の ReDoS セーフな整数バリデーション
- **ISO 8601 バリデーション**: 正規表現パターン + 辞書順比較（UTC で有効）
- **ノート長ガード**: `mb_strlen($note) > 500` で 422 を返す
- **カスケード削除**: `ON DELETE CASCADE` でリソース削除時に予約も削除される

## VULN + ATK アセスメント（FT216）

この FT は VULN-A から VULN-L、ATK-01 から ATK-12 の全評価をパスしています:

- **VULN-B**: マスアサインメントなし — リソース/予約フィールドは明示的にバインドされる
- **VULN-C**: キャンセルは間違ったオーナーに 403 を返す; リソース/予約検索は型付き ID を使用する
- **VULN-D**: 管理者フェイルクローズ — 空の管理者キーは常に false を返す
- **VULN-F**: ISO 8601 正規表現が日時インジェクションを防ぐ
- **VULN-G**: `ctype_digit()` がすべての整数パスパラメーターをガードする
- **ATK-01**: パラメーター化クエリにより SQL インジェクションをブロック
- **ATK-02/03**: `strlen > 18` ガードにより ID の整数オーバーフローをブロック
- **ATK-06**: フェイルクローズ管理者チェックにより認証バイパスをブロック
- **ATK-09**: 重複ロジックが二重予約を正しく防ぐ
