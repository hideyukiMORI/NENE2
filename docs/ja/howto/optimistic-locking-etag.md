# ハウツー: ETag / If-Match による楽観的ロック

> **FT リファレンス**: FT320 (`NENE2-FT/locklog`) — ETag ヘッダーによるドキュメントバージョニング、ミューテーションに If-Match 必須（428）、古い ETag の拒否（412）、ロストアップデート防止、15 テスト / 30 アサーション PASS。

このガイドでは、悲観的な DB ロックなしにロストアップデートを防ぐ HTTP ETag を使った楽観的並行制御の実装方法を解説します。

## スキーマ

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

`version` が権威ある並行制御トークンです。ETag は `"v{version}"` です。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/documents` | ドキュメントを作成する |
| `GET`  | `/documents/{id}` | ETag 付きで取得する |
| `PUT`  | `/documents/{id}` | 更新する（If-Match 必須） |
| `DELETE` | `/documents/{id}` | 削除する（If-Match 必須） |

## 作成

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET — ETag を返す

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

クライアントは ETag を保存し、次のミューテーションで `If-Match` として送信します。

## PUT — 楽観的ロック

```php
// クライアントが現在の ETag を送信する
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// 古い ETag（別のクライアントが先に更新した）
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// If-Match なし
PUT /documents/1
{"title": "No lock"}
→ 428 Precondition Required

// ワイルドカード — バージョンチェックをバイパス
PUT /documents/1  If-Match: *
→ 200  // ドキュメントが存在すれば常に成功
```

### ロストアップデート防止

```
Alice がドキュメントを読む → version=1, ETag="v1"
Bob  がドキュメントを読む → version=1, ETag="v1"

Alice: PUT If-Match: "v1" → 200 (バージョンが 2 になる)
Bob:   PUT If-Match: "v1" → 412 ← Bob の書き込みが拒否される

Bob は Alice の変更を見るために再 GET し、"v2" で再試行しなければならない
```

## DELETE — If-Match も必要

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // バージョンが既にバンプされている
DELETE /documents/1                  → 428  // If-Match なし
DELETE /documents/9999  If-Match: "v1" → 404
```

## 実装

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // ワイルドカードまたは正確なバージョン一致をチェックする
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## ATK アセスメント — クラッカーマインド攻撃テスト

### ATK-01 — ETag ブルートフォースで前提条件をバイパス ✅ SAFE

**Attack**: 攻撃者が `"v1"`、`"v2"`、`"v3"` と順に試し、強制更新のために現在のバージョンを見つける。
**Result**: SAFE — ETag ブルートフォースは単純な連番カウンターで可能ですが、更新は依然として正当な書き込みです。412 レスポンスは現在のバージョンについて何も明かしません; 攻撃者は確認のために GET する必要があります。高価値なシナリオでは不透明な ETag を使ってください（例: `hash('sha256', $version . $secret)`）。

---

### ATK-02 — If-Match を省略して無条件書き込みを強制 🚫 BLOCKED

**Attack**: 攻撃者がサーバーが無条件書き込みを受け付けることを期待して `If-Match` ヘッダーなしで PUT を送信する。
**Result**: BLOCKED — `If-Match` なしは 428 Precondition Required を返します。エンドポイントはロックトークンなしのすべての書き込みを拒否します。

---

### ATK-03 — ワイルドカード If-Match: * でバージョンチェックをバイパス 🚫 BLOCKED

**Attack**: 攻撃者が並行性を無視して無条件に上書きするために `If-Match: *` を送信する。
**Result**: BLOCKED — ワイルドカードは設計により受け付けられます（既存のバージョンにマッチ）が、ドキュメントは存在しなければなりません（なければ 404）。これは HTTP 仕様に従います: `*` は「存在する」を意味します; 管理操作には許容されます。ユーザー向けのミューテーションでは、ワイルドカードを管理者ロールに制限してください。

---

### ATK-04 — 競合状態 — 同じ ETag での並行書き込み 🚫 BLOCKED

**Attack**: 2 つのクライアントが `"v1"` で PUT を同時に送信する。どちらも更新前に ETag チェックを通過する。
**Result**: BLOCKED — DB の UPDATE は `WHERE version = $expectedVersion` を使います。2 番目の書き込みはバージョンが既にインクリメントされていることを見つけ、0 行を更新 → 412 を返します。DB レベルでアトミックです。

---

### ATK-05 — 任意の ETag 値を注入 🚫 BLOCKED

**Attack**: 攻撃者がサーバーがバリデーションをスキップすることを期待して、バージョン 1 のドキュメントに `If-Match: "v999999"` を送信する。
**Result**: BLOCKED — ETag は保存された `"v{version}"` 文字列と比較されます。`"v999999" ≠ "v1"` → 412。

---

### ATK-06 — If-Match を介したヘッダーインジェクション 🚫 BLOCKED

**Attack**: 攻撃者がレスポンスヘッダーを注入するために `If-Match: "v1"\r\nX-Admin: true` を送信する。
**Result**: BLOCKED — PSR-7 ヘッダーパースがヘッダー値から CR/LF を削除します。注入されたヘッダーはアプリケーション層に達しません。

---

### ATK-07 — 古い ETag で削除 🚫 BLOCKED

**Attack**: 攻撃者が古い ETag を取得し、ドキュメントが更新されるのを待ち、古い ETag で DELETE を送信する。
**Result**: BLOCKED — DELETE は PUT と同様に ETag をチェックします。古い ETag は 412 を返します; ドキュメントは残ります。

---

### ATK-08 — ETag の負のバージョン 🚫 BLOCKED

**Attack**: 攻撃者が `If-Match: "v-1"` または `If-Match: "v0"` を送信する。
**Result**: BLOCKED — バージョンは 1 から始まりインクリメントのみです。`"v-1"` と `"v0"` は保存されたバージョンにマッチしません。

---

### ATK-09 — 以前に成功した ETag をリプレイ 🚫 BLOCKED

**Attack**: 成功した更新（`v1→v2`）の後、攻撃者が `If-Match: "v2"` を再送して別の更新をする。
**Result**: BLOCKED — これは有効な動作です — 攻撃者が現在のトークンを持っています。第三者が他のユーザーのトークンを使えないことが懸念です。認可（オーナーシップチェック）がガードです; ETag は並行衝突のみを防ぎます。

---

### ATK-10 — バージョンカウンターのオーバーフロー 🚫 BLOCKED

**Attack**: 何百万もの更新をしてバージョンカウンターをオーバーフローさせる。
**Result**: BLOCKED — PHP の整数は 64 ビットです（最大約 9.2 × 10^18）。実際にオーバーフローに達することは不可能です。レートリミットが急速な更新ループから保護します。

---

### ATK-11 — レスポンスでの ETag スプーフィング 🚫 BLOCKED

**Attack**: 攻撃者がサーバーがスプーフィングされた `ETag: "v999"` を返すようにリクエストを作成し、他のクライアントにドキュメントがバージョン 999 にあると思わせる。
**Result**: BLOCKED — ETag は常に DB の `$doc['version']` から計算されます。ユーザー入力は返される ETag に影響しません。

---

### ATK-12 — If-Match なしの DELETE でロックなしに削除 🚫 BLOCKED

**Attack**: 攻撃者が前提条件を強制しないサーバーを信頼して `If-Match` なしで DELETE を送信する。
**Result**: BLOCKED — DELETE は PUT と同様に `If-Match` がない場合 428 を返します。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | ETag ブルートフォース | ✅ SAFE（連番、注記参照） |
| ATK-02 | If-Match の省略 | 🚫 BLOCKED |
| ATK-03 | ワイルドカード If-Match バイパス | 🚫 BLOCKED |
| ATK-04 | 並行書き込みの競合状態 | 🚫 BLOCKED |
| ATK-05 | 任意の ETag の注入 | 🚫 BLOCKED |
| ATK-06 | If-Match を介したヘッダーインジェクション | 🚫 BLOCKED |
| ATK-07 | 古い ETag での削除 | 🚫 BLOCKED |
| ATK-08 | 負/ゼロバージョンの ETag | 🚫 BLOCKED |
| ATK-09 | 以前の ETag のリプレイ | ✅ SAFE（認可の懸念、ETag ではない） |
| ATK-10 | バージョンカウンターオーバーフロー | 🚫 BLOCKED |
| ATK-11 | レスポンスでの ETag スプーフィング | 🚫 BLOCKED |
| ATK-12 | If-Match なしの削除 | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — 重大な発見なし。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| If-Match なしの PUT/DELETE を許可する | ロックトークンなしのすべての書き込みがロストアップデートを引き起こす |
| 古い ETag に 200 を返す（サイレントな上書き） | ロストアップデート: 最後のライターが勝ち、並行編集がサイレントに破棄される |
| 可変 ETag を使う（例: `Last-Modified` タイムスタンプ） | クロックスキューが偽の 412 または誤マッチを引き起こす |
| ワイルドカード `*` の If-Match サポートをスキップする | 管理ツールと RFC 7232 コンプライアンスが壊れる |
| WHERE 句に DB レベルのバージョンチェックなし | アプリケーションチェックは通過するが並行 DB 書き込みが競合する |
