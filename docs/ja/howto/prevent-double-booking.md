# 二重予約を防ぐ方法（予約と定員管理）

予約システムには、別々に対処すべき 2 つの失敗モードがあります:

1. **重複予約** — 同じユーザーが同じスロットを 2 回予約しようとする
2. **定員超過** — 予約数がスロットの上限を超えてしまう

どちらも INSERT の拒否につながりますが、異なるエラーレスポンスが必要です。
このガイドでは、それらを区別して並行競合から守る方法を解説します。

---

## 1. スキーマ: UNIQUE 制約 + 定員カラム

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- 重複予約に対する最後の砦
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

`UNIQUE(slot_id, user_id)` 制約はセーフティネットです — アプリケーションロジックにバグがあっても重複予約を防ぎます。ただし、INSERT が失敗した*理由*を教えてはくれません。

---

## 2. 明示的なチェックで重複と定員超過を区別する

`DatabaseConstraintException` はどの制約が発動したかについてカラムレベルの情報を持ちません。異なる 409 レスポンスを返すには、INSERT の前に各条件をチェックします:

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. まず重複予約を確認
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. 残り定員を確認
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // 呼び出し元が null → 409 slot-full にマップ
    }

    // 3. INSERT — UNIQUE 制約が最終的な守り
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

ユーザー向けのビジネスルールには**ドメイン例外**（`AlreadyReservedException`）を使ってください。`DatabaseConstraintException` はデータベース層のイベントであり、ビジネス条件ではありません。

---

## 3. ハンドラー: 異なる 409 レスポンスにマップする

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. SQL で空き数を計算する（N+1 を避ける）

スロット取得と同じクエリで予約数をカウントします:

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

そして `available = capacity - reserved` とします。PHP で全予約を取得してカウントすることは避けてください。

---

## 5. 並行性: TOCTOU とその影響

明示的なチェック→挿入パターンには **TOCTOU（チェック時/使用時）ウィンドウ**があります: 2 つの並行リクエストが両方とも定員チェックを通過し、両方が挿入しようとする場合があります。

| データベース | 動作 |
|---|---|
| **SQLite** | データベースごとの書き込みシリアライズ: 一度に 1 つの書き込みのみ実行。2 番目の INSERT は UNIQUE 制約に当たり `DatabaseConstraintException` をスローします。安全。 |
| **PostgreSQL** 高並行時 | 異なる `user_id` を持つ 2 つのリクエストが両方とも `available > 0` チェックを通過して両方 INSERT し、一時的に定員を 1 超過することがあります。UNIQUE 制約は発動しません（異なるユーザー）。 |

**PostgreSQL での修正**: チェックと INSERT を `SERIALIZABLE` トランザクションでラップするか、スロット行を読む前に `SELECT ... FOR UPDATE` を使ってロックします:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // このクロージャ内のすべてのクエリが同じシリアライズ可能なスナップショットを共有
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

SQLite では UNIQUE 制約だけで十分な保護となります。

---

## 6. 並行シナリオのテスト

順次テストでは真の並行性を再現できませんが、意図を検証することはできます:

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // "同時に"到着

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // スロット満員

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // キャンセル後、bob が予約可能
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## 注意事項

- UNIQUE 制約は**最後の砦**です — アプリケーションロジックのバグを捕捉します。
  重複ユーザーと定員超過を区別できないため、主要な定員管理手段として依存しないでください。
- **キャンセルと再予約**: ユーザーがキャンセルした場合、`reservations` から削除します。`COUNT(r.id)` クエリで定員カウントが自動的に減少します。「スロット解放」の明示的な更新は不要です。
- **冪等なキャンセル**: `DELETE WHERE slot_id = ? AND user_id = ?` は予約が存在しない場合 0 行を返します — これは 500 ではなく 404 にマップしてください。
