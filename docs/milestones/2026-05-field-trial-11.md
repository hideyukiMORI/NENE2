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

### Phase 62 — Field Trial 11 Execution

- [ ] tasklog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [ ] User ドメイン（登録・ログイン・JWT 発行）を実装
- [ ] Task ドメイン（認証保護 CRUD）を実装
- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] 認証なし → 401、他ユーザーリソース → 403 の動作確認
- [ ] 摩擦記録を `docs/field-trials/2026-05-field-trial-11.md` に残す
- [ ] フォローアップ Issue を開く

## 完了条件

- `composer check` 全通過
- 認証フロー（登録 → ログイン → Bearer トークン付きリクエスト）が動作
- 摩擦記録あり
- フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #434
- フィールドトライアル報告書: `docs/field-trials/2026-05-field-trial-11.md`（実施後作成）
