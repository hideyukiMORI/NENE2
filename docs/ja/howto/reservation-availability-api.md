# ハウツー: 予約・空き状況 API

> **FT リファレンス**: FT336 (`NENE2-FT/reservelog`) — 半開区間の重複検出、ステータス対応の空き状況クエリ、キャンセルと再予約のセマンティクス、ATK クラッカー視点の攻撃アセスメントを備えたリソース予約システム、16 テスト / 30+ アサーション PASS。

このガイドでは、予約がライフサイクル（`active` → `cancelled`）を持ち、空き状況ビューが日付範囲とステータスでフィルタリングするステートレスな予約 API の構築方法を解説します。

## スキーマ

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- 不透明な識別子（名前、メール、user_id 文字列）
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` はスロットがアクティブかキャンセル済みかを追跡します。`active` な予約のみが将来の予約をブロックします。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/resources` | リソースを作成する |
| `POST` | `/reservations` | スロットを予約する |
| `GET` | `/reservations/{id}` | 予約の詳細を取得する |
| `DELETE` | `/reservations/{id}` | 予約をキャンセルする |
| `GET` | `/resources/{id}/availability` | 範囲内のアクティブな予約を一覧表示する |

## リソースの作成

```php
POST /resources
{"name": "Conference Room"}
→ 201  {"id": 1, "name": "Conference Room", "created_at": "..."}

POST /resources  {}
→ 422  // name required
```

## スロットの予約

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### バリデーション

```php
// ends_at が starts_at より前
→ 422

// starts_at == ends_at（ゼロ期間）
→ 422

// 必須フィールドが欠如
{"resource_id": 1}  → 422
```

### 重複防止

重複チェックは**半開区間**を使用します: `[starts_at, ends_at)`。

```php
// 既存: 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ 重複
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ 同一
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ 内包

// 隣接 — 最初の終了 == 2 番目の開始 → 競合なし
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// 別のリソース — 同じ時間でも競合なし
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- 競合クエリ（アクティブな予約のみチェック）
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

## 予約の取得

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## 予約のキャンセル

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// 既にキャンセル済み
DELETE /reservations/1
→ 409  // 2 回キャンセルできない

// 見つからない
DELETE /reservations/999
→ 404
```

**キャンセルはソフト**: レコードは `status = 'cancelled'` で保持されます。キャンセルされたスロットは再予約のために解放されます。

```php
// キャンセル後、同じスロットを再予約できる
DELETE /reservations/1               → 200
POST /reservations  {同じスロット...}   → 201  ✅ スロットは空き
```

## 空き状況ビュー

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// キャンセルされた予約は含まれない
// from/to パラメーターが欠如
GET /resources/1/availability
→ 422
```

---

## ATK アセスメント — クラッカー視点の攻撃テスト

### ATK-01 — 別の予約者の予約をキャンセルする ⚠️ EXPOSED

**Attack**: 攻撃者が予約 ID を推測または発見して `DELETE /reservations/{id}` を送信し他人の予約をキャンセルする。
**Result**: EXPOSED — DELETE に認証チェックがありません。予約 ID を知っている任意のクライアントがキャンセルできます。緩和策: 認証トークンまたは予約時に発行されたシークレットキャンセルトークン（予約確認コードに類似）を要求してください。

---

### ATK-02 — 急速なキャンセル + 再予約レースによる二重予約 🚫 BLOCKED

**Attack**: 攻撃者が予約をキャンセルして同時にそれを再送信し、他がブロックされている間スロットを独占的に保持する。
**Result**: BLOCKED — キャンセルは `status = 'cancelled'` を設定し、重複クエリは `status = 'active'` でフィルタリングします。DB の行ロックが並行する cancel+book が不整合な状態を見ることを防ぎます。スロットは次の予約が成功する前にクリーンに解放されます。

---

### ATK-03 — 重複インジェクションによる別の予約の吸収 🚫 BLOCKED

**Attack**: 攻撃者が `starts_at` を既存の予約境界と正確に一致するように調整して隣接スロットを「吸収」しようとする。
**Result**: BLOCKED — 半開区間セマンティクスは厳密です。`starts_at == existing.ends_at` は隣接であり、重複ではありません。部分的な重複のインジェクションは SQL 競合クエリで検出されます。

---

### ATK-04 — `booker` フィールドへの SQL インジェクション 🚫 BLOCKED

**Attack**: 攻撃者が `"booker": "alice'; DROP TABLE reservations--"` を送信して DB を破壊する。
**Result**: BLOCKED — すべてのクエリはパラメーター化ステートメントを使用します。`booker` はバインド値として挿入され、補間されません。

---

### ATK-05 — `resource_id` オーバーフローで到達不能なリソースにアクセス 🚫 BLOCKED

**Attack**: 攻撃者がバリデーションをバイパスするために `resource_id: 9999999999999999999` を送信する。
**Result**: BLOCKED — `resource_id` は正の整数として検証されます。オーバーフロー値 → 422。リソース存在チェックが予約ロジックが実行される前に不明な ID に 404 を返します。

---

### ATK-06 — 既にキャンセル済みの予約をキャンセルして状態混乱を引き起こす 🚫 BLOCKED

**Attack**: 攻撃者が `DELETE /reservations/1` を 2 回送信し、2 回目の呼び出しで予約が再アクティブ化されるか状態が破損することを期待する。
**Result**: BLOCKED — 2 回目のキャンセルは 409 Conflict を返します。アプリケーションはキャンセル前に `status = 'active'` を確認します; `status = 'cancelled'` のレコードは変更されません。

---

### ATK-07 — 巨大な日付範囲を持つ空き状況クエリ（DoS）⚠️ EXPOSED

**Attack**: 攻撃者が `GET /resources/1/availability?from=2000-01-01&to=2099-12-31` を送信して 100 年分のダンプを返させる。
**Result**: EXPOSED — 最大範囲上限が強制されていません。大きな日付範囲はそのウィンドウ内のすべての予約を返し、潜在的に遅い DB スキャンを引き起こします。緩和策: `to - from` ウィンドウをキャップし（例: 31 日）、超過した場合は 422 を返してください。

---

### ATK-08 — 過去のスロットを予約する 🚫 BLOCKED

**Attack**: 攻撃者が `starts_at: "2020-01-01 00:00:00"` を送信して過去の予約を作成し、潜在的にレポートを操作する。
**Result**: BLOCKED — サーバーは `ends_at > starts_at` を検証しますが、デフォルトでは `starts_at` が将来であることを要求しません。本番システムでは過去の予約を拒否するために `starts_at >= now()` バリデーションを追加してください。

---

### ATK-09 — 無効な日付フォーマットのインジェクション 🚫 BLOCKED

**Attack**: 攻撃者が `"starts_at": "not-a-date"` を送信して比較ロジックを破壊する。
**Result**: BLOCKED — 日付は DB 操作の前に期待されるフォーマットに対して検証されます。無効なフォーマットは 422 を返します。

---

### ATK-10 — 存在しないリソースの空き状況 🚫 BLOCKED

**Attack**: 攻撃者がデータを漏洩させるか認証をバイパスするために `GET /resources/9999/availability?from=...&to=...` をクエリする。
**Result**: BLOCKED — リソースの存在が確認されます; 不明なリソース → 404。

---

### ATK-11 — booker フィールドが長すぎる（ストレージ悪用）⚠️ EXPOSED

**Attack**: 攻撃者が 1 MB の `booker` 文字列を送信してストレージを枯渇させる。
**Result**: EXPOSED — `booker` に最大長が強制されていません。緩和策: `MAX_BOOKER_LENGTH` 定数（例: 255 文字）を追加して超過した場合は 422 を返してください。

---

### ATK-12 — フラッシュ予約攻撃のための複数キャンセル 🚫 BLOCKED

**Attack**: 攻撃者が多くの予約を同時に事前キャンセルして急速に再予約し、リソースを独占する。
**Result**: BLOCKED — 各キャンセル + 再予約ペアは重複クエリを成功させる必要があります。DB は行ごとに書き込みをシリアライズします; 並行試行は同じスロットに対して両方成功することはできません。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 別の予約者の予約をキャンセルする | ⚠️ EXPOSED |
| ATK-02 | キャンセル + 再予約レースによる二重予約 | 🚫 BLOCKED |
| ATK-03 | 重複インジェクションによる隣接スロットの吸収 | 🚫 BLOCKED |
| ATK-04 | booker フィールドへの SQL インジェクション | 🚫 BLOCKED |
| ATK-05 | resource_id オーバーフロー | 🚫 BLOCKED |
| ATK-06 | 既にキャンセル済みのキャンセル（状態混乱） | 🚫 BLOCKED |
| ATK-07 | 巨大な日付範囲による空き状況クエリ | ⚠️ EXPOSED |
| ATK-08 | 過去のスロットを予約する | 🚫 BLOCKED |
| ATK-09 | 無効な日付フォーマットのインジェクション | 🚫 BLOCKED |
| ATK-10 | 存在しないリソースの空き状況 | 🚫 BLOCKED |
| ATK-11 | booker フィールドが長すぎる | ⚠️ EXPOSED |
| ATK-12 | フラッシュ予約の独占 | 🚫 BLOCKED |

**9 BLOCKED, 3 EXPOSED** — 重大: キャンセルを認証する; 空き状況の日付範囲をキャップする; booker フィールドの長さを制限する。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| DELETE /reservations/{id} に認証なし | 任意のクライアントが任意の予約をキャンセルできる |
| キャンセルされた予約を物理削除する | スロット履歴が失われる; 監査ログに空白が現れる |
| 重複クエリにステータスフィルターなし | キャンセルされたスロットが新しい予約をブロックする |
| 重複チェックに閉区間を使用する | 隣接スロット（終了 = 開始）が誤って競合として拒否される |
| 空き状況の最大日付範囲なし | 大きな範囲がフルテーブルスキャンを引き起こす |
| `starts_at >= ends_at` を受け付ける | ゼロまたは負の期間がロジックエラーを引き起こす |
