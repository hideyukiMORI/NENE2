# ハウツー: リソース予約システム

## 概要

このガイドでは NENE2 でリソース予約 API の構築について説明します。定員管理、二重予約防止、ユーザーごとの IDOR 分離、管理者によるキャンセルなどの機能を含みます。

**参照実装**: `../NENE2-FT/bookinglog/`

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

主要な制約:
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — ユーザーごとにスロットごとに 1 つの予約。
- `cancelled` ソフトデリートフラグ — 再予約を許可しながら履歴を保持します。
- 定員はクエリ時に確認します（アクティブな予約数 vs resource.capacity）。

---

## ルートテーブル

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `GET` | `/resources` | なし | すべてのリソースを一覧表示する |
| `POST` | `/resources` | 管理者 | リソースを作成する |
| `POST` | `/bookings` | ユーザー | スロットを予約する |
| `GET` | `/bookings` | ユーザー | 自分の予約を一覧表示する |
| `GET` | `/bookings/{id}` | ユーザー | 1 つの予約を取得する |
| `DELETE` | `/bookings/{id}` | ユーザー/管理者 | 予約をキャンセルする |

---

## 二重予約防止

まず、ユーザーがすでにこのスロットを持っているか確認します（アプリケーションレベル）:

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

次に定員を確認します:

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## IDOR 分離

ユーザーは自分の予約のみ読み取り/キャンセルできます。存在を明かさないために 404 を返します（403 ではない）:

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## X-User-Id なしの管理者キャンセル

管理者は自分のユーザー ID を提供せずに任意の予約をキャンセルできます:

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## バリデーションルール

| フィールド | ルール |
|-------|------|
| `resource_id` | `is_int()` + 正の整数 |
| `slot_date` | 正規表現 `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0〜23 |
| `capacity` | `is_int()` + 正の整数 |
| `name` | 空でない文字列 |

---

## HTTP ステータスコード

| 状況 | ステータス |
|-----------|--------|
| リソース作成済み | 201 |
| 予約確認済み | 201 |
| 予約が見つかった / 一覧 | 200 |
| X-User-Id なし | 400 |
| 無効なフィールド型 | 422 |
| 無効な日付フォーマット | 422 |
| slot_hour が 0〜23 の範囲外 | 422 |
| リソースが見つからない | 404 |
| 予約が見つからない | 404 |
| 管理者キーなし | 403 |
| 自分の予約をキャンセル | 200 |
| 他のユーザーの予約をキャンセル | 403 |
| 二重予約 | 409 |
| 定員満杯 | 409 |

---

## カバーされた VULN パターン

| VULN | パターン | 防御 |
|------|---------|---------|
| A | IDOR: ユーザーが他の予約を見る | `WHERE user_id = :uid` + 404 |
| B | 負の resource_id | `is_int() + > 0` チェック |
| C | ゼロの slot_hour（深夜） | 0〜23 の範囲で 0 を許可 |
| D | slot_date への SQL インジェクション | 正規表現バリデーション + パラメーター化クエリ |
| E | 文字列 resource_id の型の混乱 | `is_int()` 厳密チェック |
| F | 二重予約 | INSERT 前の存在チェック |
| G | 定員オーバーフロー | COUNT vs 定員チェック |
| H | X-User-Id なし | メッセージ付きの 400 |
| I | 他のユーザーの予約をキャンセル | `user_id` 所有権チェック → 403 |
| J | 一覧が他のユーザーのデータを漏洩 | `WHERE user_id = :uid` |
| K | 管理者が任意の予約をキャンセル | `isAdmin` で所有権バイパス |
| L | slot_hour = 24（範囲外） | `$hour > 23` → 422 |
