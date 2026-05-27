# ユーザー招待システム

メールで新規ユーザーを招待し、有効期限を強制し、トークンベースの招待で不正使用を防ぎます。

## 概要

招待システムでは既存ユーザーが新しいアカウント作成をスポンサーできます。主要な不変条件は以下の通りです:

- トークンは暗号的にランダムで推測不可能です。
- 有効期限は読み取り時と書き込み時の両方でチェックされます。
- 招待はオリジナルの招待者のみがキャンセルできます。
- 受け入れ済みとキャンセル済みのトークンは再利用できません。

## データベーススキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## トークン生成

常に `bin2hex(random_bytes(32))` を使用してください — 64 文字の hex、256 ビットのエントロピー:

```php
$token = bin2hex(random_bytes(32));
```

連番 ID、UUID、または短い文字列を招待トークンとして使用しないでください。推測可能なトークンにより攻撃者が任意の保留中の招待を受け入れることができます。

## 招待の送信

招待を作成する前に、対象のメールアドレスがすでに登録されていないことを確認してください:

```php
// すでに登録済みのユーザーへの招待を防ぐ
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

登録済みのメールアドレスを招待しようとすると 409 を返すことで、招待者に登録状態を明かしてしまいます。招待者が信頼できるユーザーである招待制システムでは許容できます。完全に公開されたシステムでは、レスポンスを 202 に統一することを検討してください。

## 招待の受け入れ

有効期限はステータスのチェックの**前に**確認してください — 保留中だが期限切れの招待は 409 ではなく 410 を返す必要があります:

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` は現在のタイムスタンプ文字列を直接比較します — SQLite の datetime 文字列は `Y-m-d H:i:s` として保存されると辞書順にソートされます:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## 招待のキャンセル

所有権はリクエストボディの `inviter_id` を使用して強制されます（この最小限の例ではセッション/JWT ミドルウェアがないため）。本番環境では代わりに認証済みトークンからアクターを取得してください:

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

所有権チェックが失敗した場合は 404 ではなく 403 を返してください — 招待の存在を隠すことで攻撃者が実際のトークンを見つけたという事実を隠しますが、リソースは見つかったがアクションが禁止されているため、ここでは 403 が正しいセマンティクスです。

## ステータスマシン

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

招待が `pending` を離れると、それ以降の遷移は許可されません。`accepted` または `cancelled` の招待を受け入れようとすると 409 が返されます。

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| トークンエントロピー | `bin2hex(random_bytes(32))` — 256 ビット |
| トークンの一意性 | `invitations.token` の UNIQUE 制約 |
| 読み取り時の有効期限 | 書き込みの前にハンドラーでチェック |
| 再利用防止 | accept/cancel 前の `isPending()` ガード |
| 所有者の強制 | `inviter_id` 等値チェック → 403 |
| メール PII 漏洩なし | 409 ボディに招待メールを露出しない |
| SQL インジェクション | 全体を通じた PDO パラメーター化クエリ |

## ルートサマリー

| メソッド | パス | 説明 |
|---|---|---|
| `POST`   | `/users`                        | ユーザーアカウントを作成する |
| `POST`   | `/users/{id}/invitations`       | 招待を送る                   |
| `GET`    | `/invitations/{token}`          | 招待を表示する               |
| `POST`   | `/invitations/{token}/accept`   | 招待を受け入れる             |
| `DELETE` | `/invitations/{token}`          | 招待をキャンセルする         |
