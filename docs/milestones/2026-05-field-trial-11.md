# Milestone: Field Trial 11 — JWT 認証フローの AI 実装可能性検証

## 仮説

> JWT Bearer 認証フロー（ユーザー登録・ログイン・認証保護エンドポイント）は、
> NENE2 のドキュメントだけを見た Claude が迷わず実装できるか。

Field Trial 10（hoplog）が見つけた摩擦は主に「初回セットアップ・ドキュメント欠如」系だった。
FT11 ではまだ検証されていない **認証エリア** を集中的に叩く。

## テーマ: 認証フローのエルゴノミクス

NENE2 は JWT Bearer 認証を実装済みだが、クライアントプロジェクトがそれをどう組み込むかの
ガイドは薄い。新規プロジェクトで認証付き API を構築する際に何が詰まるかを記録する。

## 実装するアプリ: tasklog

**タスク管理 API** — `composer require hideyukimori/nene2:^1.4` から 0 構築。

### ドメイン

- **User**: 登録・ログイン・JWT 発行
- **Task**: ユーザー所有タスクの CRUD + ページネーション一覧

### エンドポイント（予定）

| Method | Path | 認証 |
|---|---|---|
| POST | /auth/register | 不要 |
| POST | /auth/login | 不要 |
| GET | /tasks | Bearer |
| POST | /tasks | Bearer |
| GET | /tasks/{id} | Bearer |
| PUT | /tasks/{id} | Bearer |
| DELETE | /tasks/{id} | Bearer |

### 注目する摩擦ポイント候補

- JWT 生成・検証のセットアップ手順（ライブラリ選定・鍵管理）
- Bearer ミドルウェアの組み込み方
- 認証ユーザーをハンドラーで取得する方法
- 他ユーザーのリソースへのアクセス制御（403 応答）
- ユーザー所有データのリポジトリ設計

## Phases

### Phase 62 — Field Trial 11 Execution ✅

- [x] tasklog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [x] User ドメイン（登録・ログイン・JWT 発行）を実装
- [x] Task ドメイン（認証保護 CRUD）を実装
- [x] `composer check` 全通過（PHPUnit 12/12・PHPStan level 8 0 errors・PHP-CS-Fixer 0 files）
- [x] 認証なし → 401、他ユーザーリソース → 403 の動作確認
- [x] 摩擦記録を `hideyukiMORI/tasklog: docs/field-trial-report.md` に残す
- [x] フォローアップ Issue を開く（#440–#443）

### Phase 62 フォローアップ Issues

| # | 内容 | 深刻度 | Issue |
|---|---|---|---|
| F-1 | BearerTokenMiddleware exact-path のみ（動的ルート非対応） | 高 | #440 |
| F-2 | RuntimeApplicationFactory にカスタムミドルウェアを渡せない | 中 | #441 |
| F-3 | JWT 発行 API が安定インターフェース未定義 | 中 | #442 |
| F-4 | 開発ソース（../NENE2/）と公開パッケージの API 乖離 | 低 | #443 |

## 完了条件 ✅

- [x] `composer check` 全通過
- [x] 認証フロー（登録 → ログイン → Bearer トークン付きリクエスト）が動作
- [x] 摩擦記録あり
- [x] フォローアップ Issue 作成済み

## 結果

- 実施日: 2026-05-19
- 実施者: Claude Code（自律実装）
- Issue: #434
- フィールドトライアル報告書: `hideyukiMORI/tasklog: docs/field-trial-report.md`
- PR: hideyukiMORI/tasklog#1（マージ済み）

### 総評

NENE2 の基本設計（PSR-15 パイプライン、ServiceProvider、UseCase/Handler 分離、ValidationException → 422 自動マッピング）は一貫していて、Tag/Note のサンプルコードを参照すれば Task ドメインの実装は迷わず進められた。JWT 認証フロー固有の課題は主に「BearerTokenMiddleware のパスマッチング方式」と「RuntimeApplicationFactory の柔軟性」の 2 点。どちらも深刻ではなく回避策があるが、`add-jwt-authentication.md` how-to の追加が最も効果的な改善策。
