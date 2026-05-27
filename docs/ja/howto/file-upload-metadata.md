# ハウツー: ファイルアップロードメタデータ API（VULN-A〜L）

このガイドでは VULN-A〜L をカバーする安全なファイルアップロードメタデータ管理を実演します。

## パターン概要

このAPIはファイルを保存しません — メタデータ（ファイル名、MIME タイプ、サイズ）のみを記録します。実際のファイル転送は別途処理されます（例: S3 直接転送）。これはアップロード履歴を追跡し制約を強制するための一般的なパターンです。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS uploads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    filename    TEXT    NOT NULL,
    mime_type   TEXT    NOT NULL,
    size_bytes  INTEGER NOT NULL,
    is_public   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

## VULN-A: SQL インジェクション

すべてのクエリは PDO プリペアドステートメントを使用します。ユーザーが送信したファイル名と MIME タイプは SQL 文字列に補間されません。

## VULN-B: マスアサインメント + MIME 許可リスト

明示的な MIME タイプ許可リストのみが受け付けられます:

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

未知の MIME タイプ（例: `application/x-msdownload`、`application/x-sh`）は 422 で拒否されます。

## VULN-C: IDOR

管理者以外のユーザーは自分のアップロードのみにアクセスできます。他のユーザーのアップロードは 404（403 ではなく）を返します:

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D: 管理者フェイルクローズ

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F: パストラバーサル

ディレクトリセパレーターと `..` はファイル名で拒否されます:

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

これにより `../etc/passwd`、`C:\Windows\cmd.exe`、`subdir/evil.php` のようなファイル名を防止します。

## VULN-G: ReDoS

パスパラメーターの ID は `ctype_digit()` で検証され、正規表現は使用しません。

## VULN-I: 負の値 / ゼロ

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

ゼロと負のサイズは拒否されます。

## VULN-J: 型混乱

- `mime_type` は `is_string()` でなければならない — 整数 `123` は拒否される。
- `size_bytes` は `is_int()` でなければならない — 文字列 `"1024"` と浮動小数点 `100.5` は拒否される。
- `is_public` は `is_bool()` でなければならない — 文字列 `"true"` と整数 `1` は拒否される。

## バリデーションまとめ

| フィールド | ルール |
|---|---|
| `X-User-Id` | POST/DELETE に必須。`ctype_digit`、>0 |
| `filename` | 空でない、最大 255 文字、`/`、`\`、`..` なし |
| `mime_type` | 文字列。許可リストに含まれる必要がある |
| `size_bytes` | 整数 1〜104,857,600（100 MiB） |
| `is_public` | ブール値のみ |

## ルート

```
POST   /uploads              アップロードメタデータを登録する（X-User-Id 必須）
GET    /uploads/{id}         メタデータを取得する（オーナーまたは管理者）
DELETE /uploads/{id}         レコードを削除する（オーナーまたは管理者）
GET    /users/{userId}/uploads  ユーザーのアップロードを一覧表示する（オーナーまたは管理者）
```

## 参照

- FT210 ソース: `../NENE2-FT/uploadlog/`
- 関連: `docs/howto/wish-list-api.md`（FT207、VULN も含む）
