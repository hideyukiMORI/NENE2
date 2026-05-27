# プライバシー同意管理の構築方法

> **FT189 consentlog で実証されたパターン** — 不変履歴、IDOR 防止、ユーザー列挙耐性を備えた GDPR スタイルの同意追跡。VULN-A〜L 全 Pass。

---

## 対象領域

プライバシー同意管理フロー:

1. **同意の付与** — ユーザーが指定された目的に対して同意を付与する
2. **同意の撤回** — ユーザーが同意を撤回する
3. **同意一覧** — すべての目的に対する現在の同意状態
4. **履歴** — 目的ごとの不変な追記のみの監査ログ

セキュリティ保証:

| 懸念事項 | 技術 |
|---|---|
| IDOR — 別ユーザーの同意 | すべてのクエリが `WHERE user_id = :user_id` でスコープを絞る |
| マスアサインメント（granted フィールド） | `granted` はサーバー制御; ボディから上書き不可 |
| purpose への SQL インジェクション | `ctype_alnum()` — 英数字のみ |
| purpose の ReDoS | `ctype_alnum()` O(n) — 正規表現なし |
| 型の混乱 | `ctype_alnum()` の前に `is_string()` |
| ユーザー列挙 | 不明ユーザーは 404 ではなく空配列を返す |
| grant/withdraw のレースコンディション | `UNIQUE(user_id, purpose)` に対する UPSERT の原子性 |
| 同意リプレイ | 履歴は追記のみ; 各変更は新しいエントリ |

---

## スキーマ

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- 英数字スラッグ: 'marketing', 'analytics' 等
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=付与, 0=撤回
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=付与, 0=撤回
    created_at TEXT    NOT NULL    -- この変更が発生した時刻
);
```

`UNIQUE(user_id, purpose)` は原子的な upsert を可能にします。`consent_history` は追記のみで更新されません。

---

## API

| メソッド | パス | ヘッダー | 説明 |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | 同意を付与する（201） |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | 同意を撤回する（200） |
| `GET` | `/consents` | `X-User-Id` | 現在の同意を一覧表示する |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | 監査履歴（追記のみ） |

---

## コアパターン: 冪等 UPSERT

```php
// 付与 — 冪等: 既に付与された目的を再付与しても安全
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// 撤回 — 同じパターン
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

`UNIQUE(user_id, purpose)` に対する UPSERT は原子的です — 同時 grant+withdraw による重複行の作成を防ぎます。

---

## コアパターン: 不変履歴

```php
// 常に履歴に追記 — 再付与も記録される
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

履歴は**更新されません** — すべての同意変更の監査ログです。これにより規制当局が同意が付与された時刻と撤回された時刻を検証できます。

---

## コアパターン: purpose バリデーション

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G: 型の混乱 — 文字列でなければならない
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I: ctype_alnum は O(n) — 正規表現なし、ReDoS なし
    // VULN-D: 英数字のみ — HTML なし、SQL 特殊文字なし
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` は `[a-zA-Z0-9]` のみを受け付けます — スペース、ハイフン、SQL メタ文字、HTML タグを O(n) の単一パスで拒否します。

---

## コアパターン: ユーザー列挙防止

```php
// VULN-F: 不明ユーザーには空配列を返す — 404 ではない
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

不明ユーザーに 404 を返すと「この user_id は存在しない」という情報を漏洩します。常に空データで 200 を返してください。

---

## コアパターン: IDOR 防止

```php
// VULN-B: すべての読み取りと書き込みを認証済みユーザーにスコープ
// 攻撃者が X-User-Id: 999 を送っても、ユーザー 999 のデータのみが見える
WHERE user_id = :user_id AND purpose = :purpose
```

クロスユーザークエリが別のユーザーのレコードに触れることはありません。

---

## コアパターン: サーバー制御の granted フィールド

```php
// VULN-C/E: granted はエンドポイントが制御 — ボディからは決して取得しない
// POST /consents → 常に付与（granted = 1）
// DELETE /consents/{purpose} → 常に撤回（granted = 0）
// POST 時のボディ { "granted": false } は黙って無視される
```

エンドポイント自体が `granted` 値を決定します。ボディフィールドはそれを上書きできません。

---

## レスポンス設計

| シナリオ | ステータス | ボディ |
|---|---|---|
| 付与成功 | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| 撤回成功 | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| 同意一覧 | 200 | `{data: [...], total: N}` |
| 履歴 | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| 不明ユーザー | 200 | `{data: [], total: 0}` — 404 ではない |

`user_id` はいかなるレスポンスにも**含まれません** — `X-User-Id` から暗黙的に取得されます。

---

## VULN-A〜L 全 Pass

| VULN | 攻撃 | 防御 |
|---|---|---|
| A | X-User-Id への SQL インジェクション | `ctype_digit()` + strlen > 18 ガード |
| B | IDOR — 他ユーザーの同意を操作 | 全クエリに `WHERE user_id = :user_id` |
| C | マスアサインメント（granted フィールド改ざん） | granted はエンドポイントが決定 — body 非採用 |
| D | purpose への XSS | `ctype_alnum()` — 英数字のみ |
| E | 同意状態の直接書き換え | grant/withdraw は独立エンドポイント |
| F | ユーザー列挙 | 不明 user_id は空配列 200 を返す |
| G | 型の混乱（purpose が int/array/null） | `is_string()` + `ctype_alnum()` |
| H | 同意リプレイ | history は追記のみ、再付与は新エントリ |
| I | purpose の ReDoS | `ctype_alnum()` O(n) |
| J | X-User-Id の整数オーバーフロー | strlen > 18 ガード |
| K | 同時 grant+withdraw レースコンディション | `UNIQUE(user_id, purpose)` UPSERT 原子性 |
| L | ヘッダーへの CRLF インジェクション | PSR-7 が HTTP 層で拒否 |

---

## テスト結果（FT189）

```
51 tests / 142 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
VULN-A〜L 全 Pass
```

ソース: [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
