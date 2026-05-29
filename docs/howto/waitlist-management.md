---
title: "ウェイティングリスト管理"
category: product
tags: [waitlist, state-machine, queue, admin]
difficulty: intermediate
related: [waitlist-system]
---

# ウェイティングリスト管理

位置ベースのウェイティングリスト（順番待ち）の実装ガイド。
動的ポジション計算・状態機械・IDOR 防止・管理者専用エンドポイントを解説する。

## 概要

- ユーザーがウェイティングリストに参加（任意メモ付き）
- **動的ポジション計算**: ポジションを DB に保存せず、`COUNT(*)` で都度算出
- 状態機械: `waiting` → `approved` / `declined`（一方向、取消不可）
- 待機中のユーザーのみ離脱可能（`approved`/`declined` 後は不可）
- 管理者は全エントリを一覧・承認・拒否（`X-Admin-Key` ヘッダー）
- ユーザー向けレスポンスに `user_id` を含めない（IDOR 防止）

## エンドポイント

| Method | Path | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/waitlist` | `X-User-Id` | ウェイティングリストに参加 |
| `GET` | `/waitlist/me` | `X-User-Id` | 自分のエントリとポジションを取得 |
| `DELETE` | `/waitlist/me` | `X-User-Id` | ウェイティングリストから離脱 |
| `GET` | `/waitlist` | `X-Admin-Key` | 全エントリ一覧（管理者用） |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | エントリを承認 |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | エントリを拒否 |

## データベース設計

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'waiting',
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id` に `UNIQUE` 制約を置くことで、1ユーザー1エントリを保証する。
ポジション用のカラムは持たない（動的計算）。

## 動的ポジション計算

待機中エントリのポジションを、`id` の相対順で計算する:

```sql
SELECT COUNT(*) FROM waitlist_entries
WHERE status = 'waiting' AND id <= :id
```

メリット:
- 離脱・承認・拒否のたびに全エントリを UPDATE しなくて済む
- 書き込み競合が発生しない
- `status` が `waiting` 以外のエントリは `null` を返す

```php
public function positionOf(WaitlistEntry $entry): ?int
{
    if ($entry->status !== WaitlistStatus::Waiting) {
        return null;
    }

    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM waitlist_entries
         WHERE status = 'waiting' AND id <= :id",
    );
    $stmt->execute(['id' => $entry->id]);

    return (int) $stmt->fetchColumn();
}
```

## 状態機械

```
waiting ──→ approved
        └─→ declined
```

- `waiting` は `approved` または `declined` にのみ遷移可能
- 一度 terminal 状態になったら変更不可（`isTerminal()` で判定）
- 待機中のユーザーのみ `DELETE /waitlist/me` で離脱可能

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## IDOR 防止

ユーザー向けエンドポイント（`/waitlist/me`）は `X-User-Id` ヘッダーで**自分のエントリのみ**取得できる。
他のユーザーの `user_id` をパスに渡す余地がなく、レスポンスにも `user_id` を含めない。

```php
/** ユーザー向けレスポンス（user_id なし） */
public function toPublicArray(): array
{
    return [
        'id'         => $this->id,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
    ];
}

/** 管理者向けレスポンス（user_id あり） */
public function toAdminArray(): array
{
    return [
        'id'         => $this->id,
        'user_id'    => $this->userId,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
    ];
}
```

## 管理者認証

`X-Admin-Key` ヘッダーを `hash_equals()` で定数時間比較する。
空の adminKey は常に `false`（fail-closed）:

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // fail-closed
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

## ルート順序

`GET /waitlist/me` は `GET /waitlist` よりも先に登録する。
後から登録すると `me` が `{id}` として捕捉される可能性があるため:

```php
$this->router->post('/waitlist',            $this->handleJoin(...));
$this->router->get('/waitlist/me',          $this->handleMe(...));      // /waitlist より先
$this->router->delete('/waitlist/me',       $this->handleLeave(...));
$this->router->get('/waitlist',             $this->handleAdminList(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->post('/waitlist/{id}/decline', $this->handleDecline(...));
```

## X-User-Id バリデーション

整数オーバーフロー・ゼロ・負数・非数値を防ぐ:

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');

    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

## セキュリティポイント

| 脅威 | 対策 |
|---|---|
| IDOR | `/waitlist/me` で自分のみ、レスポンスに `user_id` を含めない |
| 管理者キー盗聴 | `hash_equals()` で定数時間比較 |
| 整数オーバーフロー | `strlen > 18` ガード |
| 重複参加 | `UNIQUE(user_id)` 制約 → 409 |
| 状態遷移の不正 | `isTerminal()` で terminal 後の変更を禁止 |
| SQL インジェクション | PDO プリペアドステートメント |

## レスポンス例

```json
// POST /waitlist (201)
{
    "entry": { "id": 1, "status": "waiting", "note": "VIP希望", "created_at": "..." },
    "position": 1
}

// GET /waitlist/me — approved の場合 (200)
{
    "entry": { "id": 1, "status": "approved", "note": "VIP希望", "created_at": "..." },
    "position": null
}

// GET /waitlist (admin, 200)
{
    "data": [
        { "id": 1, "user_id": 101, "status": "approved", "note": "VIP希望", ... },
        { "id": 2, "user_id": 102, "status": "waiting",  "note": null,      ... }
    ],
    "total": 2
}
```

## 関連ガイド

- [システムアナウンスメント管理](system-announcement-management.md) — 管理者キー認証パターン（同様の `hash_equals()` 使用）
- [プライバシーコンセント管理](privacy-consent-management.md) — UPSERT と冪等操作
- [ソフトデリート](soft-delete.md) — 削除フラグパターン（離脱は物理削除）
- [予約ダブルブッキング防止](prevent-double-booking.md) — UNIQUE 制約による競合防止
