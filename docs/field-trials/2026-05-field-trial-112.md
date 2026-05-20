# Field Trial 112 — Multi-Tenant Isolation

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/tenantlog/`
**NENE2 version:** 1.5.45
**Theme:** マルチテナント隔離 — `tenant_id` を全クエリに含める（漏れると即 IDOR）、JWT クレームにテナント ID を含める、クロステナントアクセスは 403 ではなく 404、レスポンスに `tenant_id` を含めない、`assertIsList()` と `list<>` の PHPStan 型。

---

## What was built

2テナント（Acme Corp / Beta Inc）が共存するノート API を実装した。

- `POST /auth/login` — JWT を返す（`tenant_id` クレーム含む）
- `GET /notes` — 自分のテナントのノートのみ
- `POST /notes` — 自分のテナントにノートを作成
- `GET /notes/{id}` — 自分のテナントのノートのみ（他テナントは 404）
- `DELETE /notes/{id}` — 自分のテナントのノートのみ削除

---

## Findings

### 1. `tenant_id` フィルターを全クエリに必須化する（最重要）

マルチテナントの最大の落とし穴: テナントフィルターを一つでも書き忘れると、全テナントのデータが見える IDOR が発生する:

```sql
-- ❌ テナントフィルターなし — 全テナントのノートが返る
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ 必ずテナントフィルターを含める
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

実装の安全パターン: リポジトリのメソッドシグネチャに `int $tenantId` を強制する:

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    return $this->executor->fetchOne(
        'SELECT ... FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );
}
```

メソッド名に `ForTenant` サフィックスを付けることで「テナント絞り込み済み」を明示する。

---

### 2. クロステナントアクセスは 404 を返す（403 ではない）

他テナントのリソースに 403 Forbidden を返すと「そのリソースは存在するが権限がない」という情報が漏洩する:

```php
// ❌ 403 を返すとクロステナント情報が漏洩する
if ($note->tenantId !== $tenantId) {
    return 403; // "このノートは存在するが、あなたのテナントではない" を暗示
}

// ✅ 自テナント以外は「存在しない」として扱う — tenant_id フィルターをクエリに含める
$note = $this->findByIdForTenant($id, $tenantId);
if ($note === null) {
    return 404; // 存在するかどうかすら答えない
}
```

`WHERE id = ? AND tenant_id = ?` で検索すれば、他テナントのレコードは自然に `null` になる。ハンドラー側でテナント判定を書く必要がない。

---

### 3. JWT クレームに `tenant_id` を含める

ユーザーのテナント所属はログイン時に確定する。毎リクエスト DB を引く代わりに JWT クレームに埋め込む:

```php
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // int として埋め込む
    'email'     => $user->email,
    'iat'       => time(),
    'exp'       => time() + 3600,
]);
```

ハンドラーでの取得（型チェック必須）:

```php
/** @var array<string, mixed>|null $claims */
$claims = $request->getAttribute('nene2.auth.claims');

if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
    return null; // 401
}

$tenantId = $claims['tenant_id']; // int
```

`is_int()` チェックが重要: `tenant_id` が文字列として保存されていると SQL でテナント比較が正しく機能しない可能性がある（SQLite は型変換するが、MySQL/PostgreSQL では問題になる）。

---

### 4. レスポンスに `tenant_id` を含めない

`tenant_id` はインフラ内部の識別子であり、クライアントが知る必要はない。全テナントの ID が分かると列挙攻撃の起点になる:

```php
// ❌ tenant_id が漏洩する
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // 不要
    'title'     => $note->title,
]);

// ✅ フロントエンドに必要なフィールドのみ返す
return $this->json->create([
    'id'    => $note->id,
    'title' => $note->title,
    'body'  => $note->body,
]);
```

---

### 5. PHPStan level 8 の `list<>` vs `array<>` 型（摩擦あり）

`json_decode()` の戻り値は `mixed` 型。`assertIsArray()` 後も PHPStan は `array<mixed>` と判断し、`list<array<string, mixed>>` を要求するメソッドの戻り値として使えない:

```php
// ❌ PHPStan エラー: array<mixed> might not be a list
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode(...);
    $this->assertIsArray($data);
    return $data; // PHPStan: array<mixed> は list<array<string, mixed>> を保証しない
}

// ✅ assertIsList() を追加して list であることを保証
$this->assertIsArray($data);
$this->assertIsList($data);
return $data; // PHPStan が list<mixed> として認識
```

---

## Test results

13 tests, 61 assertions — all pass.

Key behaviors confirmed:
- JWT クレームに `tenant_id` が含まれる（int）
- ノート一覧は自テナントのみ（他テナントのノートは見えない）
- 他テナントのノート ID で GET → 404（403 ではない）
- 他テナントのノート ID で DELETE → 404、元のノートは削除されていない
- 改ざんトークン（署名無効）で GET → 401
- 認証なしで全エンドポイント → 401
- レスポンスに `tenant_id` が含まれない
- 自テナントのノートは正常に CRUD できる

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**「全ユーザーのデータが見えてしまう」事故:** `WHERE id = ?` だけを書いて `AND tenant_id = ?` を忘れると、他の会社のデータが見えてしまう。「え、これって個人情報漏洩じゃ...」となるまでバグに気づかないケースが多い。テナントフィルターを書き忘れると何が起きるかを具体的な例で示すことが重要。

**事故リスク:** 非常に高。テナントフィルターの書き忘れは静かに起きる。テストを書かないと発見できない。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**「ログインユーザーのIDでフィルターすれば大丈夫」の誤解:** ユーザーID（`user_id`）でフィルターすることと、テナントID（`tenant_id`）でフィルターすることの違いが分かりにくい。「同じテナントの他ユーザーが作ったデータを見せたい」というユースケースがあるとき、`user_id` フィルターでは不十分で `tenant_id` フィルターが必要。

**403 vs 404 の選択を知らない:** クロステナントアクセスに 403 を返すことが情報漏洩につながるという発想がない。「権限がないなら 403 でしょ？」と考えてしまう。

**事故リスク:** 高。テナント設計の理解不足が最大のリスク。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**JWT クレームのテナント ID:** フロントエンド側で JWT ペイロードをデコードして `tenant_id` を取得し、URL に埋め込む実装（`/api/tenants/{tenant_id}/notes`）と、サーバー側で JWT から自動取得する設計の違いが分かると、どちらが安全かを理解できる。URL に `tenant_id` を埋め込む場合は改ざんリスクがあるため、必ずサーバー側で JWT の `tenant_id` と照合する。

**事故リスク:** 低〜中。URL の `tenant_id` をそのまま信頼する実装がリスク。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**Laravel Tenant との比較:** `stancl/tenancy` などのパッケージは自動的にテナントコンテキストを設定し、クエリに `tenant_id` を自動付加するが、NENE2 では手動で全クエリにフィルターを書く。「フレームワークマジックなし」の哲学と整合しているが、書き漏れリスクは高い。PHPStan level 8 でカバーできない（SQL 文字列のチェックは静的解析では困難）。

**テナント切替のリスク:** テナントコンテキストがグローバル状態（シングルトン・スタティック変数）に保存されている場合、テナント切替のタイミングでデータが混在するリスクがある。PSR-7 の Request attribute にテナントコンテキストを持たせることで、リクエストスコープに限定できる。

**事故リスク:** 低（経験者はテナントフィルターの重要性を知っている）。ただし書き漏れのコードレビューに注意。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. 全ての SELECT/UPDATE/DELETE に `WHERE tenant_id = ?` が含まれているか
2. テナント ID が JWT から取得されているか（URL パラメータを信頼していないか）
3. クロステナントアクセスが 404 を返しているか（403 は情報漏洩）
4. レスポンスに `tenant_id` が含まれていないか
5. テナント境界をまたぐ JOIN がないか（tenant_id フィルターなしの JOIN）
6. `is_int($claims['tenant_id'])` の型チェックがあるか

**テスト必須:** テナント分離は必ずクロステナントアクセスを試みるテストで確認する。単機能のハッピーパステストだけでは隔離の欠陥を発見できない。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- テナント ID を PSR-7 Request attribute 経由で渡す設計はリクエストスコープに限定 — 良い設計
- `findByIdForTenant()` メソッドパターンはテナントフィルターの書き漏れを防ぐ — 良い設計

**設計上のギャップ:**
1. マルチテナント隔離の howto が未作成
2. `assertIsList()` PHPUnit アサーションが `list<>` 型を PHPStan に伝える仕組みが未文書

---

## Issues / PRs

- Issue: `docs/howto/multi-tenant-isolation.md` — 全クエリに `tenant_id` フィルター・JWT クレームにテナント ID・クロステナント 404 vs 403・レスポンスからテナント ID を除外・`assertIsList()` PHPStan 型
