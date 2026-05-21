# Field Trial 115 — API Versioning Pattern

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/versionlog/`
**NENE2 version:** 1.5.48
**Theme:** API バージョニング — URI プレフィックス（`/v1/` / `/v2/`）・`Deprecation`/`Sunset` ヘッダー（RFC 8594）・v1→v2 の破壊的変更（フィールド名リネーム・レスポンス構造変更）・バージョン間でのストレージ共有。

---

## What was built

Note API を v1（非推奨）と v2（現行）で同時提供する実装。

- v1: `GET /v1/notes` / `POST /v1/notes` / `GET /v1/notes/{id}` — 全レスポンスに `Deprecation: true` / `Sunset` / `Link` ヘッダー付き
- v2: `GET /v2/notes` / `POST /v2/notes` / `GET /v2/notes/{id}` — 現行 API（リッチなレスポンス構造）

---

## Findings

### 1. URI プレフィックスバージョニングが最もシンプル

バージョニング戦略の主な選択肢:

| 方式 | 例 | 長所 | 短所 |
|---|---|---|---|
| URI プレフィックス | `/v1/notes` | ブラウザ・curl・ログで一目瞭然 | URL が変わる（「RESTful でない」論争） |
| Accept ヘッダー | `Accept: application/vnd.api+json;version=2` | URL 汚染なし | クライアント実装・ログが複雑 |
| クエリパラメータ | `/notes?version=2` | シンプル | キャッシュ制御が難しい |

NENE2 の明示的ルーティングと URI プレフィックスの相性は良い。Router は `/v1/` と `/v2/` を独立したルートとして登録するだけ:

```php
// V1 RouteRegistrar
$router->get('/v1/notes', $this->list(...));
$router->post('/v1/notes', $this->create(...));

// V2 RouteRegistrar — 独立したクラスで定義
$router->get('/v2/notes', $this->list(...));
$router->post('/v2/notes', $this->create(...));
```

---

### 2. Deprecation + Sunset ヘッダーで廃止を通知する（RFC 8594）

非推奨バージョンのレスポンスには必ず廃止シグナルを付ける。クライアント（SDK・APIゲートウェイ・開発者ツール）が自動検出できる:

```php
private const string SUNSET = 'Sat, 31 Dec 2026 23:59:59 GMT';

private function withDeprecationHeaders(ResponseInterface $response): ResponseInterface
{
    return $response
        ->withHeader('Deprecation', 'true')
        ->withHeader('Sunset', self::SUNSET)           // RFC 8594
        ->withHeader('Link', '</v2/notes>; rel="successor-version"'); // 移行先を示す
}
```

全 v1 エンドポイント（GET・POST）のレスポンスにこのメソッドを適用する。エラーレスポンス（422 など）には付けなくてよい（ProblemDetails を返した段階でルーティング失敗）。

---

### 3. v1→v2 の破壊的変更はレスポンス変換メソッドで表現する

同じ DB レコードを v1/v2 で異なる形状に変換する。変換ロジックは各バージョンの `toV1()` / `toV2()` に閉じ込める:

```php
// V1: "content" フィールド、tags/updated_at なし
private function toV1(Note $note): array
{
    return [
        'id'         => $note->id,
        'title'      => $note->title,
        'content'    => $note->body,  // v1 では "content"
        'created_at' => $note->createdAt,
        // tags, updated_at は v1 に存在しない
    ];
}

// V2: "body" フィールド、tags/updated_at あり、data ラッパー
private function toV2(Note $note): array
{
    return [
        'id'         => $note->id,
        'title'      => $note->title,
        'body'       => $note->body,  // v2 では "body"
        'tags'       => $note->tags,  // v2 で追加
        'created_at' => $note->createdAt,
        'updated_at' => $note->updatedAt,  // v2 で追加
    ];
}
```

---

### 4. ストレージは共有する（バージョンごとに DB を分けない）

v1 と v2 は同じ `notes` テーブルを読み書きする。v1 で作成したノートは v2 からも参照でき、逆も同様。ストレージ層（`NoteRepository`）はバージョンを知らない:

```
v1 RouteRegistrar ─┐
                   ├── NoteRepository ── notes テーブル
v2 RouteRegistrar ─┘
```

v1 が `content` で受け取った値は `note->body` に保存され、v2 は `body` として読み返す。

---

### 5. リストレスポンスの構造変更（breaking change の典型例）

v1 は `{ "notes": [...] }` だが、v2 は `{ "data": [...], "meta": {...} }` に変わる。この種の変更がバージョンアップの主要動機:

```php
// V1 response
{ "notes": [{ "id": 1, "title": "...", "content": "..." }] }

// V2 response — 構造が異なる
{ "data": [{ "id": 1, "title": "...", "body": "...", "tags": [], "updated_at": "..." }],
  "meta": { "limit": 20, "offset": 0 } }
```

---

## Test results

14 tests, 36 assertions — all pass.  
PHPStan level 8 clean. PHP-CS-Fixer clean.

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者

**「バージョンって必要？」:** 最初から v2 だけでいいのでは？という疑問が出る。「後から仕様を変えたくなったとき、既存クライアントを壊さないため」という説明が必要。モバイルアプリのアップデートを強制できない場合の具体例が刺さる。

### ペルソナ2: ロースキル経験者

**Sunset ヘッダーを知らない:** 独自の `X-Deprecated: true` を使いがち。RFC 8594 の `Sunset` が API ゲートウェイ・monitoring ツールで自動サポートされることを知ると移行意欲が高まる。

### ペルソナ3: フロントエンド寄り経験者

**URL の変更が辛い:** `/v1/notes` から `/v2/notes` への変更は API クライアントの全箇所を書き換える必要がある。`baseUrl` を定数化してバージョンを一箇所で管理するパターンが重要。

### ペルソナ4: バックエンド経験者

**Accept ヘッダーバージョニングとの比較:** GitHub API が `application/vnd.github.v3+json` を使う方式。NENE2 のシンプルなルーティングでは URI プレフィックスの方が自然。Accept ヘッダー方式はコンテントネゴシエーション（FT96 で検証）との組み合わせになる。

### ペルソナ6: 設計者・ポリシー照合

**廃止スケジュールの明文化が重要:** `Sunset` ヘッダーに入れる日付をどう決めるか。最低6ヶ月の移行期間が業界標準。`docs/` に廃止スケジュールを記録することを推奨。

---

## Issues / PRs

- Issue #732: このトライアルの起票 → `docs/howto/api-versioning.md` で解消
