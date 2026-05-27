# ハウツー: 委任アクセスグラント

> **FT リファレンス**: FT282 (`NENE2-FT/grantlog`) — 委任アクセスグラント: スコープ付き（read/write/admin）時間制限リソースアクセス、UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee)、IDOR → 404、ソフトデリート失効、使用回数追跡、GrantScope.satisfies() 階層、23 テスト / 71 アサーション PASS。
>
> FT176 でも検証済み — 元の実装。

ユーザーごと、時間制限付き、失効可能なアクセス委任 — グランターがグランティーに名前付きリソースへのスコープ付きアクセスを制限された時間枠で付与します。

---

## 概要

委任アクセスグラントにより、1 人のユーザー（グランター）が別のユーザー（グランティー）にリソース識別子への時間制限付きスコープアクセスを付与できます。「document:42 を読み取り専用でユーザー 7 と 24 時間共有する、いつでも失効可能」のようなイメージです。

主要な特性:

- **マルチパーティ** — グランターとグランティーは常に異なるユーザー。自己グラントは拒否されます。
- **ステートマシン** — active → revoked（一方向）。expired 状態は `expires_at` から計算されます。
- **不透明なリソース** — `resource` は自由形式の文字列。サーバーはそのまま保存します。
- **冪等な一意性** — `(grantor_id, grantee_id, resource)` ごとに 1 つのユニークなグラント。
- **IDOR セーフ** — すべての所有権チェックは存在列挙を防ぐために 403 ではなく 404 を返します。

---

## スキーマ

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

`CHECK (grantor_id != grantee_id)` は多層防御の手段です — 明確なエラーレスポンスのために自己グラントはアプリケーション層でも拒否する必要があります。

---

## ドメイン層

### 階層を持つ GrantScope enum

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Grant エンティティ — 計算済み状態メソッド

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

**先に失効を確認**し、次に有効期限を確認します — 両方のパスは 403 を返しますが、システムの内部を公開せずにグランティーがアクセスが失敗した理由を理解できるように異なるエラーボディを持ちます。

---

## HTTP エンドポイント

| メソッド | パス | 認証 | 目的 |
|--------|------|------|---------|
| `POST` | `/grants` | `X-User-Id`（グランター） | グラントを作成する |
| `GET` | `/grants/issued` | `X-User-Id` | 呼び出し元が発行したグラントを一覧表示する |
| `GET` | `/grants/received` | `X-User-Id` | 呼び出し元が受け取ったグラントを一覧表示する |
| `DELETE` | `/grants/{id}` | `X-User-Id`（グランターであること） | グラントを失効させる |
| `POST` | `/grants/{id}/use` | `X-User-Id`（グランティーであること） | グラントを使用する |

---

## バリデーションルール

| フィールド | ルール |
|-------|------|
| `grantee_id` | **JSON 整数** > 0 であること。文字列 `"2"`、null、boolean、浮動小数点は拒否 |
| `resource` | 空でない文字列。UTF-8 で 500 文字以下。そのまま保存（不透明） |
| `scope` | `read` / `write` / `admin` のいずれかであること |
| `expires_at` | 有効な ISO 8601。将来の日時であること。現在から 30 日以内 |
| 自己グラント | `grantee_id == grantor X-User-Id` → 422 |

### 厳格な整数フィールドパース

一般的な脆弱性は暗黙の型変換です — `"2"`（JSON 文字列）を `2`（int）として受け入れる。明示的な型チェックを使用してください:

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() は "2", null, true, 2.5 に対して false を返す — PHP int のみ true
    return is_int($body[$key]) ? $body[$key] : null;
}
```

注意: `2.0`（PHP float）は `json_encode` 後は `2`（int）と区別できません — 単体テストで浮動小数点拒否をテストするには `2.5` を使用してください。

---

## ステートマシン

```
         revoke()
active ─────────────→ revoked   （2 回目の revoke は 409）
  │
  │ expires_at ≤ now
  ↓
expired

revoked + expired → revoked が勝つ（先に revoked をチェック）
```

二重失効は **409** で拒否する必要があります。サイレントに受け入れてはいけません。
`revoked_at` タイムスタンプは 2 回目の呼び出しで変更してはいけません。

---

## IDOR 保護パターン

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// 「見つからない」と「あなたのグラントではない」の両方に 404 を返す
// ここでは 403 は返さない — 存在を漏洩する
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

同じパターンが `POST /grants/{id}/use` にも適用されます — 呼び出し元がグランティーでない場合は 404 を返します。

---

## マルチパーティの混同防止

| シナリオ | 期待される動作 |
|----------|----------|
| グランターが `POST /grants/{id}/use` を呼び出す（自分のグラント） | 404 — グランターはグランティーではない |
| グランティーが `DELETE /grants/{id}` を呼び出す | 404 — グランティーはグランターではない |
| ユーザー 3 がユーザー 1 と 2 の間のグラントで呼び出す | 404 — IDOR |
| `X-User-Id: 0` または `X-User-Id: -1` | 401 — 非正の ID は拒否 |
| `X-User-Id` なし | 401 |

---

## セキュリティチェックリスト（ATK-01 〜 ATK-12）

| # | 攻撃ベクター | 軽減策 |
|---|---|---|
| ATK-01 | 期限切れグラント（クロック境界） | `isExpired()` 比較。テストで DB の `expires_at` を過去に設定 |
| ATK-02 | 失効グラントの状態バイパス | 使用前の `isRevoked()` チェック |
| ATK-03 | 自己グラント（grantor == grantee） | アプリ層 422 + DB `CHECK` |
| ATK-04 | 間違ったグランティーがグラントを使用（IDOR） | 403 ではなく 404 |
| ATK-05 | 非グランターがグラントを失効（IDOR） | 403 ではなく 404。元のグラントは有効なまま |
| ATK-06 | 作成時に過去の `expires_at` | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | `grantee_id` の型混同 | `is_int()` 厳格チェック。`"2"`、`null`、`true`、`2.5` を拒否 |
| ATK-08 | `resource` のパストラバーサル | 不透明なストレージ。ファイルシステムアクセスなし |
| ATK-09 | `resource`/`scope` の SQL インジェクション | パラメータ化クエリ。scope enum が注入値を拒否 |
| ATK-10 | `resource` の Unicode/BIDI | そのまま保存。ホモグリフと BIDI は異なるリソース |
| ATK-11 | 二重失効（ステートマシン） | 2 回目の失効で 409。`revoked_at` は最初の後は不変 |
| ATK-12 | グランターが自分のグラントをグランティーとして使用 | 404 — パーティロールが厳格に強制される |

---

## テストアプローチ

- **ATK-01、ATK-02**: 時間経過のシミュレートにスリープせず、DB を直接更新（`UPDATE grants SET expires_at/revoked_at`）して状態を強制します。
- **ATK-07**: `"2"`（文字列）、`null`、`true`、`2.5`（浮動小数点）をテストします。`2.0` は PHP json_encode 後に int と区別できないのでテスト不可。
- **ATK-10**: `"\u{202E}"`（BIDI オーバーライド）とキリル文字のホモグリフを使ってそのまま保存されることを確認します。
- **ATK-11**: 2 回目の失効試行後も DB の `revoked_at` が変わっていないことをアサートします。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE (grantor_id, grantee_id, resource)` なし | 同じペアが重複グラントを作成できる。グランティーは同じリソースに古いグラントと有効なグラントを持つ |
| 失効時にハード削除 | 監査履歴が失われる。アクセスが削除された時期や使用回数がわからなくなる |
| 所有権チェックに 404 の代わりに 403 を返す | 不正な呼び出し元にグランドの存在が分かる。IDOR 列挙のサーフェス |
| `CHECK (grantor_id != grantee_id)` なし | 多層防御が欠如。アプリ層チェックがバイパスされると自己グラントが通り抜ける |
| 自由形式のスコープ文字列を受け入れる | タイポがサイレントに `read` にデフォルトする。`GrantScope::tryFrom()` で未知の値を拒否する |
| `satisfies()` 階層なしのスコープチェック | `write` ユーザーが `read` チェックを個別に通過する必要がある。下位レベルすべてをチェックするために階層を使用する |
| `expires_at` の最大 TTL なし | グランターが 100 年のグラントを作成できる。実質的に恒久的なアクセスでレビューなし |
| リソース長制限なし | 10MB のリソース文字列が低速なインデックスルックアップとメモリ割り当てを引き起こす |
| 失効前に有効期限を確認 | 失効 + 期限切れのグラントは「失効済み」を示すべき — ステートマシンでは失効が優先 |
| 使用回数をクライアント側で追跡 | クライアントが使用回数を報告する。サーバーがカウンターを所有する必要がある |
