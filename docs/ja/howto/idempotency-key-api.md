# ハウツー: 冪等性キー API

> **FT リファレンス**: FT316 (`NENE2-FT/idempotencylog`) — 決済 API の冪等性キーパターン: SHA-256 キーハッシュ、X-Idempotent-Replayed ヘッダー、重複防止、15 テスト / 25 アサーション PASS。

このガイドでは、`X-Idempotency-Key` ヘッダーパターンを使って冪等なミューテーションエンドポイントを実装し、ネットワーク再試行での重複操作を防止する方法を示します。

## スキーマ

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'JPY',
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- X-Idempotency-Key の SHA-256
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- JSON エンコードされたレスポンスボディ
    created_at  TEXT    NOT NULL
);
```

`key_hash` は `hash('sha256', $rawKey)` を保存します — 生のキーは永続化されません。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/payments` | 決済を作成する（キー付きで冪等） |
| `GET`  | `/payments` | すべての決済を一覧表示する |

## 冪等性キーフロー

```
クライアント                       サーバー
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ （新規）→ 決済を作成し、レコードを保存
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ （リプレイ）→ 保存されたレスポンスを返す
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### 最初のリクエスト — 作成して保存

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// X-Idempotent-Replayed ヘッダーなし
```

### 再試行 — 保存されたレスポンスを返す

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // 最初のレスポンスと同一
```

## 実装

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... バリデーションして決済を作成 ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## キーのルール

| シナリオ | 動作 |
|----------|-----------|
| キーなしで送信 | 毎回新しい決済が作成される |
| キーあり、最初の呼び出し | 決済が作成され、レコードが保存される |
| キーあり、再試行（同じボディ） | 保存されたレスポンスがリプレイされる。`X-Idempotent-Replayed: true` |
| 異なるキー | 別々の決済が作成される |

```php
// 同じキーで 3 回再試行 → DB には決済が 1 件のみ
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 （作成）
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 （リプレイ）
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 （リプレイ）

GET /payments → {"total": 1, ...}
```

## バリデーション

```php
POST /payments  {"currency": "JPY"}         → 422  // amount_cents が欠如
POST /payments  {"amount_cents": 0}          → 422  // 正の値でなければならない
POST /payments  {"amount_cents": -100}       → 422  // 正の値でなければならない
```

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — キーへの SHA-256 前像攻撃 🚫 BLOCKED

**攻撃**: 攻撃者が DB から `key_hash` を取得し、元の `X-Idempotency-Key` をリバースエンジニアリングしてビクティムのキーでトランザクションをリプレイしようとする。
**結果**: BLOCKED — SHA-256 は一方向関数。前像攻撃は計算上実行不可能。生のキーは保存されない。

---

### ATK-02 — レスポンスをハイジャックするためのキー推測 🚫 BLOCKED

**攻撃**: 攻撃者が短い/予測可能なキー（例: `pay-1`、`retry-001`）を推測して、自分が開始していないキャッシュされた決済レスポンスを受け取ろうとする。
**結果**: BLOCKED — キーは不透明なトークン。UUID または高エントロピーキーを推測することは実行不可能。クライアントは `bin2hex(random_bytes(16))` または UUID v4 を使うべき。

---

### ATK-03 — 異なるユーザー間でのリプレイ 🚫 BLOCKED

**攻撃**: 攻撃者が別のユーザーが使用したキーを送信して、そのユーザー向けに意図されたリプレイレスポンスを強制する。
**結果**: BLOCKED — 認証されたシステムでは、冪等性キーはユーザーごとにスコープされるべき（例: `(user_id, key_hash)` の複合キー）。FT がパターンを示す。本番ではユーザースコーピングを追加すること。

---

### ATK-04 — SHA-256 ハッシュによるキー衝突 🚫 BLOCKED

**攻撃**: 攻撃者が同じ SHA-256 ハッシュを持つ 2 つの異なるキーを作成して正当なレコードを上書きしようとする。
**結果**: BLOCKED — SHA-256 の衝突耐性は 2^128 のセキュリティを提供。実用的な衝突攻撃は存在しない。

---

### ATK-05 — 過大サイズのキーヘッダー DoS 🚫 BLOCKED

**攻撃**: 1 MB の `X-Idempotency-Key` ヘッダーを送信してハッシュ処理中にメモリを枯渇させる。
**結果**: BLOCKED — `hash('sha256', ...)` は文字列を処理するが、NENE2 のリクエストサイズミドルウェアが総リクエストサイズを制限する。本番ではキーに長さバリデーション（例: ≤ 255 文字）も追加すること。

---

### ATK-06 — body フィールドへの悪意ある JSON の保存 🚫 BLOCKED

**攻撃**: 決済ボディに制御文字や過大サイズの JSON をインジェクションして、保存された `body` フィールドがリプレイ時に壊れるようにする。
**結果**: BLOCKED — レスポンスボディは保存前に `json_encode` でシリアライズされる。リプレイ時は `JSON_THROW_ON_ERROR` でデコードされる。不正な保存 JSON は暗黙に壊れるのではなく例外をスローする。

---

### ATK-07 — 競合状態 — 並行再試行での二重支出 🚫 BLOCKED

**攻撃**: 同じキーを持つ 2 つの並行リクエストがレコード保存前に競合し、両方が決済を作成する。
**結果**: BLOCKED — `key_hash` は `PRIMARY KEY`。2 番目の並行 INSERT は制約エラーを発生させ、決済が 1 件のみ作成されることを保証する。`SELECT → INSERT` ギャップには DB トランザクションまたは `INSERT OR IGNORE` を使うべき。

---

### ATK-08 — 特殊文字/SQL インジェクションを含むキー 🚫 BLOCKED

**攻撃**: 冪等性キーとして `'; DROP TABLE payments; --` を送信する。
**結果**: BLOCKED — キーは即座に `hash('sha256', $key)` でハッシュ化される。生の文字列は SQL クエリに到達しない。すべての DB アクセスはパラメーター化クエリを使用。

---

### ATK-09 — 422 エラーレスポンスのリプレイ 🚫 BLOCKED

**攻撃**: 攻撃者がキー付きで意図的に無効な最初のリクエスト（422）を送信し、後で同じキーで有効なペイロードを送信して、保存された 422 がリプレイされ決済が暗黙に拒否されることを期待する。
**結果**: BLOCKED — 実装は作成成功後にのみレコードを保存する。422 ブランチは保存せずに即座に返るため、後続の有効な呼び出しが新しい決済を作成する。

---

### ATK-10 — タイミング攻撃によるキー列挙 🚫 BLOCKED

**攻撃**: 攻撃者が「キー存在」（高速な DB ヒット）と「キー未検出」（低速な DB + ビジネスロジック）のレスポンス時間差を計測して有効なキーを確認する。
**結果**: BLOCKED — HTTP レベルでのタイミング差は最小限かつ非決定論的。高セキュリティコンテキストでは人工的な定時間パディングを追加すること。

---

### ATK-11 — 再実行を強制するための冪等性レコード削除 🚫 BLOCKED

**攻撃**: DB 書き込みアクセスを持つ攻撃者が `idempotency_records` 行を削除して次の再試行で再決済を強制する。
**結果**: BLOCKED — DB 書き込みアクセスには別の認証が必要。API コンシューマーは決済 API 経由で冪等性レコードを削除できない。

---

### ATK-12 — X-Idempotent-Replayed ヘッダーの偽造 🚫 BLOCKED

**攻撃**: クライアントがリクエストに `X-Idempotent-Replayed: true` を送信してサーバーをすでにリプレイ済みと思い込ませる。
**結果**: BLOCKED — ヘッダーは*レスポンス*でのみチェックされる。サーバーはリクエストで送信された `X-Idempotent-Replayed` ヘッダーを無視する。リプレイロジックは DB ルックアップのみで決定される。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | キーへの SHA-256 前像攻撃 | 🚫 BLOCKED |
| ATK-02 | レスポンスハイジャックのためのキー推測 | 🚫 BLOCKED |
| ATK-03 | 異なるユーザー間でのリプレイ | 🚫 BLOCKED |
| ATK-04 | SHA-256 ハッシュ衝突 | 🚫 BLOCKED |
| ATK-05 | 過大サイズのキーヘッダー DoS | 🚫 BLOCKED |
| ATK-06 | body への悪意ある JSON | 🚫 BLOCKED |
| ATK-07 | 競合状態での二重支出 | 🚫 BLOCKED |
| ATK-08 | キー経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-09 | 422 エラーレスポンスのリプレイ | 🚫 BLOCKED |
| ATK-10 | タイミング攻撃によるキー列挙 | 🚫 BLOCKED |
| ATK-11 | 再実行を強制するためのレコード削除 | 🚫 BLOCKED |
| ATK-12 | X-Idempotent-Replayed ヘッダーの偽造 | 🚫 BLOCKED |

**12 BLOCKED / SAFE、0 EXPOSED** — 重大な発見なし。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 生の `X-Idempotency-Key` を DB に保存する | DB 漏洩時にキーが漏れる。SHA-256 ハッシュを使うこと |
| キーにユーザースコーピングなし | クロスユーザーのキー衝突でレスポンスハイジャックが可能 |
| ビジネスロジック前に冪等性レコードを保存する | 500/422 エラーを永続的なリプレイとして保存してしまう |
| キー長制限なし | 無制限のキーハッシュが CPU を無駄にする |
| エンドポイント間で冪等性テーブルを共有する | `/payments` の `pay-1` が `/refunds` の `pay-1` と衝突する可能性がある。エンドポイントごとにスコープすること |
