# ハウツー: ワンタイムシークレット API & ATK-01〜12 クラッカー攻撃テスト

> **NENE2 フィールドトライアル 184** — クラッカー攻撃テストサイクル（ATK-01〜12）。
> トークン自体が認証情報。アトミックな消費が競合状態を防ぎます。

---

## このトライアルが実証すること

ワンタイムシークレットは 1 回だけ読める暗号化メッセージを保存します。
最初の読み取りが成功すると、シークレットは永久に消費されます。

セキュリティ要件:
1. **256 ビットトークンエントロピー** — ブルートフォースは計算上不可能
2. **アトミックな消費** — `UPDATE WHERE consumed=0` でダブルリードの競合状態を防ぐ
3. **IDOR 防止** — 削除にはトークンとユーザーオーナーシップの両方が必要
4. **マス代入ブロック** — consumed/token/created_at はサーバー側のみ
5. **型安全性** — V::str() / V::userId() / V::queryInt() が非文字列入力を拒否

---

## API

| メソッド | パス | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/secrets` | X-User-Id | ワンタイムシークレットを作成する |
| `GET` | `/secrets` | X-User-Id | 自分のシークレット一覧（メタデータのみ、メッセージなし） |
| `GET` | `/secrets/{token}` | — | 読み取り + 消費（トークン自体が認証情報） |
| `DELETE` | `/secrets/{token}` | X-User-Id | 読み取り前にキャンセル（オーナーのみ） |

---

## ATK-01〜12 結果

| ID | 攻撃ベクター | 防御 | 結果 |
|---|---|---|---|
| ATK-01 | トークンへの SQL インジェクション | PDO パラメーター化クエリ | ✅ PASS |
| ATK-02 | IDOR クロスユーザー削除 | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | マス代入（body に `consumed=1`） | サーバー側フィールドのみ | ✅ PASS |
| ATK-04 | メッセージへの XSS ペイロード | JSON API — HTML レンダリングなし | ✅ PASS |
| ATK-05 | ダブルエンコード / 不正なトークン | `/^[0-9a-f]{64}$/` フォーマットチェック | ✅ PASS |
| ATK-06 | 読み取りでの認証バイパス | トークン自体が認証情報 — 設計による | ✅ PASS |
| ATK-07 | message/password を非文字列で送信 | `V::str()` が `is_string()` を強制 | ✅ PASS |
| ATK-08 | limit/offset に 20 桁の数値オーバーフロー | `V::queryInt()` の strlen > 18 ガード | ✅ PASS |
| ATK-09 | limit パラメーターへの ReDoS | `ctype_digit()` — O(n)、バックトラッキングなし | ✅ PASS |
| ATK-10 | トークンへのブルートフォース | `random_bytes(32)` = 2^256 エントロピー | ✅ PASS |
| ATK-11 | 競合状態のダブルリード | `UPDATE WHERE consumed=0` + rowCount チェック | ✅ PASS |
| ATK-12 | X-User-Id へのヘッダーインジェクション | `V::userId()` が `ctype_digit()` を強制 | ✅ PASS |

**12/12: PASS**

---

## コアパターン: アトミックな消費

重要なセキュリティ不変条件 — シークレットは 1 回だけ読めます:

```php
// SecretRepository::consumeByToken()

// ステップ 1: シークレットを取得する（通常の SELECT — ガードではない）
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// ステップ 2: consumed フラグをチェックする（一般的なケースの早期終了）
if ($secret['consumed']) return null;

// ステップ 3: アトミックな UPDATE — これが実際のガード
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// ステップ 4: rowCount() === 0 は別の読み取りが競争に勝ったことを意味する
if ($update->rowCount() === 0) {
    return null; // SELECT と この UPDATE の間に誰かが消費した
}

// ステップ 5: 勝利 — シークレットを返す
return Secret::fromRow($secret);
```

**なぜこれが機能するか**: SQLite とほとんどの RDBMS は `UPDATE WHERE consumed=0` がアトミックであることを保証します。
並行ライターの 1 つだけが `consumed` を 0→1 に変更できます。敗者の `rowCount()` は 0 を返します。

---

## トークン生成

```php
$token = bin2hex(random_bytes(32)); // 64 hex 文字 = 32 バイト = 256 ビット
```

- `random_bytes()` は OS の CSPRNG（`/dev/urandom` と同等）を使用します
- 10^12 回/秒のレートで 2^256 トークンをブルートフォースするには約 10^60 年かかります
- トークンは DB で一意です（`UNIQUE` 制約）

---

## トークンフォーマットバリデーション

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// 拒否: 大文字 hex、パストラバーサル ../../、URL エンコード、整数、空文字列
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## IDOR 防止（ATK-02）

```php
// DELETE にはトークンのオーナーシップと user_id の一致の両方が必要
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// 理由に関係なく 404 を返す — 列挙 oracle を避ける
return $stmt->rowCount() > 0;
```

---

## マス代入防止（ATK-03）

サーバー側フィールドはリクエストボディから**絶対に読み取りません**:

```php
// POST /secrets ハンドラー — ボディから受け付けるのは message、password、expires_at のみ
$token        = bin2hex(random_bytes(32));  // サーバー生成
$consumed     = 0;                          // 常に未消費で開始
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // サーバー時刻
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // サーバー側でハッシュ化

// body['consumed']、body['token']、body['user_id']、body['created_at'] はサイレントに無視される
```

---

## V.php バリデーションチェーン

```php
// ATK-07: message は文字列でなければならない（int、bool、null、array を拒否）
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: X-User-Id は ctype_digit + 正の値 + 最大 18 文字でなければならない
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: limit は数値、最大 18 桁、範囲 1〜100 でなければならない
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## オプションのパスワード保護

```php
// 保存: SHA-256 ハッシュのみ（平文ではない）
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// 検証: 定数時間比較（タイミングセーフ）
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // パスワード不正 → サイレント 404（oracle なし）
}
```

> **注意:** パスワード不正は 404 を返します（403 ではなく）。oracle 攻撃を防ぐためです。
> シークレットはパスワード不正では消費されません — 正しいパスワードだけが消費します。

---

## メタデータ一覧（メッセージ漏洩なし）

```php
// GET /secrets — メタデータのみを返し、メッセージは絶対に返さない
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' は意図的に省略
    ];
}
```

---

## テスト結果

```
85 tests / 209 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## 主要ポイント

| パターン | ルール |
|---|---|
| アトミックな消費 | `UPDATE WHERE consumed=0` + `rowCount()` チェック — SELECT してから UPDATE ではない |
| トークンエントロピー | 最低 `random_bytes(32)`（256 ビット）— 連番 ID は使わない |
| トークンフォーマット | 両端でアンカーされた許可リスト正規表現（`/^[0-9a-f]{64}$/`） |
| IDOR | すべての書き込み操作は `token AND user_id` でスコープする |
| マス代入 | トークン、consumed、created_at — サーバー側のみ、ボディからは絶対に取得しない |
| パスワードタイミング | 定数時間比較に `hash_equals()` を使う |
| パスワード不正 | 404、403 ではない — シークレットの存在を確認しない |
| メタデータ一覧 | 一覧エンドポイントからメッセージを省略 — 消費時のみ読み取り |

完全な例: [`../NENE2-FT/onetimelog/`](https://github.com/hideyukiMORI/NENE2-examples)
