# ハウツー: PATCH + バージョンフィールドによる楽観的ロック

> **FT リファレンス**: FT324 (`NENE2-FT/optlocklog`) — PATCH ベースの楽観的ロック、409 にゼロ GET 再試行用の `current_version` を含む、厳密な整数バージョン型、ATK アセスメント、12 テスト / 24 アサーション PASS。

このガイドでは、`version` フィールドを持つ PATCH による楽観的並行制御の実装方法を解説します。409 レスポンスに現在のサーバーバージョンを返すことで、クライアントは追加の GET なしに再試行できます。

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST`  | `/articles` | 作成する（version=1） |
| `GET`   | `/articles/{id}` | バージョン付きで取得する |
| `PATCH` | `/articles/{id}` | 更新する（バージョンを整数で必須） |

## 作成 & 読み取り

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## バージョン付き PATCH

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

バージョンは **JSON 整数**でなければなりません — 文字列 `"1"` は拒否されます。

## 409 に current_version を含める

競合が検出された場合、レスポンスに `current_version` を含めることでクライアントは再 GET なしに再試行できます:

```php
// バージョン 1 が別のライターにより既に 2 にバンプされている
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← クライアントはこれを直接再試行に使える
}

// クライアントは 409 ボディの current_version で再試行する
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← 成功
```

## 型バリデーション

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // バージョンなし
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // 文字列は int ではない
PATCH /articles/9999 {"version": 1}                      → 404  // 見つからない
```

## 実装

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // 厳密な整数型チェック — "1"（文字列）は拒否される
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← ゼロ GET 再試行を可能にする
        ]);
    }

    // WHERE version = ? によるアトミックな UPDATE
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## ATK アセスメント — クラッカーマインド攻撃テスト

### ATK-01 — バージョンブルートフォースで上書き ✅ SAFE

**Attack**: 攻撃者がバージョン `1, 2, 3…` を順に試し、成功するものを見つけて現在のコンテンツを上書きする。
**Result**: SAFE — ブルートフォースは最終的に現在のバージョンを見つけますが、これは正当な書き込みであり、権限昇格ではありません。オーナーシップ認可（非表示）が無許可の書き込みを防ぎます。

---

### ATK-02 — 文字列バージョンバイパス（`"version": "1"`） 🚫 BLOCKED

**Attack**: 攻撃者が `"version": "1"`（JSON 文字列）を送り、PHP の型強制が整数として扱うことを期待する。
**Result**: BLOCKED — `is_int($version)` は文字列に対して false を返します。400 を返します。

---

### ATK-03 — Float バージョン（`"version": 1.0`） 🚫 BLOCKED

**Attack**: 緩い比較でマッチさせるために `"version": 1.0` を送信する。
**Result**: BLOCKED — PHP では `is_int(1.0)` は false です（float です）。400 を返します。

---

### ATK-04 — バージョンなし → 強制ブラインド書き込み 🚫 BLOCKED

**Attack**: `version` フィールドを省略し、サーバーがデフォルトで更新を受け付けることを期待する。
**Result**: BLOCKED — バージョンなし（null）は `is_int()` チェックで失敗します。400 を返します。

---

### ATK-05 — 負のバージョン 🚫 BLOCKED

**Attack**: バージョン比較で潜在的な 1 つずれを悪用するために `"version": -1` を送信する。
**Result**: BLOCKED — バージョンは 1 から始まり、インクリメントのみです。`-1 !== 1` → 409 conflict。

---

### ATK-06 — 409 の current_version を使った競合状態 🚫 BLOCKED

**Attack**: 攻撃者が 409 から `current_version` を読み取り、すぐに送信して正当な再試行と競合する。
**Result**: BLOCKED — `WHERE version = $current` のアトミックな UPDATE により、バージョンごとに 1 つの並行ライターのみが成功できます。もう一方は再び 409 を受け取ります。これは楽観的ロックの意図した動作です。

---

### ATK-07 — オーバーフローバージョン番号 🚫 BLOCKED

**Attack**: `"version": 9999999999999999999` を送信して int をオーバーフローさせる。
**Result**: BLOCKED — PHP では JSON の大きな整数は float としてデコードされる場合があります; `is_int()` は false を返します。400 を返します。

---

### ATK-08 — ゼロバージョン 🚫 BLOCKED

**Attack**: 最小バージョンを下回るために `"version": 0` を送信する。
**Result**: BLOCKED — バージョンは 1 から始まります。`0 !== 1` → 409 conflict。

---

### ATK-09 — リクエストボディでの current_version 偽造 🚫 BLOCKED

**Attack**: 攻撃者がサーバーがそれを使うことを期待して PATCH ボディに `"current_version": 999` を含める。
**Result**: BLOCKED — `current_version` は*レスポンス*にのみあります。サーバーは未知のリクエストフィールドを無視します; バージョンは `$body['version']` からのみ取得されます。

---

### ATK-10 — バージョンフィールドを介した SQL インジェクション 🚫 BLOCKED

**Attack**: `"version": "1; DROP TABLE articles; --"` を送信する。
**Result**: BLOCKED — DB に達する前に `is_int()` チェックで拒否されます。400 を返します。

---

### ATK-11 — 成功したバージョンをリプレイして再実行 🚫 BLOCKED

**Attack**: 成功した PATCH（バージョン N → N+1）を記録し、同じリクエストを再送する。
**Result**: BLOCKED — 更新後、記事はバージョン N+1 にあります。`version: N` を再送すると 409 が返ります。

---

### ATK-12 — 並行書き込みで両方が成功 🚫 BLOCKED

**Attack**: 同じ `version` を持つ 2 つの同一 PATCH リクエストを同時に送信する。
**Result**: BLOCKED — `UPDATE … WHERE version = ?` はアトミックです。DB は並行書き込みをシリアライズします; 2 番目の UPDATE は 0 行にマッチ → アプリケーションが検出して 409 を返します。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | バージョンブルートフォース | ✅ SAFE（認可の懸念） |
| ATK-02 | 文字列バージョンバイパス | 🚫 BLOCKED |
| ATK-03 | Float バージョン | 🚫 BLOCKED |
| ATK-04 | バージョンなしのブラインド書き込み | 🚫 BLOCKED |
| ATK-05 | 負のバージョン | 🚫 BLOCKED |
| ATK-06 | current_version の競合状態悪用 | 🚫 BLOCKED |
| ATK-07 | オーバーフローバージョン | 🚫 BLOCKED |
| ATK-08 | ゼロバージョン | 🚫 BLOCKED |
| ATK-09 | ボディでの current_version 偽造 | 🚫 BLOCKED |
| ATK-10 | バージョンを介した SQL インジェクション | 🚫 BLOCKED |
| ATK-11 | 成功したバージョンのリプレイ | 🚫 BLOCKED |
| ATK-12 | 並行書き込みで両方が成功 | 🚫 BLOCKED |

**11 BLOCKED, 1 SAFE, 0 EXPOSED** — 重大な発見なし。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| `"version": "1"`（文字列）を受け付ける | PHP の緩い比較 `"1" == 1` は true; 型の混乱攻撃 |
| 409 から `current_version` を省略する | クライアントは追加の GET が必要; 競合時にレイテンシが増加しリクエストが増える |
| アプリケーションレベルのチェックのみ使用（WHERE 句なし） | バージョン読み取りと書き込みの間の競合状態 |
| バージョンなしに 200 を返す | 無条件の上書き — ロストアップデート |
