# Field Trial 103 — Mass Assignment Defence (masslog)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/masslog/`
**NENE2 version:** 1.5.36
**Theme:** マスアサインメント防御 — ユーザー登録 API で `role=admin` や `is_active=false` をリクエストボディに混入されても永続化されないことを、明示的 DTO ホワイトリストパターンで保証する。

---

## What was built

ユーザー登録・一覧取得 API を実装した。攻撃者がリクエストボディに `role`, `is_active`, `id`, `created_at` などのフィールドを混入しても、サーバー側の DTO（`CreateUserInput`）が `name` と `email` のみを受け取るため、権限昇格やデータ改ざんが起きないことを確認した。

---

## Findings

### 1. 明示的 DTO がマスアサインメント防御の核心（摩擦なし）

```php
// ✅ CreateUserInput は name と email しか持たない
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

コントローラーで `$body` 全体を渡さず、ホワイトリストされたフィールドだけを DTO に詰める:

```php
// role・is_active は明示的に除外 — extra fields are silently ignored
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);
```

リポジトリは DTO のフィールドのみを使って INSERT するため、`role` は常に `'user'`、`is_active` は常に `1` が永続化される。

攻撃シナリオの確認:

```php
// ❌ 攻撃者の試み
$res = $this->post('/users', [
    'name'       => 'Attacker',
    'email'      => 'attacker@example.com',
    'role'       => 'admin',    // ← 無視される
    'is_active'  => false,      // ← 無視される
    'created_at' => '2000-01-01 00:00:00', // ← 無視される
    'id'         => 9999,       // ← 無視される
]);

assertSame('user', $data['role']); // ✅ 防御成功
```

---

### 2. 「配列まるごと渡し」は脆弱パターン（高リスク）

フレームワークが薄い場合、初心者がよく書く危険なパターン:

```php
// ❌ $body をそのまま INSERT に使う
$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (:name, :email, :role, :is_active)',
    $body, // ← role=admin が混入してしまう
);
```

または ORM の mass assignment:

```php
// ❌ Laravel 風の危険パターン（NENE2 にはないが、初心者が真似しがち）
User::create($request->all()); // role, is_active が全部入る
```

NENE2 は薄いフレームワークで `create($body)` のようなマジックメソッドを持たないため、この罠は踏みにくい。しかしクエリに `$body` を直接渡せば同じ問題が起きる。

---

### 3. `JsonResponseFactory::createList()` の発見（摩擦あり・低）

最初 `$this->json->create(array_map(...))` でリスト形式の配列を渡したところ PHPStan level 8 が `argument.type` エラーを出した。`create()` は `array<string, mixed>` を期待するが、`array_map` の結果は `list<array<...>>` になるため型が合わない。

`createList()` メソッドが存在することを発見して解決:

```php
// ✅ リスト（JSON 配列）は createList()
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// create() は JSON オブジェクト専用
return $this->json->create(['id' => $user->id, ...], 201);
```

**DX観点:** `create()` と `createList()` の使い分けは PHPDoc には書いてあるが、エラーメッセージだけ見ると「型が合わない」という情報しか得られない。初心者は `create()` だけあれば良いと思い込みやすい。

---

### 4. レスポンスフィールドの明示的制御（情報漏洩防止）

DB カラムが増えても、レスポンスに含めるフィールドを明示的にマッピングすることで、意図しないカラムの露出を防ぐ:

```php
// ✅ 返すフィールドを明示的に列挙
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash は含めない — 明示的な除外
], 201);
```

テストで確認:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

---

## Test results

14 tests, 39 assertions — all pass.

Key behaviors confirmed:
- 正常なユーザー作成（role は常に `user`）
- `role=admin` をリクエストに含めても無視される
- `is_active=false` をリクエストに含めても無視される
- 複数の不正フィールド（`role`, `is_active`, `id`, `created_at`）が全て無視される
- 既存の管理者ユーザーは API 経由で変更されない
- バリデーション: name 必須、email 必須・形式検証、空 name 拒否
- 非 JSON オブジェクトボディ（400）
- email の小文字正規化
- 一覧取得（全ユーザーが `role=user`）
- レスポンスフィールドに内部フィールドが含まれないこと

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**マスアサインメントの概念理解:** 「なぜリクエストのフィールドをそのまま DB に入れてはいけないのか」を最初から知っている初心者は少ない。自分でフォームから送ったデータを INSERT するのが「普通」と思っている。この脆弱性を認識するには「攻撃者の視点」を持つ必要があり、経験が浅いと気づきにくい。

**NENE2 での安全性:** NENE2 が薄いフレームワークであることで逆に安全。`User::create($request->all())` のようなマジックがないため、「`$body` をそのまま使うと危ない」を意識せずとも、DTO に詰め替える工程を踏まざるを得ない。ただし「なぜ DTO に詰め替えるのか」の理由を理解せずにコピペすると、後で `$body['role'] ??  'user'` のような迂回コードを書いてしまう危険がある。

**事故リスク:** 中。NENE2 のパターンを踏んでいれば安全だが、`$body` を直接 SQL パラメータに渡す誘惑がある。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**コピペ可能性:** `CreateUserInput` パターンをコピーして使える。ただし「このフィールドしか受け取らない」という意図を理解していないと、新しいフィールドを追加するとき（例: `bio` フィールド）に `CreateUserInput` ではなく `$body['bio']` から直接取ることで「慣れてきたら省略」してしまう可能性がある。

**他フレームワークの影響:** Laravel や CakePHP の ORM に慣れていると、`$model->fill($request->all())` が頭にある。NENE2 にはそれがないが、「こっちのほうが手間が少なくて良いな」と感じて近似するコードを書く。

**事故リスク:** 中〜高。特に「レガシーコードに機能を追加するとき」にパターンを崩しやすい。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**API 設計の観点:** フロントから「余分なフィールドを送っても無視してくれる API」は扱いやすいが、「どのフィールドが有効か」の定義がドキュメントに書かれていないと混乱する。OpenAPI スキーマで `additionalProperties: false` を設定してリクエストスキーマを明示するのが最善。

**エラーレスポンスの質:** バリデーションエラーの Problem Details は十分。ただし「なぜ role フィールドが無効なのか」のフィードバックが 422 ではなく「単に無視される」ことは、フロントエンド開発者には不透明に感じられる（デバッグ時に「なんで設定したのに反映されないの？」となる）。

**TS の比較:** TypeScript では入力の型を厳密に定義するため、余分なフィールドを送る機会が少ない。しかしバックエンドが型チェックなしで受けると危険。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**他フレームワークとの差異:** Laravel では `$fillable` / `$guarded` プロパティがマスアサインメント防御の仕組みとして提供されている。NENE2 にはそれがなく、DTO パターンが代替となる。DTO パターンのほうが型安全で明示的だが、フィールドが増えるたびに DTO も更新する必要があり、コード量が増える。

`protected $guarded = [];`（全フィールド許可）のような「緩い設定」を NENE2 では書けないため、逆に安全。

**PHPStan との組み合わせ:** `CreateUserInput` が readonly プロパティで構成されているため、PHPStan level 8 が型安全を保証する。`$body['role']` を DTO に渡しそうになったとき、型エラーで気づける（`string|null` を `string` に渡せない等）。

**チームでの安全な共有:** DTO パターンを「コーディング規約」として定義すれば、コードレビューで「`$body` を直接渡していないか」をチェックするルールを追加できる。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**セキュリティ事故リスク評価:** マスアサインメントは OWASP Top 10（A04: インセキュアデザイン）に関連する。特に `role` フィールドの権限昇格は直接的なセキュリティインシデントにつながる（EC サイトで `is_admin=true` を付けて無制限注文するなど）。

NENE2 の薄い設計はここで有利。`create($body)` のような便利メソッドが存在しないため、初学者でも「どのフィールドを INSERT するか」を明示せざるを得ない。

**スケール時の問題:** マイクロサービス間で内部 API を呼ぶとき、信頼されたサービスからは `role` を設定できる必要がある。その場合のパターン（`AdminCreateUserInput` のような別DTO）が howto にあると良い。

**コードレビューチェックポイント:** `$body` が直接 `execute()` や `insert()` のパラメータに渡されていないか、`$body[$key]` が DTO を経由せず直接使われていないか、を確認する。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `DatabaseQueryExecutorInterface::insert()` / `execute()` に `array $params` を渡す設計は、パラメータをどう構築するかをコード側に委ねる — マスアサインメント防御をフレームワークが強制しない代わりに、DTO パターンで補う
- CLAUDE.md「フレームワークマジックでコントロールフローを隠さない」方針と整合: マスアサインメントの防御を明示的にするのが NENE2 の哲学に沿っている
- CLAUDE.md「境界で array のままにせず DTO / value object を使う」— `CreateUserInput` は正しいパターン

**設計上のギャップ:**
1. `JsonResponseFactory::create()` と `createList()` の使い分けが PHPDoc にあるが、エラーメッセージからは発見しにくい — 型エラーが出た時に「`createList()` がある」と知るまでに時間がかかる
2. `additionalProperties: false` の OpenAPI スキーマを合わせて定義すると、フロントエンドとのコントラクトが明確になる（今回の FT では OpenAPI は実装していない）
3. howto: `docs/howto/mass-assignment.md` — DTO ホワイトリストパターン・「配列まるごと渡し」の危険・信頼済みサービス向けの別 DTO パターン

---

## Issues / PRs

- Issue: `docs/howto/mass-assignment.md` — DTO ホワイトリストパターン・権限フィールドの隔離・レスポンスフィールドの明示的制御
