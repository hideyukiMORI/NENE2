# Field Trial 145 — ユーザー設定管理（User Preferences）

**Date**: 2026-05-21  
**App**: `preflog`  
**Path**: `/home/xi/docker/NENE2-FT/preflog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.79

---

## What was built

ユーザー設定（Preferences）管理システムを実装した。
事前定義された設定キー（enum）に対して型バリデーション付きで設定値を保存・更新・リセットできる。

| Endpoint | 説明 |
|---|---|
| `GET /users/{id}/preferences` | 設定一覧取得（全キー、デフォルト含む） |
| `PUT /users/{id}/preferences/{key}` | 設定値更新（upsert） |
| `DELETE /users/{id}/preferences/{key}` | 設定リセット（デフォルトに戻す） |

---

## Architecture decisions

### 設定キーは enum で管理

`PreferenceKey` enum がキーの網羅性・デフォルト値・バリデーション規則を一元管理する。
未知のキーは 422 で弾き、有効なキー一覧をレスポンスに含める（自己説明 API）。

### 値は TEXT で保存、型はクライアント解釈

DB には全値を TEXT として保存。`items_per_page: "20"` をフロントで `parseInt()` するパターン。
型安全性をサーバー側のバリデーション（enum の `validate()` メソッド）で担保する。

### GET は全キーを返す（デフォルト含む）

保存済みの設定と未保存のデフォルト値を統合して全キーを返す。
クライアントは存在チェックなしに全設定値を使用できる。

```php
foreach (PreferenceKey::cases() as $key) {
    $storedRow = $stored[$key->value] ?? null;
    $preferences[] = [
        'key' => $key->value,
        'value' => $storedRow !== null ? (string) $storedRow['pref_value'] : $key->defaultValue(),
        'is_default' => $storedRow === null,
        'updated_at' => $storedRow !== null ? (string) $storedRow['updated_at'] : null,
    ];
}
```

### DELETE は物理削除（= リセット）

DELETE で DB から行を削除し、次の GET でデフォルト値が返る。
未設定状態で DELETE しても 200 を返す（冪等）。

### IDOR 防止（所有権チェック）

`X-User-Id` ヘッダーと URL の `{id}` を比較。他ユーザーの設定変更は 403。
GET は全ユーザー公開（設定は通常 public 情報）。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `PrefTest.php` (SQLite) | 20 | Pass |
| **Total** | **20** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「ユーザー設定は普段から使っているもの（ダークモード切り替えなど）なので概念が身近だった。
enum でキーを管理するのが最初は大げさに思えたが、`valid_keys` をエラーレスポンスに含めることで
API の自己説明性が上がるというメリットがわかった。`is_default: true/false` という設計が、
フロントで『この設定はユーザーがカスタマイズしたか』を判定するのに便利だとすぐ理解できた。
`DELETE でリセット（物理削除）` という設計は直感に反した（DELETE したら消えてほしい）が、
GET でデフォルトが返るという動作を確認して納得した。」

★★★★☆ — 日常的な機能で学習効果が高い

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel の User Settings パッケージ（spatie/laravel-settings 等）と同等の機能を自分で組んだ感じ。
`user_preferences` テーブルの `UNIQUE(user_id, pref_key)` + upsert パターンは汎用的で
他のプロジェクトでもすぐ使える。Laravel では Eloquent の `updateOrCreate()` が対応するが、
NENE2 では明示的に find → update/insert で書くのでロジックが見えやすい。
`PreferenceKey::tryFrom($keyStr)` で未知のキーを安全に弾くパターンは PHP 8.1+ の enum の
典型的な使い方で、よく設計されている。」

★★★★★ — パターンが汎用的で即戦力になる

### Persona 3 — セキュリティエンジニア

「IDOR チェック（`$actorId !== $userId` → 403）が PUT・DELETE 両方に適用されている。
GET は読み取りのみなので全ユーザー公開は問題なし（設定が機密情報でないことが前提）。
設定値のバリデーションが enum の `validate()` メソッドに集約されているので、
バリデーション漏れリスクが低い。SQL インジェクションはプリペアドステートメントで防止済み。
懸念点: timezone の値検証が `strlen <= 64` のみで実際の IANA タイムゾーン名を検証していない。
本番では `timezone_identifiers_list()` でホワイトリスト検証を推奨。」

★★★★☆ — 基本的なセキュリティは確保、timezone 検証が改善余地あり

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /preferences` が全キーをデフォルト含めて返すのが最高に使いやすい。
フロントで『設定が存在するか』チェックをしなくてよい。`is_default` フラグで
『このユーザーがカスタマイズした設定』を区別してハイライト表示できる。
`updated_at` がキーごとに取得できるので、最後に変更された設定の日時を表示する UX も実装できる。
`items_per_page: "20"` が文字列なのは少し違和感あるが、一貫性（全部 TEXT）の方が
フロント側のハンドリングが楽という判断は理解できる。」

★★★★☆ — レスポンス設計がフロントにとって使いやすい

### Persona 5 — インフラ・DevOps エンジニア

「`UNIQUE(user_id, pref_key)` がインデックスを兼ねるため、設定取得クエリが効率的。
ユーザー数が増えても `WHERE user_id = ?` でインデックス検索できる。
全設定取得が 1 クエリ（`SELECT ... WHERE user_id = ?`）で済む設計はシンプル。
将来の拡張として、設定変更の監査ログが必要になった場合は
別テーブルへの INSERT を追加するだけで対応できる（現在の設計を壊さない）。
SQLite → MySQL 移行もスキーマが標準的なので問題なし。」

★★★★★ — インフラ的にシンプルで効率的な設計

### Persona 6 — プロダクトマネージャー

「ユーザー設定は UX の基本機能で、この実装で主要なニーズを網羅できている。
ダークモード（theme）・言語（language）・通知（notifications_enabled）は
どのアプリでも必要な設定で、FT で作ったパターンを再利用できる。
`is_default: true` のユーザーは設定を変えたことがない人 = デフォルト設定で満足している人として
分析に使えると気づいた。削除（リセット）機能があることで
『元に戻す』ボタンの UX が実装しやすい。今後の拡張として、
設定のエクスポート/インポート（他デバイスに設定を引き継ぐ）が考えられる。」

★★★★★ — プロダクト機能として完成度が高い

---

## Howto

`docs/howto/user-preferences-management.md`
