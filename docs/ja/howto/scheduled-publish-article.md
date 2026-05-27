# ハウツー: 記事の予約公開

> **FT リファレンス**: FT330 (`NENE2-FT/pubschedulelog`) — 記事の下書き/スケジュール/公開/アーカイブのライフサイクル、オーナーのみの下書きアクセス、公開記事のパブリックアクセス、スケジュール公開トリガー、34 テスト / 95 アサーション PASS。

このガイドでは、延期公開機能を持つ記事管理システムの構築方法を解説します。著者が下書きを書き、将来の時刻にスケジュールを設定し、バックグラウンドジョブ（または API 呼び出し）が公開状態に遷移させます。

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601、スケジュール設定時のみ、それ以外は NULL
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## ステータス遷移

```
draft ──publish──► published ──archive──► archived
  │
  └──schedule──► scheduled ──(時間経過)──► published
  │                  │
  │               unschedule
  │                  │
  └──────────────────┘
```

許可された遷移のみ — 無効な遷移は 409 を返します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST`  | `/articles` | 下書きを作成する（`X-User-Id` 必須） |
| `GET`   | `/articles/{id}` | 取得する（下書き: オーナーのみ; 公開済み: パブリック） |
| `PUT`   | `/articles/{id}` | 下書きを更新する（`X-User-Id` 必須） |
| `POST`  | `/articles/{id}/publish` | 即時公開する |
| `POST`  | `/articles/{id}/schedule` | 将来の時刻にスケジュールする |
| `POST`  | `/articles/{id}/unschedule` | 下書きに戻す |
| `POST`  | `/articles/{id}/archive` | 公開済み記事をアーカイブする |
| `GET`   | `/articles` | 一覧表示する（`?status=` フィルター付き） |
| `POST`  | `/publish-due` | `publish_at` を過ぎたスケジュール済み記事をトリガーする |

## 下書きの作成

```php
POST /articles  X-User-Id: 1
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// 認証なし → 401
```

## 可視性ルール

```php
// 下書き: オーナーのみ
GET /articles/1  X-User-Id: 1  → 200   // 著者は自分の下書きを見られる
GET /articles/1  X-User-Id: 2  → 404   // 他のユーザーは下書きを見られない
GET /articles/1               → 404   // 認証なし、下書きは非表示

// 公開済み: 誰でも
GET /articles/1               → 200   // パブリック
```

## 公開とアーカイブ

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// 下書きをアーカイブできない
POST /articles/1/archive  X-User-Id: 1  → 409
```

## スケジュール設定

```php
// 1 時間後にスケジュール
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// 過去の時刻 → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// スケジュール解除 → 下書きに戻す
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## スケジュール済み記事のトリガー

cron ジョブまたは管理者エンドポイントが `publish_at <= now` のすべてのスケジュール済み記事を遷移させます:

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## 記事の一覧表示

```php
GET /articles?status=published      → 200  // パブリック、認証不要
GET /articles?status=draft  X-User-Id: 1  → 200  // 自分の下書きのみ
```

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 未認証ユーザーに下書きを表示する | 未公開コンテンツが漏洩する |
| 過去の時刻へのスケジュール設定を許可する | トリガージョブ経由で記事が「即時」公開され、レビューをバイパスする |
| テストのスケジュールトリガーに wall-clock の now() を使用する | テストが時間依存になる; テストでは過去の `publish_at` を持つ行を強制挿入する |
| アーカイブ時にハードデリートする | 監査証跡が失われる; status フィールドを使う |
| archived → published への遷移を許可する | 削除されたコンテンツが復活する; 明示的な再公開を要求する |
