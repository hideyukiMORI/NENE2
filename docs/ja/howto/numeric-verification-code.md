# 数値確認コードの構築方法

> **FT188 verifylog で実証済みのパターン** — ブルートフォース保護、定数時間比較、リプレイ防止付き 6 桁 SMS/メール確認コード。ATK-01〜12 全 Pass。

---

## 対象範囲

連絡先確認フロー（メールまたは電話）:

1. **コードをリクエスト** — サーバーがランダムな 6 桁コードを生成し、帯域外で配信する
2. **コードを送信** — ユーザーがコードを送信する; ロックアウト前に最大 3 回の試行
3. **ステータス確認** — 確認が完了したかどうかを確認する

セキュリティ保証:

| 懸念事項 | 技術 |
|---|---|
| ブルートフォース | 最大 3 回の試行 → 429 Locked |
| タイミング攻撃 | `hash_equals()` 定数時間比較 |
| コードリプレイ | 確認済みコードは 410 Gone を返す |
| ユーザー列挙 | `POST /verifications` は常に 202 を返す |
| マス代入 | `code_hash/verified_at` はサーバー側のみ設定 |
| SQL インジェクション | 整数のみのパスパラメーター（ctype_digit + strlen > 18 ガード） |
| 型の混乱 | `ctype_digit()` の前に `is_string()` チェック |
| ReDoS | `ctype_digit()` O(n) — 正規表現なし |

---

## スキーマ

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- 6 桁コードの SHA-256
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = 保留中
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` は `hash('sha256', $code)` を保存します — 平文コードは保存しません。

---

## API

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/verifications` | コードをリクエストする（常に 202） |
| `POST` | `/verifications/{id}/check` | コードを送信する（最大 3 回の試行） |
| `GET` | `/verifications/{id}` | ステータス確認（コードは返さない） |

---

## コアパターン: コード生成とハッシュ保存

```php
// 暗号学的にランダムな 6 桁コードを生成
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// ハッシュを保存 — 平文は絶対に保存しない
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// plainCode を呼び出し元に返す（配信用） — 保存もログも残さない
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` は CSPRNG を使用します。`str_pad(..., 6, '0', STR_PAD_LEFT)` は先頭ゼロを保証します（例: `000042`）。

---

## コアパターン: 定数時間比較

```php
// ATK-10: hash_equals がタイミング攻撃を防ぐ
// $v->codeHash = DB からの保存済み SHA-256
// $submittedCode = ユーザー入力（6 桁の文字列）
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**`===` ではない理由**: `===` は最初の不一致でショートサーキットします — 攻撃者は「1 バイト目が間違い」と「すべてのバイトが間違い」のタイミング差を測定して、正しいコード文字を絞り込めます。`hash_equals()` は不一致が発生した箇所に関係なく定数時間です。

---

## コアパターン: フェイルファースト試行カウント

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11: リプレイガード
    if ($v->isLocked())     return 'locked';    // ATK-05: ブルートフォースガード
    if ($v->isExpired())    return 'expired';

    // チェック前にインクリメント — 競合状態の悪用を防ぐ
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10: 定数時間比較
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

比較の**前に**試行回数をインクリメントすることで、同じコードを同時にチェックする競合状態が制限を回避できないようにします。

---

## コアパターン: ユーザー列挙防止

```php
// POST /verifications — 常に 202 を返す
// 連絡先が無効または配信に失敗した場合でも
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // 空/null のみ
    }

    // 配信の成功や失敗は呼び出し元には見えない
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

不明な連絡先に 404 や 422 を返すと「この連絡先は登録されていない」が漏洩します。常に 202 を返してください。

---

## コアパターン: コードの型とフォーマットバリデーション

```php
$raw = $body['code'] ?? null;

// ATK-07: 型の混乱 — code は文字列でなければならない
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09: ReDoS — ctype_digit は O(n)、正規表現ではない
// ATK-09: 正確な長さチェック — 「最低 6 桁以上」ではない
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`ctype_digit()` の前の `is_string()` は JSON 整数、ブール値、配列を拒否します。`ctype_digit()` は ReDoS に対して安全です（線形時間）。

---

## レスポンス設計

| シナリオ | ステータス | ボディ |
|---|---|---|
| コード正解 | 200 | `{verified: true}` |
| コード不正解、試行回数残あり | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| 最大試行回数到達 | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| 既に確認済み（リプレイ） | 410 | `{error: "This verification has already been completed."}` |
| 期限切れ | 410 | `{error: "Verification has expired. Request a new code."}` |
| 見つからない | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 全 Pass

| ATK | 攻撃 | 防御 |
|---|---|---|
| 01 | `{id}` への SQL インジェクション | `ctype_digit()` + strlen > 18 ガード |
| 02 | IDOR — 他者の verification ID で check | 同一 404 — ownership oracle なし |
| 03 | マス代入（body から code_hash/verified_at） | サーバー側のみ設定 |
| 04 | contact への XSS | JSON 出力のみ — HTML 非レンダリング。contact をレスポンスに返さない |
| 05 | 6 桁コードのブルートフォース | 3 回失敗で 429 Locked |
| 06 | 認証バイパス | verified_at はサーバーのみ設定 |
| 07 | 型の混乱（code を int/bool/array で送信） | `is_string()` + `ctype_digit()` |
| 08 | `{id}` での整数オーバーフロー | strlen > 18 guard |
| 09 | ReDoS スタイルのコード入力 | `ctype_digit()` O(n) |
| 10 | コード比較へのタイミング攻撃 | `hash_equals()` 定数時間 |
| 11 | 成功後のコードリプレイ | 410 Gone |
| 12 | ヘッダーへの CRLF インジェクション | PSR-7 が HTTP 層で拒否 |

---

## テスト結果（FT188）

```
48 tests / 103 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
ATK-01〜12 全 Pass
```

ソース: [`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
