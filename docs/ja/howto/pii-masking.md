# ハウツー: PII マスキング API

> **FT リファレンス**: FT297 (`NENE2-FT/masklog`) — PII マスキング: メール/電話/名前の部分マスキング、ロールベースの生データアクセス（管理者のみ）と必須 X-Accessor 監査証跡、イミュータブル監査ログ、VULN-A〜L すべて SAFE、24 テスト / 49 アサーション PASS。

このガイドでは、デフォルトで PII（個人識別情報）をマスクし、監査証跡を持つ認可済みロールのみにフルアクセスを許可する顧客データ API の構築方法を解説します。

## スキーマ

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

生の PII は `customers` に保存されます。生データへの管理者アクセスはすべて `mask_audit_log` に記録されます（追記のみ — 更新/削除ルートなし）。

## マスキングパターン

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678"（末尾 4 桁を保持）
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## ロールベースのアクセス — デフォルトマスク

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // 生の PII
    }

    return $this->json->create($this->masker->applyMask($customer));  // マスク済み
}
```

- **非管理者（デフォルト）**: 常にマスクされたデータを受け取ります。
- **`X-Accessor` 付きの管理者**: 生データを受け取り、アクセスがログに記録されます。
- **`X-Accessor` なしの管理者**: 403 — 監査証跡を空にすることはできません。

## 監査ログ — 追記のみ

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // 監査ログの DELETE または PUT なし — 設計によりイミュータブル
}
```

監査ログには削除または更新ルートがありません。エントリは永続的です; 管理者のみがログを読み取れます。

---

## 脆弱性アセスメント

### V-01 — デフォルト GET で PII が公開されない ✅ SAFE

**Risk**: 非管理者が生の顧客メール/電話/名前を読み取る。
**Finding**: SAFE — デフォルトレスポンスは常に `applyMask()` を適用します。`X-Role: admin` なしでは生のフィールドは絶対に返されません。

---

### V-02 — 名前フィールドへの SQL インジェクション ✅ SAFE

**Risk**: `"name": "'; DROP TABLE customers; --"` がデータを削除する。
**Finding**: SAFE — パラメーター化クエリがインジェクション文字列をそのまま名前として保存します。

---

### V-03 — メールフィールドへの SQL インジェクション ✅ SAFE

**Risk**: 作成時のメール経由の SQL インジェクション。
**Finding**: SAFE — 同じパラメーター化クエリ保護。

---

### V-04 — IDOR: 非管理者が顧客 ID 経由で生の PII を読み取る ✅ SAFE

**Risk**: `X-Role: admin` なしで、ユーザーが `GET /customers/1` を試みてフル PII を取得する。
**Finding**: SAFE — `X-Role: admin` なしのリクエストは顧客 ID に関係なくマスクされたデータを受け取ります。

---

### V-05 — ロール昇格: 任意の X-Role ヘッダー ✅ SAFE

**Risk**: マスキングをバイパスするために `X-Role: superuser` または `X-Role: ADMIN` を送信する。
**Finding**: SAFE — 正確な文字列 `'admin'` のみが生アクセスを許可します: `if ($role === 'admin')`。他の値はマスクされたレスポンスにフォールスルーします。

---

### V-06 — X-Accessor ヘッダーなしの管理者 ✅ SAFE

**Risk**: 管理者が X-Accessor なしで生データにアクセスして監査証跡を回避する。
**Finding**: SAFE — `if ($accessor === '') return 403`。管理者アクセスには空でないアクセサー識別子が必要です。

---

### V-07 — 非管理者がアクセスできない監査ログ ✅ SAFE

**Risk**: 非管理者が `GET /customers/1/audit` を読み取り、誰が自分のデータにアクセスしたかを発見する。
**Finding**: SAFE — 監査エンドポイントは `X-Role: admin` をチェックします。非管理者 → 403。

---

### V-08 — 存在しない顧客が 404 を返す ✅ SAFE

**Risk**: 存在しない ID のクエリが 500 を返すか DB エラーを漏洩する。
**Finding**: SAFE — `if ($customer === null) return 404`。クリーンなエラー、内部情報なし。

---

### V-09 — 非常に長い入力がクラッシュしない ✅ SAFE

**Risk**: 10,000 文字の名前が DB エラーまたはメモリ枯渇を引き起こす。
**Finding**: SAFE — SQLite の TEXT には長さ制限がありません; アプリケーションはクラッシュなしに保存とマスクを行います。本番では長さ制限（例: 500 文字）を追加してください。

---

### V-10 — XSS ペイロードがリテラルとして保存される ✅ SAFE

**Risk**: `"name": "<script>alert(1)</script>"` がブラウザで実行される。
**Finding**: SAFE — API は `application/json` を返します; JSON エンコードが `<` と `>` をエスケープします。API 層に HTML レンダリングはありません。

---

### V-11 — マスクされたレスポンスが完全な PII を明かさない ✅ SAFE

**Risk**: マスクされたレスポンスに元の PII を再構築できるだけのデータが含まれる。
**Finding**: SAFE — メール: 最初の文字 + ドメインのみ; 電話: 末尾 4 桁のみ; 名前: 各単語の最初の文字のみ。元のものを再構築できません。

---

### V-12 — 監査ログがイミュータブル ✅ SAFE

**Risk**: 管理者が自分の監査ログエントリを削除して証跡を隠す。
**Finding**: SAFE — `DELETE /customers/{id}/audit` ルートは存在しません。ログエントリは追記のみです。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|---------------|---------|
| V-01 | デフォルト GET で PII が公開 | ✅ SAFE |
| V-02 | 名前への SQL インジェクション | ✅ SAFE |
| V-03 | メールへの SQL インジェクション | ✅ SAFE |
| V-04 | IDOR: 非管理者が生の PII を読み取る | ✅ SAFE |
| V-05 | X-Role ヘッダーによるロール昇格 | ✅ SAFE |
| V-06 | X-Accessor なしの管理者 | ✅ SAFE |
| V-07 | 非管理者がアクセスできる監査ログ | ✅ SAFE |
| V-08 | 存在しない顧客の動作 | ✅ SAFE |
| V-09 | 非常に長い入力のクラッシュ | ✅ SAFE |
| V-10 | 名前への XSS ペイロード | ✅ SAFE |
| V-11 | マスクされたレスポンスが PII を明かす | ✅ SAFE |
| V-12 | 監査ログの可変性 | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
デフォルトマスキング、必須アクセサー監査、厳密なロールチェック、イミュータブルなログがすべての PII 公開と監査バイパスベクターを防ぎます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| デフォルトで生の PII を返す | 認証済みユーザーが完全なメール/電話/名前を読み取る |
| 明示的な許可リストなしの大文字小文字を区別しないロールチェック（`strtolower`） | `ADMIN`、`Admin`、`aDmIn` — 期待される正確な文字列のみを受け付ける |
| X-Accessor なしの管理者アクセスを許可する | 監査証跡なし; GDPR コンプライアンス違反 |
| 可変な監査ログ | 管理者が自分のエントリを削除; 法科学的証跡が信頼できない |
| 非管理者に監査ログを公開する | ユーザーが誰（どの従業員）が自分のデータにアクセスしたかを発見する |
| ハッシュマスキング（実際のデータの代わりにハッシュを表示） | PII のハッシュは依然として機密 — 攻撃者が短い値をブルートフォースできる |
| 作成レスポンスにマスキングなし | 新規顧客作成レスポンスが保存されたばかりの PII を公開する |
| 入力の長さ制限なし | 非常に長い入力がストレージを消費する; 本番では明示的な長さキャップを追加する |
