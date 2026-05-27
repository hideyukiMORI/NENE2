# ハウツー: ポイント台帳 API

> **FT リファレンス**: FT300 (`NENE2-FT/pointlog`) — ポイント台帳 API: earn/spend/adjust/expire トランザクション、残高追跡、オーバードラフト防止（CHECK balance_after >= 0）、管理者専用 adjust、reference_id 冪等性、MAX_EARN=10000 / MAX_ADJUST=100000 上限、ATK-01〜12 すべて BLOCKED、30 テスト / 66 アサーション PASS。

このガイドでは、ユーザーがポイントを獲得・消費し、管理者が残高を調整し、reference_id が重複トランザクションを防ぐロイヤルティポイント台帳の構築方法を解説します。

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

多層防御として 3 つの CHECK 制約を使用しています:
- `amount > 0` — DB レベルでゼロや負のトランザクションを防ぎます
- `balance_after >= 0` — 残高がストレージ上で絶対に負にならないようにします
- `type IN (...)` — 既知のトランザクションタイプのみ受け付けます

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | 現在の残高を取得する |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | トランザクション履歴を取得する |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id`（本人） | ポイントを獲得する |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id`（本人） | ポイントを消費する |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id`（管理者） | 管理者による調整 |

## 認証と認可

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

すべてのハンドラーは最初に `requireUserId()` を呼び出します:

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

次に earn/spend のクロスユーザーアクセスをチェックします:

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

管理者は任意のユーザーの残高や履歴を参照できます。非管理者は自分のものにのみアクセスできます。

## 厳密な整数バリデーション

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` は以下を拒否します:
- 浮動小数点数: `10.5` — 拒否（422）
- 文字列: `"100"` — 拒否（422）
- 真偽値: `true` — 拒否（422）
- ゼロ: `0` — 拒否（amount <= 0）
- 負の値: `-500` — 拒否（amount <= 0）

## トランザクション上限

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

earn は 1 トランザクションあたり 10,000 に制限されています。管理者の adjust は 100,000 に制限されています（特権的な修正操作のため上限が高く設定されています）。

## オーバードラフト防止

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

差し引く前に現在の残高を確認します。エラーに現在の残高と必要額を返すことで、クライアントがユーザーにわかりやすいメッセージを表示できます。

## 管理者による調整

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

adjust はターゲットユーザーを確認する**前**に `isAdmin()` をチェックします — 非管理者はターゲットに関係なく即座に 403 を受け取ります。`adjust_type` フィールド（デフォルト `'add'` / `'subtract'`）により、管理者は別のエンドポイントなしでポイントの付与と控除の両方が行えます。

## reference_id 冪等性

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

`reference_id` が指定された場合:
- 初回呼び出し → 新しいトランザクションで 201 Created
- 同じ `reference_id` での再呼び出し → 元のトランザクションで 200 OK（新しいトランザクションは作成されない）

これによりネットワークリトライでの二重クレジットを防ぎます。reference_id のルックアップは**ユーザースコープ**（`findByReferenceId($targetUserId, ...)`）で行われるため、異なるユーザーが同じ reference_id を使っても競合しません。

## 残高計算

```php
// リポジトリ: 最新トランザクションの balance_after、またはなければ 0
public function getBalance(int $userId): int
{
    // 通常: 最新トランザクションの balance_after、なければ 0
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

各トランザクションの `balance_after` カラムに累積残高が記録されています。現在残高の取得は単一の `ORDER BY id DESC LIMIT 1` クエリで完結します — SUM 集計は不要です。

## レスポンス形式

```php
private function formatTransaction(array $t): array
{
    return [
        'id'           => isset($t['id'])           ? (int)    $t['id']           : null,
        'user_id'      => isset($t['user_id'])       ? (int)    $t['user_id']       : null,
        'type'         => $t['type']         ?? null,
        'amount'       => isset($t['amount'])        ? (int)    $t['amount']        : null,
        'balance_after'=> isset($t['balance_after']) ? (int)    $t['balance_after'] : null,
        'description'  => $t['description']  ?? null,
        'reference_id' => $t['reference_id'] ?? null,
        'created_at'   => $t['created_at']   ?? null,
    ];
}
```

---

## ATK アセスメント — クラッカー視点の攻撃テスト

### ATK-01 — 未認証での残高アクセス 🚫 BLOCKED

**Attack**: `X-User-Id` ヘッダーなしで `GET /users/2/points`。
**Result**: BLOCKED — `requireUserId()` が null を返す → 即座に 401。データは返されません。

---

### ATK-02 — クロスユーザーの残高覗き見 🚫 BLOCKED

**Attack**: `X-User-Id: 3` で `GET /users/2/points`（Alice が Bob の残高を読もうとする）。
**Result**: BLOCKED — `$targetUserId (2) !== $actorId (3)` かつ管理者でない → 403。

---

### ATK-03 — 他ユーザーへの自己付与 🚫 BLOCKED

**Attack**: `X-User-Id: 2` で `POST /users/3/points/earn`、`amount: 99999`。
**Result**: BLOCKED — actor (2) != target (3) かつ管理者でない → 403。ターゲットの残高は 0 のまま。

---

### ATK-04 — 負の金額での獲得 🚫 BLOCKED

**Attack**: `amount: -500` で `POST /users/2/points/earn`。
**Result**: BLOCKED — `$amount <= 0` チェック → 422。残高は変わりません。

---

### ATK-05 — ゼロ金額のトランザクション 🚫 BLOCKED

**Attack**: earn に `amount: 0`、spend にも `amount: 0` を個別に試す。
**Result**: BLOCKED — どちらも 422 を返します（`amount <= 0`）。ゼロ値のトランザクションは作成されません。

---

### ATK-06 — オーバードラフトによる消費 🚫 BLOCKED

**Attack**: 100 ポイント獲得後、101 ポイントの消費を試みる。
**Result**: BLOCKED — `$balance (100) < $amount (101)` → `insufficient points` で 422。残高は 100 のまま。DB の `CHECK (balance_after >= 0)` が追加の安全網として機能します。

---

### ATK-07 — 一般ユーザーによる調整 🚫 BLOCKED

**Attack**: `X-User-Id: 2`（非管理者ロール）で `POST /users/2/points/adjust`。
**Result**: BLOCKED — `isAdmin()` チェック失敗 → 403。残高は 0 のまま。

---

### ATK-08 — 過大な獲得金額 🚫 BLOCKED

**Attack**: `amount: 10001`（MAX_EARN=10000 超）で `POST /users/2/points/earn`。
**Result**: BLOCKED — `$amount > MAX_EARN_PER_TRANSACTION` → `max: 10000` で 422。残高は変わりません。

---

### ATK-09 — reference_id 再利用による二重クレジット 🚫 BLOCKED

**Attack**: `reference_id: "order-999"` で 500 ポイント獲得後、同じリクエストを繰り返す。
**Result**: BLOCKED — 2 回目の呼び出しは `findByReferenceId()` で既存トランザクションを発見 → 同じトランザクションで 200。残高は 500 のまま（1000 にならない）。

---

### ATK-10 — reference_id 再利用による二重引き落とし 🚫 BLOCKED

**Attack**: `reference_id: "redemption-777"` で 300 ポイント消費後、繰り返す。
**Result**: BLOCKED — 2 回目の呼び出しは元の消費トランザクションを返します（200）。残高は 700 のまま（400 にならない）。

---

### ATK-11 — reference_id への SQL インジェクション 🚫 BLOCKED

**Attack**: earn リクエストで `reference_id: "' OR '1'='1' --"`。
**Result**: BLOCKED — パラメーター化クエリがインジェクション文字列をそのまま保存します。残高は 100 で破損しません。レスポンスの `reference_id` はインジェクション文字列と完全に一致します（SQL として解釈されずデータとして保存）。

---

### ATK-12 — 浮動小数点金額 🚫 BLOCKED

**Attack**: `amount: 10.5` で `POST /users/2/points/earn`。
**Result**: BLOCKED — `is_int(10.5)` は false → null → 422。残高は変わりません。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 未認証での残高アクセス | 🚫 BLOCKED |
| ATK-02 | クロスユーザーの残高覗き見 | 🚫 BLOCKED |
| ATK-03 | 他ユーザーへの自己付与 | 🚫 BLOCKED |
| ATK-04 | 負の金額での獲得 | 🚫 BLOCKED |
| ATK-05 | ゼロ金額のトランザクション | 🚫 BLOCKED |
| ATK-06 | オーバードラフトによる消費 | 🚫 BLOCKED |
| ATK-07 | 一般ユーザーによる調整 | 🚫 BLOCKED |
| ATK-08 | 過大な獲得金額（>MAX） | 🚫 BLOCKED |
| ATK-09 | reference_id による二重クレジット | 🚫 BLOCKED |
| ATK-10 | reference_id による二重引き落とし | 🚫 BLOCKED |
| ATK-11 | reference_id への SQL インジェクション | 🚫 BLOCKED |
| ATK-12 | 浮動小数点金額 | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
重大な発見なし。認証チェーン（401→403）、金額バリデーション（is_int + >0 + 上限）、オーバードラフトガード、reference_id 冪等性がすべての既知の攻撃ベクターを防いでいます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| `X-User-Id` チェックなし（認証スキップ） | すべての残高とトランザクションへの未認証アクセス |
| 管理者チェックなしのクロスユーザー earn | 任意のユーザーが他のユーザーのアカウントにポイントを獲得できる |
| `is_int()` なしの `$amount > 0` | 浮動小数点数 `10.5` が通過し、台帳の整合性が壊れる |
| MAX_EARN 上限なし | 攻撃者が 1 リクエストで INT_MAX ポイントを獲得できる |
| spend 前のオーバードラフトチェックなし | 残高が負になる; DB CHECK は最終手段であり主要ガードではない |
| `reference_id` 冪等性なし | ネットワークリトライでクレジットまたは課金が二重になる |
| ユーザー横断の `reference_id` 空間共有 | ユーザー A の `order-1` がユーザー B の同じ参照の使用を妨げる |
| 大きなテーブルでの SUM 集計による `getBalance()` | リクエストごとに全テーブルスキャン; 代わりに `balance_after` 累積合計を使う |
| ロールチェック前の管理者 adjust | 非管理者が大きな adjust を送信; ビジネスロジックより前にロールを確認する |
| 重複時に同じトランザクションボディなしで 200 を返す | クライアントが冪等性を検証できない; 元のトランザクションを返す必要がある |
