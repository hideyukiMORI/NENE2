# Milestone: Field Trial 13 — MySQL + Phinx マイグレーション（Event Management API）

## 仮説

> MySQL + Phinx マイグレーションを使った Consumer Project を、
> NENE2 のドキュメントだけを参照した Claude が迷わず構築できるか。

FT12 シリーズが見つけた摩擦は主に「認証エリア」と「MCP ツール設計エリア」だった。
FT13 では **DB インフラエリア**（MySQL・Phinx・本番設定）を集中的に叩く。

## テーマ: Database Ergonomics

NENE2 の `DatabaseQueryExecutorInterface`・`DatabaseConfig`・Phinx 統合を、
SQLite なしの本番近似環境（MySQL + Docker）で初めて使う際の摩擦を記録する。

## 実装するアプリ: eventlog

**イベント管理 API** — `composer require hideyukimori/nene2:^1.4` から 0 構築。
MySQL + Phinx マイグレーションを必須とする。

### ドメイン

- **Event**: タイトル・説明・開催日時・定員（API キー保護 CRUD）
- **User**: 登録・ログイン（JWT Bearer）
- **Registration**: User ↔ Event の M:N（参加登録、Bearer 必須）

### エンドポイント

| Method | Path | 認証 |
|---|---|---|
| GET | /events | なし |
| GET | /events/{id} | なし |
| POST | /events | API キー |
| PUT | /events/{id} | API キー |
| DELETE | /events/{id} | API キー |
| POST | /auth/register | なし |
| POST | /auth/login | なし |
| GET | /me/registrations | Bearer |
| POST | /me/registrations/{eventId} | Bearer |
| DELETE | /me/registrations/{eventId} | Bearer |

### 注目する摩擦ポイント候補

- `phinx.php` の Consumer Project での設定方法（ConfigLoader の使い方）
- Phinx マイグレーションファイルの書き方と `composer migrations:migrate` の動作
- MySQL Docker サービスの起動・接続確認（healthcheck・接続タイミング）
- `DB_ADAPTER=mysql` + 環境変数の設定方法
- `DatabaseQueryExecutorInterface` と MySQL 固有 SQL の相性
- テスト用 DB の扱い（MySQL vs SQLite 切り替え）
- `APP_ENV=production` でのエラー表示制御

## Phases

### Phase 68 — Field Trial 13 Execution

- [ ] eventlog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [ ] MySQL Docker サービスを起動・接続確認
- [ ] Phinx マイグレーションファイルを作成・実行
- [ ] Event / Registration / Auth ドメインを実装
- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] MySQL で全エンドポイント動作確認
- [ ] 摩擦記録を `docs/field-trial-report.md` に残す
- [ ] フォローアップ Issue を開く

## 完了条件

- `composer check` 全通過
- MySQL で全エンドポイント動作確認
- Phinx マイグレーション（migrate/status/rollback）が動作
- 摩擦記録あり
- フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #472
- プロジェクト: `/home/xi/docker/eventlog/`
- 前: FT12-C (#455) — Multi-Auth (shoplog)
