# DX Scenario 50: マルチテナント SaaS 基盤

## アプリ概要

テナント・ユーザー・プラン・API キー・監査ログを管理するマルチテナント SaaS 基盤 API。

| 機能 | エンドポイント例 |
|------|----------------|
| テナント管理 | `POST /tenants`（name, subdomain, plan_id）|
| ユーザー管理 | `POST /tenants/{id}/users`（email, role: owner/admin/member）|
| プラン管理 | `GET /plans`、`POST /tenants/{id}/upgrade`（plan_id）|
| API キー管理 | `POST /tenants/{id}/api-keys`（name, scopes[], expires_at）|
| 使用量追跡 | `GET /tenants/{id}/usage?month=2026-05`（API 呼び出し数・ストレージ）|
| 制限チェック | `GET /tenants/{id}/limits`（プランの上限に対する現在使用量）|
| 監査ログ | `GET /tenants/{id}/audit-log`（全操作ログ）|
| テナント分離 | （全 API で `tenant_id` によるデータ分離）|

ポイント: テナント間の完全データ分離、プラン別機能制限、API キーの安全な発行・失効、使用量集計。

---

## Persona A — 加藤 健太（新卒・男性・25 歳）

### 背景

クラウドサービス会社に就職したエンジニア 1 年目。Slack / Notion 等の SaaS ヘビーユーザー。

### 作業シナリオ

1. `tenants(id, name)` と `users(id, tenant_id, email, role)` の基本設計。
2. テナント分離を「忘れる」: `GET /users` でテナントフィルターなしに全ユーザーを返す。
3. API キーを `api_keys(id, tenant_id, key_value)` で保存 — `key_value` を平文で DB に保存。
4. プラン制限を「if ($plan === 'free') { if ($count >= 5) throw Exception; }」とハードコード。
5. 使用量を「リクエストごとに `usage_logs` に INSERT」として全件 COUNT で集計。

### ハマりポイント

- **テナント分離の抜け**: `tenant_id` フィルターを WHERE に追加し忘れるリスク。
  Repository パターンで `tenant_id` を常に適用する設計が必要。
- **API キーの平文保存**: 漏洩時に全キーが危険に。`sha256(key)` で保存して比較する設計。
- **プラン制限のハードコード**: プランを追加するたびに PHP コードを変更する保守性問題。

### 解決策 & 感想

Repository コンストラクタに `TenantContext` を注入して全クエリに自動で `tenant_id` フィルターを追加。
API キーは `bin2hex(random_bytes(32))` で生成し、DB には `hash('sha256', $key)` で保存。

> 「マルチテナントって全クエリに tenant_id を追加しないといけないの大変。
>  Repository に TenantContext を注入するパターン、こういう使い方があるのか。
>  API キーの平文保存は先輩に怒られた。ハッシュして保存の重要性が分かった。
>  howto に API キー安全設計パターンを書いてほしい。」

### DX スコア: ⭐⭐（2/5）

テナント分離とセキュリティの基本的な実装パターンが必要。

---

## Persona B — 谷口 真由子（ロースキル・女性・38 歳）

### 背景

中規模 SaaS 企業の IT 担当 12 年。テナント管理・課金・サポートを担当。

### 作業シナリオ

1. テーブル設計:
   - `plans(id, name, price_yen_monthly, max_users, max_api_calls_monthly, max_storage_mb, features_json)`
   - `tenants(id, name, subdomain, plan_id, status: active/suspended/cancelled, plan_expires_at)`
   - `users(id, tenant_id, email, role, is_active, invited_at, joined_at)`
   - `api_keys(id, tenant_id, name, key_hash, scopes_json, last_used_at, expires_at, is_revoked)`
   - `usage_daily(tenant_id, date, api_calls, storage_bytes)` — 日次集計テーブル
2. API キーの発行:
   ```php
   $rawKey = 'sk_' . bin2hex(random_bytes(32));  // 'sk_' prefix + 64文字
   $keyHash = hash('sha256', $rawKey);
   // $rawKey はこの1回だけ返す（DB には $keyHash のみ保存）
   ```
3. テナント分離: `TenantRepository` で `tenant_id = :tenant_id` を全クエリに適用。
4. プラン制限チェック: `plans` テーブルから `max_api_calls_monthly` を取得して比較。
5. 使用量集計: `usage_daily` を `SUM` で月次集計（全ログを COUNT しない）。

### ハマりポイント

- **API キーの `scopes_json`**: スコープ（`read:users`, `write:projects`）の設計と検証ロジック。
  どの API エンドポイントがどのスコープを要求するかのマッピング。
- **テナント分離の実装漏れ**: 新しい Repository を追加するたびに `tenant_id` フィルターを忘れるリスク。
  抽象基底クラスか Trait でフィルターを強制する設計。
- **プランアップグレードの即時反映**: 月次ではなく即時に制限が変わる場合のキャッシュ無効化。

### 解決策 & 感想

`TenantScopedRepository` 抽象クラスを作り、全子クラスが `addTenantFilter()` を必ず呼ぶ設計に。

> 「テナント分離って仕組みを作らないと絶対抜けが出る。
>  基底クラスで強制する設計、最初から考えるべきだった。
>  API キーのスコープ設計は OAuth2 のスコープと同じ考え方で、
>  howto に書けばバックエンドエンジニアに有用なパターンになる。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。テナント分離の強制パターンと API キースコープ設計が howto 候補。

---

## Persona C — 浜田 浩二（ベテラン・男性・52 歳）

### 背景

クラウドネイティブ SaaS のアーキテクト 20 年。AWS マルチテナント設計・SOC 2 準拠の経験あり。

### 作業シナリオ

1. テーブル設計（エンタープライズ対応）:
   - `tenant_feature_flags(tenant_id, feature, enabled_at, disabled_at)` — テナント別機能フラグ
   - `api_keys` に `ip_allowlist_json` と `rate_limit_rpm` を追加（テナント内カスタム設定）
   - `audit_events(tenant_id, actor_type: user/api_key/system, actor_id, action, resource_type, resource_id, metadata_json, created_at)` — 監査ログ
   - `tenant_quotas(tenant_id, resource: api_calls/users/storage, used, limit, reset_at)` — リアルタイム使用量
2. テナント分離: `TenantContext` をミドルウェアで設定し、Repository の基底クラスで適用。
   テスト時は `MockTenantContext` に差し替え可能。
3. API キー認証: `X-API-Key` ヘッダーから `hash('sha256', $header)` して `api_keys.key_hash` と比較。
   認証成功後に `last_used_at` を更新 + レート制限チェック。
4. 使用量クォータ: `tenant_quotas` に `UPDATE ... SET used = used + 1 WHERE used < limit` で atomic チェック+インクリメント。
   返り値が 0 行ならクォータ超過。
5. 監査ログ: 全 `write` 操作後に `audit_events` INSERT（アスペクト的に UseCase 基底クラスで実装）。

### ハマりポイント

- **Atomic クォータチェック**: `UPDATE ... SET used = used + 1 WHERE used < limit` の affected rows チェックが SQLite でも動作するか確認（SQLite は SELECT FOR UPDATE がないため）。
- **API キー認証ミドルウェアのパフォーマンス**: 毎リクエスト `hash + SELECT` がボトルネックにならないか。
  `api_keys` に `key_hash` のインデックスが必須。
- **監査ログの非同期化**: 高トラフィック時に監査ログ INSERT がボトルネックになる場合のキューイング設計（今回は同期）。

### 解決策 & 感想

Atomic クォータチェックは SQLite でも `rowsAffected() > 0` で確認可能。
監査ログは今回は同期 INSERT のシンプル設計で、非同期化は次フェーズに。

> 「SQLite の atomic クォータチェックが SELECT FOR UPDATE なしにできるのは、
>  UPDATE の WHERE 条件チェックが atomic になる SQLite の特性を活かしたパターン。
>  これは flash-sale.md と同じ atomic UPDATE パターン。
>  NENE2 のフレームワークとしてマルチテナントサポートを提供できれば DX が大きく上がる。
>  jwt-tenant-isolation.md と合わせて 'マルチテナント API 設計' howto を書いてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。マルチテナント howto の需要が高い。Atomic クォータチェックパターンが重要。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 加藤（新卒） | △ テナント分離・セキュリティ基本が必要 | 2/5 | tenant_id 漏れ、API キー平文保存 |
| 谷口（ロースキル） | ○ 実用的完成 | 3/5 | テナント分離強制パターン、APIキースコープ |
| 浜田（ベテラン） | ◎ 高品質完成 | 4/5 | Atomic クォータチェック、監査ログ非同期化 |

**共通のフリクション**:
1. **テナント分離の実装パターン** — Repository 基底クラスで `tenant_id` を強制するアーキテクチャ。
2. **API キーの安全な設計** — 発行時のみ平文を返し、DB には SHA-256 ハッシュを保存するパターン。
3. **Atomic クォータチェック** — `UPDATE ... WHERE used < limit` + `rowsAffected()` パターン（SQLite で SELECT FOR UPDATE の代替）。
