# ハウツー: テナント分離と IDOR 防止

> **FT リファレンス**: FT318 (`NENE2-FT/isolationlog`) — マルチテナントデータ分離、クロステナント IDOR 防止、ヘッダー型混乱ハードニング、ボディ tenant_id インジェクション防止、34 テスト / 133 アサーション PASS。

このガイドでは、テナントが他のテナントのデータを読み取り、変更、列挙できないように厳格なテナントレベルのデータ分離を強制する方法を説明します — ヘッダーやリクエストボディを操作されても。

## スキーマ

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## 認証モデル

```
管理者エンドポイント → X-Admin-Key: <server_secret>       （例: env ADMIN_KEY）
テナントエンドポイント → X-Tenant-Id: <int>  X-User-Id: <int>
```

### ヘッダーバリデーションルール

`X-Tenant-Id` と `X-User-Id` は**厳密な正の整数**バリデーションを通過する必要があります:

| 入力 | 結果 |
|-----|------|
| `"1"`（有効） | ✅ 受け入れ |
| `"0"` | ❌ 401 — 0 より大きい必要がある |
| `"-1"` | ❌ 401 — 負の値は拒否 |
| `"1.5"` | ❌ 401 — 浮動小数点数は拒否 |
| `"+1"` | ❌ 401 — 符号プレフィックスは拒否 |
| `"1 OR 1=1"` | ❌ 401 — SQL インジェクション試行は拒否 |
| `""`（不在） | ❌ 401 — ヘッダーなし |
| `"99999999999999999999"`（20 桁） | ❌ 401 — オーバーフローは拒否 |

```php
// ctype_digit + 範囲チェックを使用したバリデーションパターン
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Unauthorized'], 401);
}
```

## 管理者エンドポイント

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// 管理者キーなし
POST /tenants  （X-Admin-Key なし）   → 401
POST /tenants  X-Admin-Key: wrong → 401
```

## テナントエンドポイント — IDOR 防止

### ノート作成（サーバー割り当てテナント）

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Hello"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Hello", ...}
```

**リクエストボディ内の `tenant_id` は常に無視されます。** サーバーはヘッダーの値のみを使用します:

```php
// 攻撃者が X-Tenant-Id: 1 を送るが、ボディはテナント 2 をインジェクトしようとする
POST /notes  X-Tenant-Id: 1
{"content": "Injection", "tenant_id": 2}  // ← 無視される

→ 201  {"tenant_id": 1, ...}   // ボディではなくヘッダーから割り当て
```

### クロステナント IDOR — 404 を返す

```php
// ノート 5 はテナント 1 に属する
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR ブロック
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR ブロック

// 所有者は引き続きアクセスできる
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

すべてのクエリに `WHERE tenant_id = $tenantId` が含まれます。存在しない行は 404 を返します — 存在列挙を防ぐために **403 ではなく** 404 です。

### 一覧の分離

```php
// T1 が 2 件のノート、T2 が 1 件のノートを持つ
GET /notes  X-Tenant-Id: 1  → {"data": [note_A, note_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [note_X],         "tenant_id": 2}
// T2 は T1 のノートを決して見ない
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- 常に検証済みヘッダーの tenant_id でフィルタリング
```

### クエリパラメーターバリデーション

```php
GET /notes?limit=-1       → 422  // 負の値
GET /notes?limit=10.5     → 422  // 浮動小数点数
GET /notes?limit=999999   → 422  // 最大値超過（例: 100）
GET /notes?limit=99999999999999999999  → 422  // オーバーフロー
GET /notes                → 200  // デフォルト制限を適用
```

## 存在しないテナントへのノート作成

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // テナント 9999 が存在しない
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| リクエストボディの `tenant_id` を信頼する | 攻撃者が任意のテナントにノートを割り当てられる |
| IDOR で 404 の代わりに 403 を返す | 403 はリソースが存在することを明かす; 404 は列挙を防ぐ |
| ctype_digit なしでヘッダーを直接キャスト: `(int) $header` | `-1`、`+1`、`1.5`、オーバーフローがすべて予期しない整数を生成する |
| 一覧クエリに `WHERE tenant_id = ?` がない | 完全なクロステナントデータ漏洩 |
| 管理者キーをクライアントレスポンスで共有する | 管理者キーはサーバーサイドのみに留めること |
| `X-Tenant-Id: 0` を許可する | ゼロはデフォルト/未設定状態であることが多い; 正の整数のみ受け入れること |
