# ハウツー: システムアナウンス管理の構築

> **パターン実証: FT190 announcelog** — 管理者キー認証、ユーザーごとの非表示、優先度順序付けを持つ時間ベースのシステムアナウンス。38 テスト / 93 アサーション PASS。

---

## このガイドで扱うこと

メンテナンス通知、機能更新、アラートをブロードキャストするシステムアナウンス API:

1. **作成/更新/削除** — 定数時間キー比較による管理者専用操作
2. **アクティブな一覧表示** — `starts_at` / `ends_at` による UTC 時間フィルタリング
3. **非表示** — 冪等な UPSERT として永続化されるユーザーごとのオプトアウト

---

## スキーマ

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- 大きいほど先に表示
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` は冪等な非表示を可能にします。`starts_at` / `ends_at` は ISO 8601 文字列です — UTC 日時では辞書的比較が正しく機能します。

---

## API

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST`   | `/announcements`             | `X-Admin-Key` | アナウンスを作成する（201）                  |
| `PUT`    | `/announcements/{id}`        | `X-Admin-Key` | アナウンスを更新する（200）                  |
| `DELETE` | `/announcements/{id}`        | `X-Admin-Key` | アナウンスを削除する（200）                  |
| `GET`    | `/announcements`             | オプション `X-User-Id` | 現在アクティブなアナウンスを一覧表示する |
| `POST`   | `/announcements/{id}/dismiss`| `X-User-Id`   | このユーザーに対して非表示にする（200）       |

---

## コアパターン: 定数時間管理者キー検証

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // 空の adminKey 設定は管理者アクセスなしを意味する — フェイルクローズド
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals: 定数時間 — キー比較のタイミング攻撃を防ぐ
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**`===` を使わない理由:** 文字列比較は最初の不一致でショートサーキットします。攻撃者はタイミングの差を測定して部分的なプレフィックスマッチを見つけ、文字ごとにブルートフォースできます。`hash_equals()` は不一致がどこにあっても一定時間かかります。

**フェイルクローズド:** 空の `adminKey` 設定は常に `false` を返します — 誤って「オープン管理者」モードになることはありません。

---

## コアパターン: UTC 時間ベースのフィルタリング

```php
// 現在アクティブなアナウンスを一覧表示
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

UTC の ISO 8601 文字列は辞書的に正しくソートされます — `'2025-06-01T...' > '2025-05-01T...'`。データベースでは常に UTC を使用してください。

`ends_at > :now`（厳密な大なり）は、アナウンスが `ends_at` の 1 秒後ではなく、正確に `ends_at` で期限切れになることを意味します。

---

## コアパターン: ユーザーごとの非表示（冪等）

```php
// UNIQUE(user_id, announcement_id) は安全な繰り返し非表示呼び出しを可能にする
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

`POST /announcements/5/dismiss` を 2 回呼び出すユーザーは安全です — 2 回目の呼び出しはサイレントに成功します。クライアントは最初に確認する必要はありません。

---

## コアパターン: 一覧表示でのオプショナルユーザーコンテキスト

```php
// X-User-Id なし: すべてのアクティブなアナウンスを表示
// X-User-Id あり: そのユーザーが非表示にしたものを除外

// ユーザーなし:
WHERE a.starts_at <= :now AND a.ends_at > :now

// ユーザーあり（LEFT JOIN + IS NULL フィルター）:
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

この単一の `GET /announcements` エンドポイントは、未認証（モニタリング、管理者ビュー）と認証済み（関連バナーを表示する UI）の両方のユースケースを処理します。

---

## コアパターン: ends_at は starts_at の後でなければならない

```php
// サーバーサイドバリデーション — クライアント信頼だけではない
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at must be after starts_at.';
}
```

`ends_at <= starts_at` のアナウンスは作成直後に非表示になります — 壊れたデータをサイレントに受け入れるのではなく、バリデーションして拒否してください。

---

## レスポンス設計

| シナリオ | ステータス | ボディ |
|---|---|---|
| 作成成功 | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| 更新成功 | 200 | `{announcement: {...}}` |
| 削除成功 | 200 | `{deleted: true}` |
| アクティブ一覧 | 200 | `{data: [...], total: N}` |
| 非表示 | 200 | `{dismissed: true}` |
| 管理者キーなし/不正 | 401 | `{error: "Admin key required."}` |
| 見つからない | 404 | `{error: "Announcement not found."}` |
| バリデーション失敗 | 422 | `{error: "..."}` |

`created_at` / `updated_at` はパブリックレスポンスに含まれません — 内部メタデータです。

---

## テスト結果（FT190）

```
38 テスト / 93 アサーション — すべて PASS
PHPStan level 8 — エラーなし
PHP CS Fixer — クリーン
```

ソース: [`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
