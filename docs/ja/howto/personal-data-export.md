# 個人データエクスポート

GDPR スタイルのデータエクスポートにより、ユーザーは自分のすべての個人データをダウンロードできます。主な懸念事項は: エクスポートペイロードからの機密フィールドの除外、安全なダウンロードトークン、期限切れの強制です。

## コアコンポーネント

- **エクスポートジョブ**: ユーザーを不透明なダウンロードトークンにリンクするレコード。ステータス（pending → ready）と期限切れタイムスタンプを持ちます。
- **プロセスステップ**: ペイロードを構築し、ジョブを ready としてマークするワーカー側の操作。
- **ダウンロード**: トークンでペイロードを取得し、サービング前に期限切れを確認します。

## スキーマ

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## トークン生成

`bin2hex(random_bytes(32))` を使ってください — 64 hex 文字、256 ビットのエントロピー。連番 ID、タイムスタンプ、MD5 ベースのトークンは推測可能であり、ダウンロードトークンには使用してはなりません。

```php
$token = bin2hex(random_bytes(32));
```

## 機密フィールドの除外

エクスポートペイロードには認証情報や、ユーザーが明示的にエクスポートに同意していないフィールドを含めてはなりません。HTTP 層ではなくリポジトリレベルで除外します:

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash は意図的に除外
            // phone は意図的に除外（PII の再同意が必要）
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

パブリックプロファイルエンドポイントにも同じ除外を適用します — `phone`、`password_hash`、および内部フィールドは `GET /users/{id}` レスポンスにも表示してはなりません。

## 期限切れの強制

ダウンロードエンドポイントとプロセスエンドポイントの**両方**で期限切れを強制します:

```php
// downloadExport 内:
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// processExport 内 — 重要: ここでもチェック
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

`processExport` でのチェックなしでは、古いジョブを受け取ったワーカーがダウンロードウィンドウが閉じていても DB にユーザーデータを書き込み、機密ペイロードデータを持つ孤立したレコードが作成されます。

## ステータスフロー

```
pending ──(プロセス呼び出し、期限切れでない)──▶ ready ──(ダウンロード呼び出し)──▶ [ペイロードサービング]
   │                                                       │
   └──(プロセス呼び出し、期限切れ)──▶ 410                    └──(期限切れ)──▶ 410
```

## ダウンロード: 410 Gone vs 404 Not Found

- **404**: トークンがデータベースに存在しない。
- **410 Gone**: トークンは存在するが期限切れ。これが正しいステータスです — リソースは存在していたが、その後削除されました。クライアントはこのシグナルを使ってユーザーに新しいエクスポートをリクエストするよう促せます。

## 設計上の決定

**同期生成ではなく別の `process` ステップを使う理由は?**
エクスポートペイロードは大きくなる場合があります（数年分のアクティビティデータ）。HTTP ハンドラーで同期的に生成するとタイムアウトリスクがあり、ワーカーを拘束します。非同期パターンではユーザーがリクエストして後で確認できます。この FT では、ワーカー呼び出しをシミュレートするためにプロセスステップを API として公開しています。

**エクスポート ID ではなくトークンをダウンロード URL として使う理由は?**
連番整数 ID は IDOR に脆弱です — ユーザー 1 が ID をインクリメントしてユーザー 2 のエクスポートをダウンロードできます。不透明なランダムトークンはダウンロード URL を推測不可能にします。

**`process` はパブリックエンドポイントにすべきか?**
本番では、いいえ。プロセスエンドポイントは内部ワーカーのみが呼び出すべきです（API キー、内部ネットワーク、またはキュー経由で）。この FT ではテスト可能性のために公開されています。トークンのエントロピーが一定の保護を提供しますが、適切なワーカー認証の代替にはなりません。
