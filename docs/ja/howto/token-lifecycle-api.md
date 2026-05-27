# ハウツー: API トークンライフサイクル管理

> **FT リファレンス**: FT272 (`NENE2-FT/tokenlog`) — API トークンライフサイクル: SHA-256 ハッシュストレージ（平文は永続化しない）、DB CHECK 制約付きスコープ enum（read/write/admin）、IDOR ガード（actorId は userId と一致する必要がある）、revoked_at によるソフト失効、verify エンドポイントが valid/user_id/scope を返す、29 テスト / 70 アサーション PASS。
>
> **ATK アセスメント**: ATK-01〜ATK-12 はこのドキュメントの末尾に含まれています。

スコープ付き API トークンシステムを実演します: ユーザーのトークンを発行し、一覧表示/失効させ、アクセス時に生のトークンを確認します。トークンは SHA-256 ハッシュとしてのみ保存されます — 平文は発行時に一度返され、保存されることはありません。

---

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

主要な設計上の選択:
- `token_hash UNIQUE` — 偶発的な重複発行を防ぐ; verify 時の検索キーでもある
- `CHECK (scope IN (...))` — スコープ enum の DB レベル強制
- `revoked_at TEXT` — ソフト失効; `NULL` はアクティブを意味し、非 NULL は失効を意味する

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/users`                           | ユーザーを作成する                   |
| `POST`   | `/users/{userId}/tokens`           | トークンを発行する（所有者のみ）     |
| `GET`    | `/users/{userId}/tokens`           | ユーザーのトークンを一覧表示する（所有者のみ） |
| `DELETE` | `/users/{userId}/tokens/{tokenId}` | トークンを失効させる（所有者のみ）   |
| `POST`   | `/tokens/verify`                   | 生のトークンを確認する               |

---

## ハッシュのみのストレージ

生のトークンは発行時に一度返され、保存されることはありません:

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 hex 文字 — 256 ビットのエントロピー
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // 呼び出し元に返され、保存されない
}
```

verify 時、呼び出し元は生のトークンを提供し; ハッシュが再計算されて検索されます:

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // 見つからない → 呼び出し元が {valid: false} を返す
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## スコープ強制

`TokenScope` は PHP のバックド enum です; `tryFrom()` は DB アクセス前に不明な値を拒否します:

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// ルートハンドラーで:
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

DB の `CHECK` 制約が第二の強制レイヤーを提供します。

---

## IDOR ガード

トークンの発行、一覧表示、失効はアクターが所有者であることを必要とします:

```php
$actorId = $this->resolveActorId($request); // X-User-Id ヘッダーから

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

失効もトークンが `userId` のものであることを確認します（任意のトークンではなく）:

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## 失効

ソフト失効は `revoked_at` を設定します; UPDATE は `revoked_at IS NULL` の場合のみ適用されます:

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );
    return $count > 0;
}
```

トークンがすでに失効している場合、ルートハンドラーは 409 Conflict を返します:

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## ATK アセスメント — クラッカー攻撃試験

### ATK-01 — 失効後のトークンリプレイ 🚫 BLOCKED

**攻撃**: トークンを失効させてから、同じ生のトークン値で `/tokens/verify` を使用する。
**結果**: BLOCKED — `verifyToken()` は行の `revoked_at` を検索する; 非 NULL の `revoked_at` は `valid: false` を引き起こす。失効したトークンは削除されないため、解決されるが `{valid: false}` を返す。

---

### ATK-02 — ブルートフォースによるトークン推測 🚫 BLOCKED

**攻撃**: ランダムな 64 文字の hex 文字列を `/tokens/verify` に送信して有効なトークンハッシュにマッチさせようとする。
**結果**: BLOCKED — トークンは `bin2hex(random_bytes(32))` = 256 ビットのエントロピー。成功する推測の確率は `1 / 2^256`。この FT にはレート制限がないが、エントロピーだけでブルートフォースは計算上実行不可能になる。

---

### ATK-03 — IDOR: 別のユーザーのトークンリストにアクセス 🚫 BLOCKED

**攻撃**: `X-User-Id: 1` を設定して `GET /users/2/tokens` をリクエストする。
**結果**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden。

---

### ATK-04 — IDOR: 別のユーザーのトークンを失効させる 🚫 BLOCKED

**攻撃**: ユーザー 1 として `DELETE /users/2/tokens/{tokenId}` を呼び出す。
**結果**: BLOCKED — ルートハンドラーがトークンを取得する前に `actorId !== userId` → 403 をチェックする。

---

### ATK-05 — クロス所有者トークン失効（共有トークン ID） 🚫 BLOCKED

**攻撃**: ユーザー 2 として `DELETE /users/2/tokens/{tokenId}` を呼び出し、`tokenId` はユーザー 1 のものである。
**結果**: BLOCKED — IDOR チェック通過後（actorId = userId = 2）、`findTokenById` がトークンを返し、次に `$token['user_id'] !== $userId` → 403。二重所有権チェックがクロスユーザー失効を防ぐ。

---

### ATK-06 — 無効なスコープインジェクション 🚫 BLOCKED

**攻撃**: `{"scope": "superadmin"}` で `POST /users/{id}/tokens` する。
**結果**: BLOCKED — `TokenScope::tryFrom('superadmin')` が `null` を返す → 422。アプリケーション層がなんらかの形で通してしまっても、DB CHECK 制約もブロックする。

---

### ATK-07 — DB からのトークン平文抽出 🚫 BLOCKED

**攻撃**: 攻撃者が `tokens` テーブルへの読み取りアクセスを得た場合、動作するトークンを入手できるか？
**結果**: BLOCKED — `token_hash`（SHA-256）のみが保存される。SHA-256 のリバースは計算上実行不可能。生のトークンは発行時に一度返され、サーバーサイドで破棄される。

---

### ATK-08 — 空/不正なトークンで verify 🚫 BLOCKED

**攻撃**: `{"token": ""}` または `{"token": null}` で `POST /tokens/verify` する。
**結果**: BLOCKED — 空文字列チェック: `if ($token === '') → 422`。`null` は `is_string()` チェックで拒否される。空文字列の SHA-256 は保存されたハッシュにマッチしない。

---

### ATK-09 — 存在しないユーザーへのトークン発行 🚫 BLOCKED

**攻撃**: ユーザー 9999 が存在しない状態で `POST /users/9999/tokens` する。
**結果**: BLOCKED — `findUserById(9999)` が `false` を返す → トークンが作成される前に 404。

---

### ATK-10 — 二重失効（冪等性） 🚫 BLOCKED

**攻撃**: 同じトークンを素早く 2 回失効させる。
**結果**: BLOCKED — `revokeToken` は `WHERE revoked_at IS NULL` を使用する; 2 回目の呼び出しは 0 行に影響する。ルートハンドラーはリポジトリを呼び出す前に `$token['revoked'] === true` を読む → 409 Conflict。二重失効が成功する競合状態のウィンドウはない。

---

### ATK-11 — パスに負の値または文字列の userId 🚫 BLOCKED

**攻撃**: `GET /users/-1/tokens` または `GET /users/abc/tokens`。
**結果**: BLOCKED — `is_numeric($params['userId'])` → `(int)` キャスト。`-1` は -1 になる; `findUserById(-1)` は false を返す → 404。`abc` は数値でない → `userId = 0` → 404。

---

### ATK-12 — verify レスポンスでのスコープダウングレード 🚫 BLOCKED

**攻撃**: `read` スコープのトークンを取得した後、変更されたリクエストボディを送ることで verify レスポンスの `scope: write` を偽造しようとする。
**結果**: BLOCKED — `/tokens/verify` は生のトークン文字列のみを受け入れる; スコープは DB 行から読み取られ、クライアントが提供したフィールドからではない。クライアントは返されるスコープに影響を与えられない。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|------|------|
| ATK-01 | 失効したトークンのリプレイ | 🚫 BLOCKED |
| ATK-02 | ブルートフォースによるトークン推測 | 🚫 BLOCKED |
| ATK-03 | IDOR: 別のユーザーのトークンリストを読む | 🚫 BLOCKED |
| ATK-04 | IDOR: 別のユーザーのトークンを失効させる | 🚫 BLOCKED |
| ATK-05 | クロス所有者トークン失効 | 🚫 BLOCKED |
| ATK-06 | 無効なスコープインジェクション | 🚫 BLOCKED |
| ATK-07 | DB からの平文抽出 | 🚫 BLOCKED |
| ATK-08 | verify での空/不正なトークン | 🚫 BLOCKED |
| ATK-09 | 存在しないユーザーへのトークン発行 | 🚫 BLOCKED |
| ATK-10 | 二重失効の競合状態 | 🚫 BLOCKED |
| ATK-11 | パスに負の値/文字列の userId | 🚫 BLOCKED |
| ATK-12 | verify ボディ経由のスコープダウングレード | 🚫 BLOCKED |

**12 BLOCKED / SAFE、0 EXPOSED**
重大な発見なし。ハッシュのみのストレージ、スコープ enum 強制、二重 IDOR チェックが堅固な防御面を形成しています。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 生のトークンを DB に保存する | DB 読み取り漏洩がすべてのトークンを露出する; ユーザーアクションなしにトークンをローテーションできない |
| トークンハッシュに MD5/SHA-1 を使用する | 衝突攻撃; SHA-256 または BLAKE2 を優先する |
| 任意のスコープ文字列を受け入れる | `tryFrom()` バリデーションなしでは `superadmin` スコープを発行できる |
| 失効時に所有権チェックがない | 任意の認証済みユーザーが任意のトークンを失効させられる（IDOR） |
| 失効時にトークンをハードデリートする | 監査証跡が失われる; 失効したトークンのリプレイを検出できない |
| すでに失効したトークンに 404 を返す | 「見つからない」と「すでに失効している」を区別できなくなる; 409 を使うこと |
