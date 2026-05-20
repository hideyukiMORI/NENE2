# Field Trial 111 — RBAC (Role-Based Access Control)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/rbaclog/`
**NENE2 version:** 1.5.44
**Theme:** ロールベースアクセス制御 — JWT クレームに `role` を含める、ハンドラーでの権限チェック、401 Unauthorized vs 403 Forbidden の正しい使い分け、`BearerTokenMiddleware` の HTTP メソッド別ルーティング制限、`createEmpty()` を使った 204 レスポンス。

---

## What was built

RBAC 付きブログ API を実装した。

- `POST /auth/login` — JWT を返す（クレームに `role` を含む）
- `GET /posts` — 公開（認証不要）
- `POST /posts` — 認証済みユーザーのみ（`user` or `admin`）
- `DELETE /posts/{id}` — 管理者のみ（`admin`）

---

## Findings

### 1. JWT クレームにロールを含める（重要なアーキテクチャ決定）

ロールを DB から毎リクエスト取得する設計と、JWT クレームに含める設計の2択がある:

```php
// ❌ DB 毎回参照 — 認証のたびにクエリが走る
$user = $this->users->findById((int) $claims['sub']);
if ($user->role !== Role::Admin) { ... }

// ✅ JWT クレームにロールを含める — 追加 DB クエリなし
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,  // 'user' or 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);

// ハンドラーでの検証
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role !== Role::Admin) { return 403; }
```

トレードオフ: JWT はロール変更を即座に反映しない（トークン有効期限まで古いロールのまま）。高セキュリティ要件では DB 確認を組み合わせる。

---

### 2. `BearerTokenMiddleware` はパスのみで保護を決定する（HTTP メソッドを区別しない）

`GET /posts`（公開）と `POST /posts`（認証必須）が同じパス `/posts` に存在する場合、`BearerTokenMiddleware` はこれを区別できない:

```php
// ❌ /posts を excludedPaths に入れると POST /posts も公開になる
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);
// → POST /posts に認証トークンを送っても nene2.auth.claims は設定されない
```

回避策1（推奨）: **同じパスで公開・保護メソッドが混在するときはハンドラーで手動検証する**:

```php
// ハンドラーが TokenVerifierInterface を受け取り、手動で検証する
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // 保護パスなら middleware が設定済み
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // 公開パスで除外された場合 — Authorization ヘッダーを手動で検証
    $authorization = $request->getHeaderLine('Authorization');
    if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }
}
```

回避策2: パスを分離する（例: `GET /public/posts` と `POST /posts`）。

---

### 3. 401 Unauthorized vs 403 Forbidden — 混同しやすい

```php
// ❌ 認証済みユーザーに 401 を返すのは誤り
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ 認証済みだが権限不足 → 403 Forbidden
// ✅ 未認証（トークンなし・無効） → 401 Unauthorized
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

| 状況 | 正しい HTTP ステータス |
|---|---|
| トークンなし・期限切れ・署名無効 | 401 Unauthorized |
| 認証済みだがロール不足 | 403 Forbidden |
| リソースが存在しない | 404 Not Found |

---

### 4. 204 No Content には `createEmpty()` を使う（摩擦あり）

`JsonResponseFactory::create()` の第1引数は `array` 型。`null` や空配列を渡すと型エラーまたは意図しない空 JSON になる:

```php
// ❌ 型エラー — create() は array 必須
return $this->json->create(null, 204);

// ❌ 空の JSON オブジェクト {} が返る（204 は body を持たないべき）
return $this->json->create([], 204);

// ✅ 空レスポンス専用メソッドを使う
return $this->json->createEmpty(204);
```

---

### 5. `Role::tryFrom()` でクレームの型安全なデコード

クレームの `role` フィールドは `mixed` 型のため、キャストしてから `tryFrom()` を使う:

```php
// ❌ $claims['role'] は mixed — 直接 Role::from() はエラーリスク
$role = Role::from($claims['role']);

// ✅ キャストしてから tryFrom — 未知の値は null になる
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role !== Role::Admin) {
    return 403;
}
```

---

## Test results

14 tests, 48 assertions — all pass.

Key behaviors confirmed:
- ログイン JWT クレームに `role` フィールドが含まれる（`user` / `admin`）
- `GET /posts` — 認証なしで公開
- `POST /posts` — 認証必須（`user` も `admin` も可能）
- `POST /posts` — 認証なしで 401
- `DELETE /posts/{id}` — admin のみ成功 → 204
- `DELETE /posts/{id}` — user ロールで 403（401 ではない）
- `DELETE /posts/{id}` — 認証なしで 401（403 ではない）
- 期限切れトークンで `DELETE` → 401
- 存在しない投稿の `DELETE` → 404

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1年・PHP 独学中）

**「認証」と「認可」の違いが分からない:** 「ログインしているかどうか」（認証）と「権限があるかどうか」（認可）の区別が初心者には曖昧。401 と 403 の違いも「両方 "認証系エラー" じゃないの？」となりやすい。「警備員に IDカードを見せる（認証）」と「その部屋に入る権限があるか確認する（認可）」の比喩で説明すると伝わりやすい。

**事故リスク:** 高。全てのエラーを 401 で返してしまうケースが非常に多い。

---

### ペルソナ2: ロースキル経験者（PHP 歴3〜4年・受託 Web 開発）

**`create(null, 204)` の落とし穴:** 「null を渡せば空だろう」と考えて `JsonResponseFactory::create(null, 204)` を書いてしまう。型エラーまたは 500 になって初めて気づく。`createEmpty()` の存在を知らない。

**JWT ロールのキャッシュ問題:** 「ユーザーのロールを admin に変更したのに API が反映されない」というバグレポートが来るケースが想定される。JWT の有効期限とロール更新の同期を設計段階で決めておく必要がある。

**事故リスク:** 中。`create(null, ...)` の型エラーと JWT ロールキャッシュが主なリスク。

---

### ペルソナ3: フロントエンド寄り経験者（JS/TS 歴4年・フルスタック転向中）

**クライアント側でのロール表示:** JWT のペイロードは Base64 デコードで読めるため（署名は検証できないが内容は見える）、クライアント側でロールを表示する実装は可能。ただし **アクセス制御はサーバー側で必ず行う** — クライアントのデコードは UI 表示用のみ。

**403 vs 401 のクライアント側ハンドリング:** 401 は「ログインページへリダイレクト」、403 は「権限不足のエラー表示」と異なる UI フローになる。サーバーが正しい HTTP ステータスを返すことでクライアントのロジックが正しく書ける。

**事故リスク:** 低〜中。クライアント側のロールチェックをサーバー側チェックの代わりにしてしまうリスクがある。

---

### ペルソナ4: バックエンド経験者（Laravel/Symfony 歴5年）

**Laravel Gate / Policy との比較:** Laravel の `$this->authorize('delete', $post)` に相当する仕組みを NENE2 では手動実装する。`Gate` のような共通抽象は NENE2 に存在しない — フレームワークマジックなしが哲学。`requireRole()` ヘルパーパターンが同等の役割を果たす。

**JWT ロール vs DB ロール:** JWT ロールは即座に反映されないため、高セキュリティ要件（医療・金融）では `findById($claims['sub'])` で DB のロールを毎回確認する方が安全。パフォーマンスと一貫性のトレードオフ。

**`BearerTokenMiddleware` のメソッド非区別:** Laravel Route Middleware は HTTP メソッドごとに適用できる（`Route::post(...)->middleware('auth')`）。NENE2 の `BearerTokenMiddleware` にはこの仕組みがない — ハンドラー手動検証か URL 分離が必要な点が摩擦。

**事故リスク:** 低。JWT ロールキャッシュの設計判断が最大の考慮点。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・10年超）

**コードレビューポイント:**
1. `role` クレームが `Role::tryFrom()` で安全にデコードされているか（`Role::from()` は例外リスク）
2. 403 と 401 が正しく区別されているか
3. JWT ロールキャッシュの有効期限設計が明示されているか（ロール変更の即時反映が必要か）
4. `createEmpty(204)` を使っているか（`create(null, 204)` ではなく）
5. `BearerTokenMiddleware` の除外パス設計が意図通りか（POST が意図せず公開になっていないか）
6. ロールチェックが全ての保護エンドポイントで実施されているか（チェック漏れのエンドポイント）

**セキュリティ上の観点:** ロール変更後のトークン無効化戦略がないと、昇格したロールも降格したロールも有効期限まで有効になる。短い有効期限か、リフレッシュトークンによる定期的な再発行が推奨。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `requireRole()` → `requireAuth()` という委譲パターンはフレームワークマジックなしで明示的 — 良い設計
- `Role` enum を使うことで型安全なロールチェック — 設計ポリシーと整合
- 403 と 401 の正しい区別 — RFC 7231 準拠

**設計上のギャップ:**
1. `BearerTokenMiddleware` が HTTP メソッドを区別しない制限が howto に未記載
2. `JsonResponseFactory::createEmpty()` の使いどころが howto に未記載
3. RBAC 全体の howto が未作成
4. `Role` enum パターンと `tryFrom()` の推奨が未文書

---

## Issues / PRs

- Issue: `docs/howto/rbac.md` — JWT クレームへのロール埋め込み・`requireRole()` パターン・401 vs 403・`BearerTokenMiddleware` のメソッド非区別・`createEmpty()` 204 レスポンス
