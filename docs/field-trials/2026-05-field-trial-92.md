# Field Trial 92 — Double-Booking Prevention (Concurrency Conflict Detection)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/bookinglog/`
**NENE2 version:** 1.5.25
**Theme:** Reservation systems — preventing duplicate bookings and enforcing slot capacity under concurrent access

---

## What was built

A time-slot reservation API with capacity enforcement. Each slot has a capacity (e.g., 1 for private sessions, 5 for group classes). Users can reserve, list, and cancel reservations. The system prevents:

1. A user booking the same slot twice (duplicate reservation)
2. Total reservations exceeding slot capacity

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/slots` | Create a slot with date, time, capacity |
| GET | `/slots` | List all slots with current availability |
| GET | `/slots/{slotId}` | Get slot with reservation count |
| POST | `/slots/{slotId}/reservations` | Reserve a slot; 409 if full or already reserved |
| GET | `/slots/{slotId}/reservations` | List reservations for a slot |
| DELETE | `/slots/{slotId}/reservations/{userId}` | Cancel a reservation |

### Schema

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
    slot_id    INTEGER NOT NULL,
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id),
    FOREIGN KEY (slot_id) REFERENCES slots(id)
);
```

---

## Frictions found

### 1. Cannot distinguish "slot full" from "already reserved" via UNIQUE constraint alone

**Severity:** High (design friction, affects error UX)

The `UNIQUE(slot_id, user_id)` constraint prevents duplicate reservations. The capacity check (`COUNT(*) < capacity`) prevents overbooking. Both result in a rejected INSERT — but they need different 409 responses:

- `"already-reserved"` → "You already have a reservation for this slot."
- `"slot-full"` → "No capacity remaining for this slot."

`DatabaseConstraintException` (caught from the INSERT) does not carry information about which UNIQUE constraint fired. To distinguish the two cases, an explicit `SELECT` before the `INSERT` is required:

```php
// Must SELECT first to tell apart duplicate-user vs over-capacity
$existing = $this->db->fetchOne(
    'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
    [$slotId, $userId],
);
if ($existing !== null) {
    throw new AlreadyReservedException('...');
}

$slot = $this->findSlot($slotId);
if ($slot->available() === 0) {
    return null; // slot full
}
$this->db->insert('INSERT INTO reservations ...');
```

This check-then-act pattern introduces a TOCTOU (Time-of-Check / Time-of-Use) window — see F-2.

---

### 2. TOCTOU window between capacity check and INSERT

**Severity:** Medium (concurrency correctness, context-dependent)

The `findSlot()` + `INSERT` sequence is two separate queries. Between them, a concurrent request could:

1. Read the slot (available = 1)
2. Both see capacity available
3. Both INSERT — one succeeds, one fails with UNIQUE constraint (if same user) or over-capacity (different users)

**SQLite**: per-database write serialization means only one write runs at a time — the UNIQUE constraint fires on the second INSERT. Safe in practice for SQLite.

**PostgreSQL under concurrent load**: the capacity check and INSERT are not in the same transaction snapshot. Two requests with different `user_id` can both pass the `available() > 0` check and both INSERT, exceeding capacity by 1.

The correct fix requires a serializable transaction or a `SELECT ... FOR UPDATE` lock on the slot row. NENE2's `DatabaseTransactionManagerInterface` does not expose isolation levels.

---

### 3. PSR-4 namespace / directory mismatch trips up project setup

**Severity:** Low (setup friction)

`composer.json` declared `"Booking\\": "src/"`. Source files were placed in `src/Booking/` with namespace `Booking\Slot\*`. This means PHP looks for `Booking\Slot\Foo` at `src/Slot/Foo.php` — not `src/Booking/Foo.php`. All 14 tests errored with "class not found" until the files were moved to `src/Slot/`.

This is a standard PSR-4 pitfall, not a NENE2 issue, but it surfaces early in every FT project. A clearer error message from Composer's autoloader would help beginners.

---

### 4. `DatabaseConstraintException` cannot be thrown manually for signaling

**Severity:** Low (API design note)

The initial implementation threw `DatabaseConstraintException` manually from the repository to signal "already reserved." This works (the class is instantiable) but is semantically wrong — `DatabaseConstraintException` signals a DB-layer constraint violation, not an application-level business rule. Using it as a control-flow signal blurs the layer boundary.

The resolution was to introduce a domain-specific `AlreadyReservedException` — correct design, but an extra class the developer must create. NENE2's howtos don't cover this pattern (domain exception vs. DB exception for signaling application state).

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 14 tests, 28 assertions — OK |
| PHPStan level 8 | No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

**難易度: 中（キャパシティチェックとDBエラーの区別に詰まりやすい）**

- 予約の追加・キャンセル・一覧は直感的に書ける。NENE2のルーティング・レスポンスAPIは問題なし。
- 「スロットが満杯」と「すでに予約済み」の2種類の409を返す必要があると気づくまでが難しい。初心者は一つの409で終わらせがち。
- PSR-4の名前空間/ディレクトリ対応ルールを知らないと `composer.json` の書き方で詰まる。

### 使ってみた印象

予約システムを実装するうえで、NENE2は必要なパーツ（JSON factory・Problem Details・ルーター）を過不足なく提供している印象。ただし「UNIQUE制約で区別できない2種類の409」という問題は、フレームワーク側ではどうにもならず、自分で設計する必要があった。設計の難しさがフレームワークの難しさと混在して感じられる。

### 楽しいか・気持ちいいか・快適か

- **楽しい点**: `testCapacityTwoAllowsTwoUsers()` と `testLostUpdateSimulation()` — 競合シナリオをテストで証明できる達成感は大きい。
- **不快な点**: 「duplicate-user か slot-full か」を区別するために余分なSELECTが必要という発見がフラストレーティング。DBのUNIQUEが「全部面倒みてくれる」と思っていたのに、違う。
- **快適な点**: `AlreadyReservedException` を作ってドメイン例外で区別する発想に切り替えられたら、コードがすっきりした。

### 簡単か

簡単ではない。「2種類の409」「TOCTOU」「PSR-4の罠」と3つの詰まりポイントがある。予約システムを実装しようとする初心者には難度が高い。

### また使いたいか

**はい** — コアのルーティング・エラーハンドリングは快適で、また使いたい。ただし予約システム特有の「競合制御」部分はフレームワークにガイドがなく、自力で解決する必要がある。

### 初心者に勧めたいか

CRUD部分は勧められる。キャパシティ制御・競合制御は中〜上級者向けとして位置づけ、howtoで誘導すべき。

---

## Notes

- `UNIQUE(slot_id, user_id)` は重複予約の最後の防壁として機能する（TOCTOU時の保険）。
- 容量チェックは `COUNT(reservations) < slots.capacity` をSQLのサブクエリで行うと1クエリで済む（FTではアプリ側で計算）。
- キャンセル後に再予約できることを `testCancelFreesCapacity()` で明示的にテストしておくと、キャンセル→再予約フローのデグレを防ぎやすい。
