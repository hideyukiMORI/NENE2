# Field Trial 109 — Password Hashing (password_hash / password_verify)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/pwdlog/`
**NENE2 version:** 1.5.42
**Theme:** パスワードのハッシュ化と検証 — `password_hash(PASSWORD_ARGON2ID)` による安全な保存、`password_verify()` による検証、ユーザー列挙防止（タイミング攻撃）、`DatabaseConstraintException` による重複メール検出。

---

## What was built

ユーザー登録・ログイン API を実装した。

- `POST /register` — メール + パスワードで登録。Argon2id ハッシュを保存。
- `POST /login` — 認証。認証失敗時は「メールが存在するか」「パスワードが違うか」を区別しない。

---

## Findings

### 1. `register()` メソッド名の衝突（摩擦あり）

`RouteRegistrar::register(Router $router)` というルート登録メソッドと、`/register` エンドポイントのハンドラーを `register()` と名付けようとしたため、PHP の「同名メソッドは定義できない」エラーが発生した:

```
Fatal error: Cannot redeclare Pwd\Auth\RouteRegistrar::register()
```

解決: ハンドラーメソッドを `handleRegister()` にリネーム。

**教訓:** NENE2 の規約で `RouteRegistrar::register()` がルート登録メソッドとして固定されているため、エンドポイント名と一致するハンドラー名を付けると衝突する。

---

### 2. `DatabaseConstraintException` vs `\PDOException`（重要な発見）

重複メール登録で SQLite の UNIQUE 制約違反が発生したとき、`\PDOException` を catch しようとしたが 500 が返った。原因: `PdoDatabaseQueryExecutor` が PDOException を `DatabaseConstraintException` に変換して rethrow していた:

```php
// ❌ 動かない — PDOException はすでにラップ済み
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ 正しい — NENE2 のラップされた例外を catch
use Nene2\Database\DatabaseConstraintException;
...
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

NENE2 の PHPDoc や CLAUDE.md に記載はないが、`DatabaseConstraintException` は ADR 0009 の公開 API 安定性保証に含まれている。howto に記載が必要。

---

### 3. Argon2id がデフォルトでない（摩擦あり）

PHP の `PASSWORD_DEFAULT` は現時点では `bcrypt`。セキュリティ上は `PASSWORD_ARGON2ID` が推奨:

```php
// ❌ PASSWORD_DEFAULT = bcrypt — GPU 攻撃に対してより弱い
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — メモリハード、GPU 攻撃耐性が高い
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id は PHP 7.3+ で利用可能。NENE2 は PHP 8.4 を要求するため常に使える。`password_needs_rehash()` を使えば bcrypt → Argon2id への移行も対応できる。

---

### 4. ユーザー列挙防止のダミーハッシュパターン

ユーザーが存在しない場合に即座に 401 を返すと、タイミング差からメールアドレスの存在を推測できる:

```php
// ❌ メール不存在なら即 401 — 応答時間が短いのでユーザー存在を漏洩
if ($user === null) {
    return $this->problems->create(..., 401, ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create(..., 401, ...);
}

// ✅ ユーザーが存在しなくてもダミーハッシュで verify を実行
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$...';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create(..., 401, ...);
}
```

ダミーハッシュの重要なポイント:
- 本物の Argon2id ハッシュ形式でなければ `password_verify()` が即座に return false → タイミング差が出る
- `$dummyHash` は有効なハッシュ形式（`$argon2id$...`）でなければならない
- `password_verify` は常にハッシュアルゴリズムのコスト分の時間がかかる

---

### 5. `password_verify()` は bcrypt ↔ Argon2id 間でも機能する

`password_verify($password, $hash)` はハッシュのプレフィックスでアルゴリズムを自動判定する:

```php
// bcrypt ハッシュに対しても正しく検証できる
$bcryptHash = password_hash('password', PASSWORD_BCRYPT);
$result = password_verify('password', $bcryptHash); // true

// Argon2id ハッシュに対しても
$argonHash = password_hash('password', PASSWORD_ARGON2ID);
$result = password_verify('password', $argonHash); // true
```

これにより、bcrypt → Argon2id 移行時もログイン処理の変更なしに古いハッシュで検証できる。

---

### 6. パスワードハッシュをレスポンスに含めない

当たり前のようで、デバッグ中に `$user->toArray()` をそのままレスポンスに使うと `password_hash` フィールドが漏洩する:

```php
// ❌ password_hash が含まれる可能性がある
return $this->json->create($user->toArray());

// ✅ 必要なフィールドだけ明示的に列挙
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
    // password_hash は含めない
]);
```

---

## Test results

14 tests, 39 assertions — all pass.

Key behaviors confirmed:
- POST /register → 201、Argon2id ハッシュで保存
- レスポンスに `password` / `password_hash` フィールドなし
- DB に Argon2id ハッシュが保存されていることを直接確認（`$argon2id$` プレフィックス）
- 短いパスワード → 422
- 無効なメール → 422
- 重複メール → 409
- 正しい認証 → 200
- 間違ったパスワード → 401（同じエラーメッセージ）
- 存在しないメール → 401（同じエラーメッセージ）
- bcrypt ハッシュのレガシーユーザーもログイン可能
- 必須フィールドなし → 400

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**プレーンテキスト保存の誘惑:** 「パスワードをそのまま DB に保存してなぜいけないのか」を理解していない段階では、`password_hash()` を使う理由が分からない。「もし DB が漏洩したらパスワードが全部バレる」という具体的なシナリオで説明すると理解しやすい。

**`PASSWORD_DEFAULT` vs `PASSWORD_ARGON2ID`:** `PASSWORD_DEFAULT` で動くと知ったら、それ以上調べない可能性がある。「Argon2id がより安全な理由」を howto に記載することが重要。

**ユーザー列挙防止のダミーハッシュ:** この概念は上級すぎて初心者には伝わりにくい。「とにかくこのパターンをコピーしてください」という形で提示する必要がある。

**事故リスク:** 高。MD5やSHA-1でハッシュを「なんとなく」かける実装が非常に多い。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**`md5($password)` のコピペ:** 古いチュートリアルのコピペで `md5()` を使ってしまうケースが実際に多い。MD5は衝突が容易でレインボーテーブル攻撃も効く。PHPのネイティブ `password_hash()` を「使わない理由がない」という形で訴求する必要がある。

**`PASSWORD_DEFAULT` の落とし穴:** bcrypt のままでも「動く」。将来のPHPバージョンで `PASSWORD_DEFAULT` が変わる可能性があることを知っておくべき（現時点では変更予告なし）。

**ユーザー列挙防止の実装忘れ:** 「なぜわざわざダミーハッシュで verify するのか」が伝わらないと実装されない。

**事故リスク:** 中〜高。MD5パターンは依然として多い。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**APIとしての設計:** 「なぜ 401 が 404 ではないのか」「なぜメール不存在と間違ったパスワードで同じエラーを返すのか」のセキュリティ理由を理解できれば、クライアント側の実装も正しくなる。

**パスワードの送信:** HTTPS 必須、フロントエンドでのハッシュ化は不要（サーバー側で行う）という設計判断を明文化することが重要。

**事故リスク:** 低。パスワードの扱いはフロント側よりもバックエンドの責任。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**Laravel との比較:** Laravel の `Hash::make()` は設定で bcrypt/argon2i/argon2id を選べる。デフォルトは bcrypt。NENE2 では PHP ネイティブの `password_hash()` を使う — 同等の機能を提供しており、フレームワーク依存がない点で移植性が高い。

**ダミーハッシュのメンテナンス:** ハードコードされたダミーハッシュが将来のアルゴリズム変更で陳腐化する可能性。`password_hash('dummy', PASSWORD_ARGON2ID)` で毎回生成する方法もあるが、リクエストごとのコストがかかる。定数として定義するか、設定から読む設計が望ましい。

**`password_needs_rehash()` の活用:** ログイン成功時に `password_needs_rehash($hash, PASSWORD_ARGON2ID)` でハッシュのアップグレードを自動化できるパターンも重要。

**事故リスク:** 低。ただしダミーハッシュの設計細部に注意が必要。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. `password_hash()` が使われているか（MD5・SHA-1・bcrypt直接は不可）
2. アルゴリズムが `PASSWORD_ARGON2ID` または `PASSWORD_DEFAULT` か
3. `password_verify()` で比較しているか（`===` や `hash_equals()` を使っていないか）
4. ユーザーが存在しない場合も `password_verify()` を実行しているか（タイミング攻撃対策）
5. `password_hash` がレスポンスに含まれていないか
6. 404 ではなく 401 を返しているか（ユーザー存在を漏洩しない）
7. ログに平文パスワードが出力されていないか

**`password_verify()` は定数時間か:** `password_verify()` はPHP 内部で定数時間比較を行う。追加の `hash_equals()` は不要。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `DatabaseConstraintException` が UNIQUE 制約違反のラッパーとして機能しており、PHPDoc に記載済み — 良い設計
- ユーザー列挙防止のダミーハッシュパターンは「フレームワークマジックなし」と整合（明示的なコード）

**設計上のギャップ:**
1. `DatabaseConstraintException` の使いどころ（重複キー検出）が howto に未記載
2. パスワードハッシュ全体の howto が未作成
3. `RouteRegistrar::register()` 名前衝突リスクが CLAUDE.md に未記載

---

## Issues / PRs

- Issue: `docs/howto/password-hashing.md` — Argon2id ハッシュ・password_verify・ユーザー列挙防止・DatabaseConstraintException 重複検出・レスポンスへのハッシュ漏洩防止
