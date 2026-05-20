# Field Trial 113 — JWT Refresh Token Rotation

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/refreshlog/`
**NENE2 version:** 1.5.46
**Theme:** JWT Refresh Token Rotation — アクセストークン短命化（5分）・リフレッシュトークンのハッシュ保存・ローテーション（使用後即失効）・リプレイ攻撃検知（失効済みトークン再利用時に全トークン失効）・ログアウトは必ず 204（トークン有効性を漏らさない）・`jti` クレームによるアクセストークンのユニーク性保証。

---

## What was built

アクセストークン（短命）＋リフレッシュトークン（長命・DB管理）による認証フローを実装した。

- `POST /auth/login` — アクセストークン（5分）＋リフレッシュトークン（7日）を返す
- `POST /auth/refresh` — リフレッシュトークンを受け取り新しいトークンペアを返す（ローテーション）
- `POST /auth/logout` — リフレッシュトークンを失効させる（必ず 204）
- `GET /auth/me` — アクセストークンで保護されたエンドポイント

---

## Findings

### 1. リフレッシュトークンはハッシュして保存する（生値は DB に入れない）

リフレッシュトークンを生値で DB に保存すると、DB が漏洩した瞬間に全ユーザーのセッションが乗っ取られる:

```php
// ❌ 生値を保存すると DB 漏洩 = 全セッション乗っ取り
$raw = bin2hex(random_bytes(32));
$this->executor->insert(
    'INSERT INTO refresh_tokens (user_id, token) VALUES (?, ?)',
    [$userId, $raw],  // 生値を保存している
);

// ✅ SHA-256 ハッシュを保存し、クライアントには生値のみ返す
$raw  = bin2hex(random_bytes(32));
$hash = hash('sha256', $raw);
$this->executor->insert(
    'INSERT INTO refresh_tokens (user_id, token_hash) VALUES (?, ?)',
    [$userId, $hash],  // ハッシュのみ保存
);
return $raw;  // クライアントには生値を返す
```

検証時はクライアントから受け取った生値を SHA-256 でハッシュしてから DB と照合する:

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    $row  = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

### 2. ローテーション: 使用と同時に旧トークンを失効させる

リフレッシュトークンを使ったら**即座に失効**させる。使用後も有効なままだと、盗まれたトークンが使い放題になる:

```php
// Rotation: revoke the old token before issuing a new one
$this->refreshTokens->revoke($stored->id);

return $this->json->create($this->issueTokenPair($user));
```

ローテーション後に古いトークンを再度使おうとすると 401 が返る。

---

### 3. リプレイ攻撃検知: 失効済みトークンが再利用されたら全トークンを失効させる

正規ユーザーが既にローテーション済みのトークンを再利用してきた場合、それは攻撃者がそのトークンを盗んで使い回しているサインかもしれない。全トークンを失効させて強制再ログインを要求する:

```php
if ($stored === null || !$stored->isValid()) {
    // 失効済みトークンが届いた = 盗まれた可能性がある
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }

    return $this->problems->create(
        $request,
        'invalid-refresh-token',
        'Invalid or Expired Refresh Token',
        401,
        'The refresh token is invalid, expired, or has already been used.',
    );
}
```

---

### 4. ログアウトは必ず 204 を返す（有効性を漏らさない）

ログアウトエンドポイントがトークンの有無や有効性によって異なるステータスを返すと、攻撃者がトークンの有効性を探れる:

```php
// ❌ トークンが有効かどうかを漏らす
$stored = $this->refreshTokens->findByRaw($token);
if ($stored === null) {
    return $this->problems->create(..., 401);  // "このトークンは無効" と教えている
}

// ✅ 常に 204 を返す — トークン有効性を漏らさない
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);
if ($stored !== null && !$stored->revoked) {
    $this->refreshTokens->revoke($stored->id);
}
return $this->json->createEmpty(204);  // 常に 204
```

---

### 5. `jti` クレームでアクセストークンをユニークにする

アクセストークンの TTL を短く設定していても、同じユーザーに対して同じ秒内にトークンを2回発行すると、全クレームが同一なので JWT が一致してしまう。`jti`（JWT ID）クレームを追加することでユニーク性を保証する:

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // ユニーク ID
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => $now,
    'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
]);
```

`jti` は将来のトークンブロックリスト実装（アクセストークンの即時失効）の起点にもなる。

---

### 6. アクセストークン（短命）とリフレッシュトークン（長命）の分離

```
アクセストークン: 5分（300秒）— JWT、stateless、DB参照不要
リフレッシュトークン: 7日 — 生値はクライアントのみ、DB にはハッシュ、ローテーション管理
```

アクセストークンが短命なので、漏洩しても5分で無効になる。リフレッシュトークンはローテーションで管理するため、使い回しが検知できる。

---

## Test results

15 tests, 63 assertions — all pass.

Key behaviors confirmed:
- ログインで access_token と refresh_token の両方が返る（`expires_in: 300`）
- アクセストークンで保護エンドポイントにアクセスできる
- リフレッシュで新しいトークンペアが返る（旧トークンとは異なる）
- 新しいアクセストークンで保護エンドポイントにアクセスできる
- ローテーション後に旧リフレッシュトークンは 401
- 失効済みトークン再利用時に全トークン失効（新トークンも無効化）
- ログアウト後にリフレッシュトークンが 401
- ログアウトは無効なトークンでも 204（情報漏洩なし）
- リフレッシュトークンは 64 文字の hex 文字列（raw, 32バイト）
- 認証なしで `/auth/me` → 401

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**「アクセストークンとリフレッシュトークンって何が違うの？」:** JWT を1種類しか知らない段階では、なぜ2種類のトークンが必要なのかが分からない。「短命 JWT を毎回発行すれば良いのでは？」と思うが、そうすると毎回ログインを要求することになる。「ユーザーが30日ログインを保持したい」というユースケースで初めて必要性を理解できる。

**「トークンを DB に保存するのが嫌だ」:** JWT の「stateless」という言葉を覚えた直後だと、リフレッシュトークンを DB に保存することに違和感を覚える。「DB に保存するなら JWT の意味がない」という誤解が生まれやすい。アクセストークンは stateless、リフレッシュトークンは stateful という分離が鍵。

**事故リスク:** 高。リフレッシュトークンを生値で保存する実装が多い。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**「ハッシュ化すれば良いってことはハッシュ化しなくていいのでは？」:** bcrypt でのパスワードハッシュは知っていても、リフレッシュトークンに SHA-256 を使うという発想がない。「トークンは長いから大丈夫では？」という考え方になりがち。DB 漏洩シナリオが腹落ちすると理解できる。

**「ローテーションは実装コストが高い」:** 旧トークンを失効させながら新トークンを発行する実装は、单純な CRUD より複雑。「毎回ローテーションするんですか？それ必要ですか？」という疑問が出やすい。セッション固定攻撃のリスクで説明すると納得しやすい。

**事故リスク:** 高。ローテーションなし・生値保存の実装が起きやすい。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**「リフレッシュトークンをどこに保存すれば良い？」:** フロントエンド目線では、localStorage vs cookie (httpOnly) の議論が重要。サーバーの実装だけでなく、「クライアントでどう管理するか」のガイドが欲しい。httpOnly cookie なら JavaScript からアクセスできないので XSS に強いが、CSRF への対策が必要になる。

**「レース条件はどうする？」:** 複数タブで同時にリフレッシュリクエストが飛んだ場合、最初の1つが成功して残りが 401 になる問題。フロントエンドでのトークン更新のシリアライズ（1つのリフレッシュリクエストを待って他を待機させる）が必要になる。

**事故リスク:** 中。フロント側のトークン管理が問題になりやすい。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**「Laravel Sanctum との比較」:** Laravel Sanctum は SPA 認証としてリフレッシュトークンパターンを提供しているが、NENE2 では手動実装。Sanctum の `PersonalAccessToken` は DB に保存して失効管理も内蔵されている。NENE2 の「フレームワークマジックなし」の哲学と整合しているが、実装コストはある。

**「リプレイ攻撃検知の実装は正しいか？」:** `revokeAllForUser()` は効果的だが、正規ユーザーも影響を受ける。「Token Family」（ローテーションの系譜）で部分失効するアプローチもある。トレードオフの議論ができる経験者。

**事故リスク:** 低（経験者はパターンを知っている）。ただし `revokeAllForUser()` の影響範囲をレビューすべき。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. リフレッシュトークンが生値で DB に保存されていないか（`token_hash` を確認）
2. ローテーション実装: `revoke()` が `issueTokenPair()` の前に呼ばれているか
3. 失効済みトークン再利用時の `revokeAllForUser()` が実装されているか
4. ログアウトが常に 204 を返しているか（条件分岐で 401 を返していないか）
5. `jti` クレームが含まれているか（アクセストークンのユニーク性）
6. アクセストークン TTL が短いか（数分〜数十分が適切）

**テスト必須:** `testRefreshTokenReuseRevokesAllUserTokens()` — リプレイ攻撃検知の動作確認。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- リフレッシュトークンのハッシュ保存は良い設計（DB 漏洩耐性）
- `createEmpty(204)` の使用は正しい（`create(null, 204)` は型エラー）
- `jti` クレームによるユニーク性保証はトークン追跡の起点として良い

**設計上のギャップ:**
1. Refresh Token Rotation howto が未作成
2. アクセストークンと `jti` によるトークンブロックリスト（即時失効）パターンが未文書

---

## Issues / PRs

- Issue: `docs/howto/refresh-token-rotation.md` — リフレッシュトークンのハッシュ保存・ローテーション・リプレイ攻撃検知・ログアウトの情報漏洩防止・`jti` クレーム
