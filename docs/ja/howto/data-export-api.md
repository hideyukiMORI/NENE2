# ハウツー: データエクスポート API

> **FT リファレンス**: FT312 (`NENE2-FT/exportlog`) — データエクスポート（GDPR スタイル）: トークンベースダウンロードによる非同期 `pending→ready` ステートマシン、`toPublicArray()` による PII 除外（password_hash と phone は GET レスポンスやエクスポートペイロードに含まれない）、ARGON2ID パスワードハッシュ、64 文字 16 進数エクスポートトークン、期限切れエクスポートに 410 Gone、pending 状態のダウンロード試行に 409、19 テスト / 32 アサーション PASS。

このガイドでは、エクスポートが非同期でトークンで保護され、PII を含むフィールドが漏洩しないユーザーデータエクスポートシステム（GDPR 第 20 条のポータビリティ）の構築方法を示します。

## スキーマ

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 文字の 16 進数
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON、status='ready' 時に設定
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` はダウンロード URL 用の 64 文字 16 進数文字列です。`payload` はエクスポートが処理されるまで null です。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/users` | ユーザーを登録する |
| `GET` | `/users/{id}` | ユーザーを取得する（PII 除外） |
| `POST` | `/users/{id}/export` | データエクスポートをリクエストする → 202 |
| `POST` | `/exports/{token}/process` | エクスポートを処理する（非同期ワーカー） |
| `GET` | `/exports/{token}` | 完了したエクスポートをダウンロードする |

## PII 除外 — toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone と password_hash は意図的にパブリックビューから除外
        ];
    }
}
```

`GET /users/{id}` レスポンスは `toPublicArray()` を呼び出します — 完全な配列は使いません。`phone` と `password_hash` は保存されますが、API 経由では返されません。

同じ除外がエクスポートペイロードにも適用されます: エクスポートは生の DB 行ではなく `toPublicArray()`（または相当のもの）から構築されます。

## パスワードハッシュ — ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID は推奨される現代的なアルゴリズムです（メモリハード、GPU 攻撃に耐性あり）。`PASSWORD_BCRYPT` も許容できますが、GPU クラッキングに対してはより弱いです。

## 非同期エクスポート — pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → data_exports 行を作成: status='pending', token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → ペイロードを構築し、status='ready' に設定

GET /exports/{token}  →  200 OK（ダウンロード）
  → status='ready' の場合はペイロードを返す
```

**エクスポートトークン生成:**
```php
$token = bin2hex(random_bytes(32)); // 64 文字の 16 進数
```

**プロセスハンドラー:**
```php
if ($export->status === 'ready') {
    return 200; // 既に処理済み、冪等
}
if ($export->expiresAt < date('c')) {
    return 410; // 期限切れ — 処理しない
}
// ペイロードを構築して保存
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## ステータスチェック — 409 と 410

```php
// ダウンロードハンドラー
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| 状態 | ダウンロードレスポンス |
|---|---|
| `pending` | 409 Conflict |
| `ready`（期限切れでない） | ペイロード付き 200 OK |
| `ready`（期限切れ） | 410 Gone |

期限切れリソースには 410 Gone を使用します（GDPR: エクスポートデータは無期限に保持すべきではない）。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| GET レスポンスに `password_hash` を含める | パスワードハッシュが公開される。オフラインクラッキングを可能にする |
| 認証なしで GET レスポンスに `phone` を含める | PII 漏洩。ユーザー ID を知っている誰でも電話番号が分かる |
| エクスポートペイロードに `password_hash` を含める | GDPR 違反。エクスポートはユーザー向けのデータポータビリティ文書 |
| `PASSWORD_MD5` または `PASSWORD_DEFAULT` を使用 | 弱いパスワードハッシュ。ARGON2ID にアップグレードする |
| 期限切れエクスポートに 404（410 ではなく）を返す | 404 は「存在しない」と「期限切れ」の区別を隠す |
| pending のダウンロードに 200 を返す | クライアントはエクスポートが準備できたと思う。空または壊れたペイロードを受け取る |
| 短いエクスポートトークン（64 文字未満） | 推測可能なトークン。誰でも任意のユーザーのエクスポートをダウンロードできる |
| エクスポートの `expires_at` なし | エクスポートが無期限に保持される。GDPR コンプライアンスの問題 |
