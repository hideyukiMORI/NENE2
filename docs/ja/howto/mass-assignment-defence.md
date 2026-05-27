# ハウツー: 明示的 DTO によるマス代入防御

> **FT リファレンス**: FT256 (`NENE2-FT/masslog`) — 明示的 DTO ホワイトリストによるマス代入防御パターン
> **ATK**: FT256 — クラッカー思考攻撃テスト（ATK-01 〜 ATK-12）

呼び出し元が設定を許可されたフィールドのみをホワイトリストに入れる明示的な readonly DTO を使って、マス代入の脆弱性を防止する方法を実証します。サーバー制御フィールド（`role`、`is_active`、`created_at`、`id`）は DTO から除外され、リポジトリでハードコードされます。完全なクラッカー思考攻撃評価を含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/users` | ユーザーを作成する（role=user） |
| `GET` | `/users` | すべてのユーザーを一覧表示する |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` は DB レベルのセーフティネットです。アプリケーションは作成時に常に `'user'` を `role` に書き込むため、通常の操作では制約はトリガーされません — バグや DB への直接アクセスから守ります。

---

## 明示的 DTO: フィールドのホワイトリスト

```php
/**
 * ユーザー作成の明示的 DTO — ユーザー入力から受け付けるのは name と email のみ。
 *
 * role と is_active は意図的に除外されています: サーバーサイドのビジネスロジックで
 * 設定されなければならず、リクエストボディからは設定されません。
 * これがマス代入防御です。
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

DTO にはちょうど 2 つのフィールド — `name` と `email` — があります。`role`、`is_active`、`created_at`、`id` フィールドはありません。コンストラクターがそれらを受け付けないため、攻撃者はこれらのフィールドをインジェクションできません。

**ブロックリストより優れている理由**:

| アプローチ | セキュリティモデル | 失敗モード |
|---------|-------------|---------|
| 明示的許可リスト（DTO） | 未知をデフォルトで拒否 | 安全 — 新しいフィールドは明示的に追加が必要 |
| ブロックリスト（`unset($body['role'])`） | 既知の悪いものをブロック | 危険 — 新しい機密フィールドが忘れられる |
| `array_intersect_key` | 既知のキーにフィルタリング | 許容可能 — キーが完全なら許可リストと同等 |

明示的 DTO は安全に失敗します: スキーマに新しい機密カラムを追加しても自動的に公開されません — 開発者は明示的に DTO に追加する必要があります。

---

## コントローラー: 明示的フィールド抽出

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // 許可されたフィールドのみがマップされます — 余分なフィールド（role、is_active 等）はサイレントに破棄されます
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

コントローラーは `$body['name']` と `$body['email']` を明示的に読み取ります。`$body` 内の他のすべてのキーはサイレントに破棄されます — 読み取られることも渡されることもありません。

メールは DTO を作成する前に小文字に正規化され（`strtolower`）、大文字小文字だけが異なる重複メールを防止します。

---

## リポジトリ: サーバー制御フィールド

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role と is_active はハードコード
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // ハードコード、$input からではない
        isActive:  true,      // ハードコード、$input からではない
        createdAt: $now,
    );
}
```

`'user'` と `1` は INSERT のリテラル値です。ユーザー入力が `role` または `is_active` に影響する方法はありません。`CreateUserInput` DTO 型シグネチャが PHP 型レベルでこれを強制します。

---

## ATK — クラッカー思考攻撃テスト（FT256）

### ATK-01 — ロール昇格: リクエストボディに `role: "admin"` をインジェクション

**攻撃**: リクエストボディに `role` を含めて管理者ユーザーを作成する。

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**観察結果**: `role` は `CreateUserInput` のフィールドではありません。コントローラーは `$body` から `name` と `email` のみを読み取ります。余分なキーはサイレントに破棄されます。作成されたユーザーは `role = 'user'` を持ちます。

**判定**: 🚫 BLOCKED — 明示的 DTO フィールドホワイトリストが権限昇格を防止します。

---

### ATK-02 — アカウント状態操作: `is_active: false` をインジェクション

**攻撃**: 無効なアカウントを作成するかフィールドが書き込み可能かをテストするために `is_active = false` でユーザーを作成する。

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**観察結果**: `is_active` は `CreateUserInput` にありません。作成されたユーザーは `is_active = true`（INSERT でハードコード）を持ちます。

**判定**: 🚫 BLOCKED — `is_active` はリクエストから決して読み取られません。

---

### ATK-03 — タイムスタンプ操作: `created_at` をインジェクション

**攻撃**: ユーザーの作成タイムスタンプを過去の日付にする。

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**観察結果**: `created_at` は `CreateUserInput` にありません。リポジトリは書き込み時に `DateTimeImmutable` から `$now` を生成します。

**判定**: 🚫 BLOCKED — 監査タイムスタンプはサーバーが生成し、クライアントが提供しません。

---

### ATK-04 — ID ハイジャッキング: `id: 9999` をインジェクション

**攻撃**: 既存のレコードを上書きしたり既知の ID を取得するために主キーを事前選択する。

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**観察結果**: `id` は `CreateUserInput` にありません。INSERT は `AUTOINCREMENT` を使用します — `id` はユーザーが提供する値からではなく SQLite によって割り当てられます。

**判定**: 🚫 BLOCKED — 主キーの割り当ては常にサーバーサイドです。

---

### ATK-05 — name または email 経由の SQL インジェクション

**攻撃**: SQL メタ文字を埋め込む。

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**観察結果**: 両フィールドは INSERT でパラメーター化された `?` プレースホルダーとしてバインドされます。インジェクションペイロードはリテラルテキストとして保存されます。

**判定**: 🚫 BLOCKED — パラメーター化クエリが SQL インジェクションを防止します。

---

### ATK-06 — メール大文字小文字バイパス: 大文字メールを送信

**攻撃**: `admin@example.com` とは別のユーザーとして `ADMIN@EXAMPLE.COM` を登録する。

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**観察結果**: コントローラーは DTO に渡す前に `strtolower()` を適用します。`ADMIN@EXAMPLE.COM` と `admin@example.com` の両方が `admin@example.com` に正規化されます。`UNIQUE` 制約が 2 回目の登録を防止します。

**判定**: 🚫 BLOCKED — 大文字小文字の正規化 + UNIQUE 制約が重複アカウントを防止します。

---

### ATK-07 — 重複メール: 同じアドレスを 2 回登録する

**攻撃**: 同じメールアドレスを登録してエラーをトリガーするか重複アカウントを作成する。

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**観察結果**: 最初のリクエストは `201` で成功します。2 番目のリクエストは SQLite の `UNIQUE` 制約違反をトリガーします。現在の実装はこの例外をキャッチしません — 未処理のエラーとして伝播します。

**判定**: ⚠️ EXPOSED — UNIQUE 制約違反をキャッチして、構造化された `409 Conflict` または `422 Unprocessable Entity` レスポンスを返してください。生の DB エラーを漏洩することはセキュリティと UX の問題です。

---

### ATK-08 — name または email への XSS ペイロード

**攻撃**: スクリプトタグを保存する。

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**観察結果**: コンテンツはそのまま保存され、JSON でそのまま返されます。API は HTML エンコードされた出力を行いません。

**判定**: ACCEPTED BY DESIGN — JSON API は生のコンテンツを返します。レンダリング層が HTML に挿入する前にサニタイズする必要があります。

---

### ATK-09 — 必須フィールドが欠落

**攻撃**: `name` または `email` を省略する。

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**観察結果**: それぞれが欠落しているフィールドを名前で識別する構造化された `errors` 配列付きの `422 Unprocessable Entity` を返します。

**判定**: 🚫 BLOCKED — 各必須フィールドの明示的な存在チェック。

---

### ATK-10 — 型混乱: name を整数として送信

**攻撃**: `name` を JSON 数値として送信する。

```json
{"name": 12345, "email": "typed@example.com"}
```

**観察結果**: `is_string($body['name'])` は整数値に対して `false` を返します。リクエストは `name is required` で `422` を返します。

**判定**: 🚫 BLOCKED — `is_string()` が非文字列型を拒否します。

---

### ATK-11 — 非常に長い name または email

**攻撃**: 10,000 文字以上の name または email を送信する。

```json
{"name": "aaaa...aaaa (10000 文字)", "email": "x@example.com"}
```

**観察結果**: リクエストは `201` で成功します。`name` または `email` には長さバリデーションが適用されません。SQLite は TEXT を固有の長さ制限なしで保存します。

**判定**: ⚠️ EXPOSED — 長さバリデーションを追加してください（例: `mb_strlen($name) > 255 → 422`）。外部の制限としてリクエストサイズミドルウェアに依存してください。

---

### ATK-12 — 複数のロール値: 配列としてインジェクション

**攻撃**: `role` を文字列ではなく配列として送信する。

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**観察結果**: `role` は `$body` から全く読み取られません。それが文字列、配列、または null であるかどうかは作成されたユーザーに影響しません。

**判定**: 🚫 BLOCKED — DTO は `role` を完全に除外します。その型は無関係です。

---

## ATK サマリー

| # | 攻撃ベクトル | 判定 |
|---|------------|------|
| ATK-01 | `role: "admin"` によるロール昇格 | 🚫 BLOCKED |
| ATK-02 | `is_active: false` によるアカウント状態操作 | 🚫 BLOCKED |
| ATK-03 | `created_at` によるタイムスタンプ過去改ざん | 🚫 BLOCKED |
| ATK-04 | `id: 9999` による ID ハイジャッキング | 🚫 BLOCKED |
| ATK-05 | name/email 経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | メール大文字小文字バイパス（`ADMIN@EXAMPLE.COM`） | 🚫 BLOCKED |
| ATK-07 | 重複メール（エラー処理なし） | ⚠️ EXPOSED |
| ATK-08 | name への XSS ペイロード | ACCEPTED BY DESIGN |
| ATK-09 | 必須フィールドが欠落 | 🚫 BLOCKED |
| ATK-10 | 型混乱（name を整数として） | 🚫 BLOCKED |
| ATK-11 | 非常に長い name または email（長さ制限なし） | ⚠️ EXPOSED |
| ATK-12 | ロールを配列として | 🚫 BLOCKED |

**本番前に修正すべき本物の脆弱性**:
1. **ATK-07** — UNIQUE 制約違反をキャッチして、ユーザー向けメッセージ付きの `409 Conflict` を返す
2. **ATK-11** — `name` と `email` に `mb_strlen` 長さバリデーションを追加する

---

## 関連 howto

- [`mass-assignment.md`](mass-assignment.md) — マス代入防御パターン概要
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防止のための所有権スコープクエリ
- [`rbac.md`](rbac.md) — JWT クレームによるロールベースアクセス制御
- [`user-profile-management.md`](user-profile-management.md) — フィールドホワイトリストによるプロフィール更新
