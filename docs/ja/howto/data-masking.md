# データマスキングの追加方法

デフォルトで API レスポンスの PII フィールド（email、電話、名前）をマスクし、監査済みの管理者アンマスクパスを提供します。

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

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/customers` | 顧客を作成する（レスポンスはマスク済み） |
| `GET` | `/customers/{id}` | 顧客を取得する（デフォルトはマスク済み、管理者はアンマスク） |
| `GET` | `/customers/{id}/audit` | 監査ログを表示する（管理者のみ） |

## マスキングパターン

```php
class MaskService
{
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // 末尾 4 桁を保持し、その他の桁を文字ごとにマスク
        $digits  = preg_replace('/\D/', '', $phone) ?? '';
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? '*' : $ch;
                if (ctype_digit($ch)) { $replaced++; }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        return implode(' ', array_map(
            fn($w) => mb_substr($w, 0, 1) . '***',
            array_filter(explode(' ', $name))
        ));
    }
}
```

例:
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## ロールベースのアンマスク

ハンドラーは `X-Role` ヘッダーをチェックします。管理者アクセスには監査証跡を強制するために `X-Accessor` が必要です:

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // 生の PII
}

return $this->json->create($this->masker->applyMask($customer));  // マスク済み
```

## 監査ログ

すべての管理者アンマスクは `mask_audit_log` に書き込みます。監査ログには DELETE や UPDATE のルートがありません — 設計上 append-only です。

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## セキュリティ特性

- **デフォルトマスク**: `X-Role: admin` がない限り、すべての GET レスポンスが PII をマスクします。
- **強制 accessor**: 管理者アンマスクには `X-Accessor` が必要です。不在の場合は 403 — 匿名管理者アクセスなし。
- **不変監査**: 監査エントリを削除または更新するルートなし。
- **パラメータ化ストレージ**: PII はプリペアドステートメント経由で保存されます — SQL インジェクション試行はリテラルとして保存されます。
- **ロール精度**: 正確な `admin` 値のみがアンマスクを許可します。`ADMIN`、`superuser` などは通常ユーザーとして扱われます。
