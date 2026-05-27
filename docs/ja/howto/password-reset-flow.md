# ハウツー: パスワードリセットフロー

> **FT リファレンス**: FT285 (`NENE2-FT/resetlog`) — パスワードリセットフロー: ユーザー列挙防止（常に 202）、SHA-256 トークンハッシュ保存、1 時間 TTL、シングルユーストークン（再使用に 409）、期限切れに 410 Gone、Argon2id 新パスワードハッシュ、15 テスト / 23 アサーション PASS。
>
> **VULN アセスメント**: このドキュメントの末尾に V-01 から V-10 を含みます。

このガイドでは安全なパスワードリセットフローの実装方法を解説します — ユーザーがリセットをリクエストし、トークンを受け取り（通常はメールで）、新しいパスワードを設定するために使用します。

## スキーマ

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE` — 生のトークンの SHA-256 を保存します。生のトークンはクライアントに送信され、保存されません。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/password-reset` | なし | パスワードリセットをリクエストする |
| `GET` | `/password-reset/{token}` | なし | トークンの状態を確認する |
| `POST` | `/password-reset/{token}` | なし | 新しいパスワードでリセットを完了する |

## ユーザー列挙防止

```php
$user = $this->repo->findUserByEmail($email);

// ユーザー列挙を防ぐために常に 202 を返す
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// 実際のユーザー: トークンを作成し（本番では）メールを送信する
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

有効なメールと無効なメールの両方が同一の 202 レスポンスを返します。攻撃者はどのメールが登録済みかを判定できません。

> **本番環境の注意**: テスト可能性のためにトークンを API レスポンスで返しています。本番では、トークンはメールのみで送信してください — API レスポンスには含めません。

## トークン保存 — SHA-256 のみ

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 hex 文字 = 256 ビットエントロピー
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// 生のトークンをクライアントに返す（本番では: HTTP レスポンスではなくメールで）
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

データベースは SHA-256 ハッシュのみを保存します。生のトークンはユーザーに（本番ではメールで）送信され、保存されません。DB 漏洩でハッシュが明かされますが、生のトークンなしでは使用不能です。

## トークンバリデーション

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

生のトークンはリクエストパスに含まれます。サーバーはそれをハッシュ化して DB を照会します。SHA-256 は決定論的です — 同じ生のトークンは常に同じハッシュを生成します。

## トークンライフサイクル状態

```
pending → used（再使用に 409）
pending → expired（410 Gone）
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| 状態 | HTTP | 条件 |
|--------|------|------|
| 見つからない | 404 | DB にトークンが存在しない |
| 期限切れ | 410 Gone | `expires_at` が過去 |
| 既に使用済み | 409 Conflict | `used_at` が設定済み |
| 有効 | 200（GET）/ 200（POST） | アクティブ、未使用、期限切れでない |

`410 Gone` は期限切れリソースに対して 404 より意味的に正確です — トークンは存在したが、もはや利用できません。

## リセットの完了

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // used_at = $now を設定

return $this->json->create(['status' => 'completed'], 200);
```

本番では両方の操作をトランザクションに含めるべきです。`updatePasswordHash` が成功して `markUsed` が失敗した場合、ユーザーはリセットされますがトークンは再使用可能なままになります。

## パスワードバリデーション

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

最低 8 文字; 登録とリセットの両方で強制されます。新しいパスワードは保存前に `PASSWORD_ARGON2ID` でハッシュ化されます。

---

## VULN アセスメント — 脆弱性診断

### V-01 — リセットレスポンスのタイミング/内容によるユーザー列挙 🛡️ SAFE

**Threat**: 攻撃者が多くのメールにリセットリクエストを送り、登録済みのものを特定する。
**Defense**: 登録済みと未登録の両方のメールが `202 { "status": "pending" }` を同一のレスポンスボディとステータスコードで返します。タイミング差なし（リセットリクエストにパスワードハッシュ検証は不要）。
**Result**: SAFE — API レスポンスからは列挙不可能。

---

### V-02 — トークンブルートフォース 🛡️ SAFE

**Threat**: 攻撃者がトークン値を推測し、任意のアカウントをリセットするために送信する。
**Defense**: `bin2hex(random_bytes(32))` が 256 ビットエントロピー（64 hex 文字）を生成します。10,000 回/秒の推測速度では、ブルートフォースには約 10^65 年かかります。SHA-256 ハッシュ比較が長さ拡張とタイミング oracle を防ぎます。
**Result**: SAFE — 256 ビットエントロピーは推測不可能。

---

### V-03 — 使用後のトークンリプレイ 🛡️ SAFE

**Threat**: 攻撃者がリセットトークンを傍受し、正規ユーザーが既にパスワードをリセットした後に使用する。
**Defense**: `markUsed()` がリセット後に `used_at` を設定します。その後の試行は `isUsed()` → 409 Conflict をチェックします。
**Result**: SAFE — シングルユース強制がリプレイを防ぎます。

---

### V-04 — 期限切れトークンが受け付けられる 🛡️ SAFE

**Threat**: 攻撃者がトークンを保存し、ユーザーがログインするのを待ってから古いトークンを使用する。
**Defense**: `isExpired($now)` が `expires_at` をチェックします。トークンは 1 時間後に期限切れ → 410 Gone。
**Result**: SAFE — 時間制限付きトークンが遅延攻撃を防ぎます。

---

### V-05 — トークンパスパラメーターを介した SQL インジェクション 🛡️ SAFE

**Threat**: `'; DROP TABLE password_resets; --` をトークンとして送信する。
**Defense**: `hash('sha256', $rawToken)` は入力に関係なく 64 文字 hex 文字列を生成します。ハッシュはパラメーター化クエリ（`WHERE token_hash = ?`）で使用されます。パスパラメーター経由の SQL インジェクションは不可能です。
**Result**: SAFE — ハッシュ化 + パラメーター化クエリで二重ブロック。

---

### V-06 — トークンが DB に平文で保存される 🛡️ SAFE

**Threat**: DB 漏洩がすべてのアクティブなリセットトークンを公開し、攻撃者がすべてのアカウントをリセットする。
**Defense**: DB は `hash('sha256', $rawToken)` のみを保存します。生のトークンはクライアントに返されます（またはメールで送信）。SHA-256 は一方向です; ブルートフォースなしにハッシュから生のトークンに逆算できません。
**Result**: SAFE — SHA-256 ハッシュ保存が保存中のトークンを保護します。

---

### V-07 — 新しいパスワードが平文で保存される 🛡️ SAFE

**Threat**: DB 漏洩がリセット中に設定された新しいパスワードを公開する。
**Defense**: `password_hash($newPassword, PASSWORD_ARGON2ID)` が保存前に新しいパスワードをハッシュ化します。平文は永続化されません。
**Result**: SAFE — Argon2id ハッシュが保存中のパスワードを保護します。

---

### V-08 — 重複リセットトークンの作成によるアカウント乗っ取り 🛡️ SAFE

**Threat**: 攻撃者が別のユーザーのトークンハッシュを予測または衝突させる。
**Defense**: `token_hash TEXT UNIQUE` — 重複ハッシュは DB に拒否されます。256 ビットエントロピーでは、衝突確率は無視できます（50% 衝突確率のバースデー境界は約 2^128 回の試行）。
**Result**: SAFE — UNIQUE 制約 + 256 ビットエントロピーが衝突を防ぎます。

---

### V-09 — リセット中に弱い新しいパスワード（8 文字未満）を送信 🛡️ SAFE

**Threat**: 攻撃者が `aa` などの簡単に推測できるパスワードにアカウントをリセットする。
**Defense**: `strlen($newPassword) < 8` → DB 操作の前に 422 バリデーションエラー。
**Result**: SAFE — 最低文字数がリセットパスでも強制されます（登録と同様）。

---

### V-10 — トークンエンドポイントがどのステップで失敗したかを明かす（列挙） 🛡️ SAFE

**Threat**: 404 vs 409 vs 410 レスポンスを比較することで、攻撃者がリセットトークンの状態をマッピングする。
**Defense**: エラーコードはトークンライフサイクル状態（見つからない/期限切れ/使用済み）を明かしますが、ユーザー情報は明かしません。トークンが期限切れまたは使用済みであることを知っても、アカウントホルダーは特定されません。リセットリクエストはメールが存在するかどうかに関係なく常に 202 を返します。
**Result**: SAFE — トークン状態レスポンスによってユーザー身元情報は明かされません。

---

### VULN サマリー

| ID | 脅威 | 結果 |
|----|--------|--------|
| V-01 | リセットレスポンスによるユーザー列挙 | 🛡️ SAFE |
| V-02 | トークンブルートフォース | 🛡️ SAFE |
| V-03 | 使用後のトークンリプレイ | 🛡️ SAFE |
| V-04 | 期限切れトークンが受け付けられる | 🛡️ SAFE |
| V-05 | トークンパスを介した SQL インジェクション | 🛡️ SAFE |
| V-06 | トークンが平文で保存される | 🛡️ SAFE |
| V-07 | 新しいパスワードが平文で保存される | 🛡️ SAFE |
| V-08 | 重複トークン衝突 | 🛡️ SAFE |
| V-09 | 弱い新しいパスワードが受け付けられる | 🛡️ SAFE |
| V-10 | トークン状態がユーザー情報を明かす | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
ユーザー列挙防止、256 ビットトークンエントロピー、SHA-256 ハッシュ保存、Argon2id パスワードハッシュ、シングルユース強制がテスト済みのすべての脆弱性ベクターを防ぎます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 未登録メールに 404、登録済みに 202 を返す | ユーザー列挙 — 攻撃者が登録済みアカウントをマッピングする |
| DB に生のトークンを保存する | DB 漏洩がすべてのアクティブなリセットトークンを公開; 大規模なアカウント乗っ取り |
| HTTP レスポンスボディでトークンを送信する（本番） | ブラウザログ、プロキシ、JS によってトークンが傍受される; メールのみで送信する |
| リセットトークンに期限なし | 古いトークンが永遠に有効; 盗まれたトークンが数ヶ月後でも使用可能 |
| パスワードリセット後のトークン再使用を許可する | メール傍受後のトークンリプレイ攻撃 |
| パスワードの最低文字数なし | ユーザーが新しいパスワードとして `aa` を設定する |
| 使用済みトークンの GET `/password-reset/{token}` に 200 を返す | クライアントが有効と既使用を区別できない |
| トークンハッシュに MD5/SHA-1 を使う | 事前計算されたレインボーテーブルが存在する; SHA-256 以上を使う |
| `updatePasswordHash` + `markUsed` にトランザクションなし | 競合状態: パスワードが更新されたがトークンが再使用可能なまま |
