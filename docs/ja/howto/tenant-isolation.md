# ハウツー: テナント分離とクロステナント IDOR 防止

**FT179 — isolationlog**

マルチテナント API でのクロステナントデータ漏洩の防止 —
スコープ付き SQL クエリ、ヘッダーベースの ID、ボディインジェクション防止。

---

## 脅威: クロステナント IDOR

マルチテナントシステムでは、すべてのリソースがテナントに属します。
あるテナントアカウントを制御する攻撃者が、他のテナントの ID を探索します:

```
GET /notes/42          X-Tenant-Id: 2   ← 攻撃者はテナント 2
                                         ノート 42 はテナント 1 に属する
```

サーバーがノートを返すと、攻撃者は別のテナントのデータを読んでしまいます —
テナント境界での **Insecure Direct Object Reference（IDOR）** です。

---

## 分離パターン

### 1. SQL レベルですべての読み取りをスコープする

ID だけでクエリしないでください。常に `AND tenant_id = ?` を追加してください:

```php
// ❌ 間違い — ID のみ、クロステナントで読み取り可能
'SELECT * FROM notes WHERE id = ?'

// ✅ 正しい — ID + テナントを SQL で強制
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

これはクロステナントアクセスに対して `null` を返し、404 になります。
攻撃者はノート 42 について何も学べません — 存在するかどうかさえわかりません。

### 2. 一覧クエリは常にスコープされる

```php
// ❌ 間違い — ?tenant_id=... インジェクションで補強される可能性がある
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ 正しい — WHERE tenant_id = ? はオプションではない
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. 削除も同じパターンを使用する

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

ノートがテナントに属さない場合、`rowCount()` は 0 を返します → 404。

---

## ヘッダーベースのテナント ID

テナントスコープのエンドポイントには `X-Tenant-Id` + `X-User-Id` ヘッダーを使用してください。
両方を `V::userId()`（ctype_digit + オーバーフローガード + > 0）で検証してください:

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` が拒否するもの:
- 空文字列（`ctype_digit('') === false`）
- ゼロ（`id <= 0`）
- 負の値（`'-'` は `ctype_digit` に失敗）
- 浮動小数点文字列（`'1.5'` は `ctype_digit` に失敗）
- 20 桁以上のオーバーフロー（strlen > 18 ガード）
- SQL インジェクション試行（`'1 OR 1=1'` は `ctype_digit` に失敗）

---

## ボディインジェクション防止

攻撃者は POST ボディに `tenant_id` を含めて、リソースを別のテナントに割り当てようとすることがあります:

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injection", "tenant_id": 99 }
```

**ボディから `tenant_id` を読み取らないでください。** 常にサーバーで検証済みのヘッダーを使用してください:

```php
// ATK-04: body['tenant_id'] は決して読まれない — ヘッダーの $tenantId を常に使う
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            $body からではなく V::userId(X-Tenant-Id) から
```

---

## 書き込み時のテナント存在確認

リソースを作成する前に、テナントが存在することを確認してください:

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant not found.'], 422);
}
```

このチェックがないと、テナントテーブルに存在しないゴースト テナント ID 用のノートが作成され、参照整合性が壊れます。

---

## 攻撃チェックリスト（ATK-01〜ATK-12）

| # | テスト | 期待値 |
|---|------|---------|
| ATK-01 | 認証ヘッダーなし | 401 |
| ATK-02 | クロステナント GET（IDOR） | 404 — ノートは存在するがこのテナントのものではない |
| ATK-03 | X-Tenant-Id: `"1"`、`1.5`、`+1`、`1 OR 1=1` | 401 — V::userId が拒否 |
| ATK-04 | POST ボディに `tenant_id: 99` が含まれる | 201 — ボディの tenant_id は無視される |
| ATK-05 | クロステナント DELETE | 404 — ノートは削除されない |
| ATK-06 | X-Tenant-Id: `0`、`-1` | 401 |
| ATK-07 | X-Tenant-Id: 20 桁のオーバーフロー | 401 |
| ATK-08 | X-Admin-Key なしでテナント作成 | 401 |
| ATK-09 | 不正な X-Admin-Key | 401 |
| ATK-10 | 存在しないテナント ID のノート | 422 |
| ATK-11 | 一覧: T1 は T1 のノートのみ見る、T2 のものは見ない | SQL WHERE tenant_id で強制 |
| ATK-12 | `?limit=-1`、`?limit=10.5`、20 桁の limit | 422 — V::queryInt ガード |

---

## レスポンス戦略: 403 ではなく 404

クロステナント IDOR が検出された場合、**404** を返してください — 403 Forbidden ではなく。

- `403` は存在を漏らします: "リソースは存在するがアクセスできない"
- `404` は何も明かしません: "このテナントにそのようなリソースはない"

これにより、テナント列挙攻撃を防ぎます。
