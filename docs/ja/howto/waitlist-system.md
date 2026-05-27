# ハウツー: ウェイトリストシステム

> **FT リファレンス**: FT287 (`NENE2-FT/waitlistlog`) — ウェイトリストシステム: UNIQUE(user_id) による 1 エントリ制約、waiting→approved/declined ステートマシン、isTerminal() ガード、ルートキャプチャを防ぐための /{id} より前の /waitlist/me 登録、X-Admin-Key 認証、待機ポジションの追跡、39 テスト / 98 アサーション PASS。

このガイドでは、ユーザーがキューに参加し管理者がエントリを承認または拒否するウェイトリストシステムの構築方法を説明します。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- ユーザーごとに 1 エントリ
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- オプションのユーザー提供メモ（最大 500 文字）
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` は DB レベルでユーザーごとに 1 エントリを強制します — 競合状態のためのアプリケーション層チェックは不要です。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST`   | `/waitlist`               | `X-User-Id`    | ウェイトリストに参加する         |
| `GET`    | `/waitlist/me`            | `X-User-Id`    | 自分のステータス + ポジションを取得する |
| `DELETE` | `/waitlist/me`            | `X-User-Id`    | ウェイトリストを離脱する         |
| `GET`    | `/waitlist`               | `X-Admin-Key`  | 管理者: すべてのエントリを一覧表示する |
| `POST`   | `/waitlist/{id}/approve`  | `X-Admin-Key`  | 管理者: エントリを承認する       |
| `POST`   | `/waitlist/{id}/decline`  | `X-Admin-Key`  | 管理者: エントリを拒否する       |

## ルート登録順序

パスパラメーターがリテラル文字列 `"me"` をキャプチャするのを防ぐために、`/waitlist/me` は `/waitlist/{id}` の**前に**登録する必要があります:

```php
// 正しい: 静的パスを動的パスの前に登録
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// 間違い: {id} が "me" をキャプチャする
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // 到達不能
```

## ステータスのライフサイクル

```
waiting ──────→ approved（終端）
       └──────→ declined（終端）
```

承認または拒否されると、エントリは別の状態に遷移できません。`isTerminal()` メソッドがこれをガードします:

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

## 重複時の 409 での参加

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

リポジトリは `user_id` がすでに存在する場合（`DatabaseConstraintException` からキャッチ）に `null` を返します。レスポンスは 409 Conflict になります。

## ポジションの追跡

```php
$position = $this->repository->positionOf($entry);

// positionOf() は status='waiting' かつ id <= $entry->id のエントリを数える
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

ポジションは `waiting` キューでの 1 ベースの順位です。承認/拒否されたエントリはカウントされません。これによりユーザーは意味のある待機順を把握できます。

## match を使った管理者の状態遷移

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` は網羅的です — `default` ケースはリポジトリからの予期しない戻り値をキャッチします。

## 離脱（待機中のみ）

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

承認または拒否されると、ユーザーは離脱できません — 決定が記録されます。これによりシステムのゲーミング（追跡を避けるための承認後離脱）を防ぎます。

## 管理者認証

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // フェイルクローズド: キーが設定されていない → 管理者アクセスなし
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` はタイミング攻撃を防ぎます。空の管理者キーは常に false を返します（フェイルクローズド）。

## メモのバリデーション

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

メモはオプションです（absent/empty の場合は null）、最大 500 文字、長すぎる場合は切り詰められます（拒否ではなく）。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(user_id)` 制約がない | 並行参加で重複エントリが作成される; 競合状態 |
| `/me` より前に `/{id}` を登録する | `/waitlist/me` が到達不能になる — `"me"` をキャプチャする `{id}` にマッチする |
| 終端状態からの遷移を許可する | アクセス付与後に承認済みエントリが拒否される; ステートマシンが壊れる |
| 終端状態からの離脱を許可する | 承認済みユーザーが離脱する; アクセス付与が孤立する |
| すべてのエントリを `id ASC` でカウントしてポジションを返す | 承認/拒否済みユーザーをカウントする; ポジション番号が誤解を招く |
| 管理者キーを DB に保存する | キーのローテーションに DB 更新が必要; env var を使用すること |
| 管理者キーの比較に `==` を使用する | タイミング攻撃でキーが 1 文字ずつ明かされる |
| 管理者のフェイルクローズドがない | env の空キーが未認証の管理者アクセスを許可する |
| 制限超過のメモを拒否する | UX: メモのようなソフトメタデータは拒否より切り詰めの方がユーザーにやさしい |
