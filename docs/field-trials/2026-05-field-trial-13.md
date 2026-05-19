# Field Trial 13 — eventlog: MySQL + Phinx マイグレーション実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.1（`hideyukimori/nene2: ^1.4`）
- PHP 8.4
- プロジェクト: **eventlog** — イベント管理 JSON API
- エンティティ: `User` / `Event` / `Registration`（3テーブル）
- テスト: PHPUnit（14テスト）・PHPStan level 8・PHP-CS-Fixer
- DB: MySQL（本番） / SQLite インメモリ（テスト）
- マイグレーション: Phinx

## Goal

SQLite ではなく MySQL をバックエンドとした場合の Phinx マイグレーションセットアップの摩擦点を記録する。

---

## Findings

### F-1: `compose.yaml` の `working_on` が無効なキー [低]

`working_on: /var/www/html` という誤ったキーが含まれており、Docker Compose がバリデーションエラーを出した。正しいキーは `working_dir`。

**解決**: `working_on` → `working_dir` に修正。

**提案**: CLAUDE.md のスキャフォールドテンプレートを修正する。

---

### F-2: `ConfigLoader` が `DB_PASS` ではなく `DB_PASSWORD` を読む [中]

`compose.yaml` に `DB_PASS: eventlog_secret` を設定したが、`ConfigLoader` は `DB_PASSWORD` を読む。`DB_PASS` は無視され、パスワードが空文字列になり認証失敗した。

**解決**: `compose.yaml` に `DB_PASSWORD: eventlog_secret` を追加。

**提案**: NENE2 の CLAUDE.md・compose.yaml テンプレート・README で `DB_PASSWORD`（`DB_PASS` ではなく）を明示する。

---

### F-3: Phinx の MySQL 外部キー — 符号なし整数の型不一致 [高]

Phinx が MySQL の `id` カラムを `INT UNSIGNED AUTO_INCREMENT` として作成するのに対し、外部キー側の `integer` 型は `INT`（符号付き）。MySQL はこの組み合わせを拒否する。

```
"Referencing column 'user_id' and referenced column 'id' in foreign key constraint are incompatible"
```

SQLite では型チェックが緩いため同じコードが動いてしまい、MySQL 移行時に初めて気づくパターン。

**解決**: 外部キーカラムに `'signed' => false` を追加した:
```php
->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
->addColumn('event_id', 'integer', ['null' => false, 'signed' => false])
```

**提案**: NENE2 ドキュメント（`add-database-endpoint.md` または新規 howto）に MySQL 外部キーの注意点として `'signed' => false` が必要である旨を明記する。

---

### F-4: Phinx rollback が部分適用マイグレーション後にテーブルを残す [中]

F-3 のエラー発生後、rollback して migration を修正・再実行しようとした。`registrations` テーブルの作成が失敗した後、Phinx の `change()` メソッドの自動ロールバックは `CREATE TABLE` まで戻せず、テーブルが作成済みだが phinxlog に記録されていない状態になった。

次の `migrations:migrate` で "Table 'registrations' already exists" エラーが出た。

**解決**: MySQL に直接接続して `DROP TABLE IF EXISTS registrations;` を手動実行してから再実行。

**提案**: NENE2 の howto に「失敗した migration の手動ロールバック手順」を追記する。または `up()` / `down()` を明示的に分けて書く方針を推奨する。

---

### F-5: PHPUnit テストは MySQL なしでも動く（SQLite インメモリ戦略が有効）[情報]

テストでは SQLite インメモリ DSN を使用し、専用の SQLite スキーマファイル `database/schema.sqlite.sql` を用意した。`PdoDatabaseQueryExecutor` に PDO を匿名クラスで渡すことで、MySQL/SQLite の切り替えが実現した。

**所感**: `DatabaseQueryExecutorInterface` の抽象化が test-friendliness を高めている。ただし MySQL と SQLite の型システムの差（符号付き整数）は SQLite テストでは見えないため、MySQL 統合テストも CI に含めることが望ましい。

---

## Test Results

```
PHPUnit:              14/14 tests
PHPStan level 8:      No errors
PHP-CS-Fixer:         0 files to fix
MySQL migration:      3 tables (users / events / registrations)
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `compose.yaml` の `working_on` キー誤り | 低 | テンプレート誤り |
| F-2 | `DB_PASS` ではなく `DB_PASSWORD` を読む | 中 | ドキュメント欠如 |
| F-3 | MySQL 外部キーで `'signed' => false` が必要 | 高 | ドキュメント欠如 |
| F-4 | 部分適用マイグレーションの手動ロールバックが必要 | 中 | ドキュメント欠如 |
| F-5 | SQLite インメモリ戦略は有効（情報） | — | 情報 |

---

## Overall Impression

Phinx のセットアップ自体は簡単（`phinx.php` を 1 ファイル追加するだけ）。ただし MySQL 特有の制約（外部キーの型一致）が SQLite 開発時には見えず、初回 MySQL 移行時に気づくパターンが発生した。`DB_PASSWORD` などの環境変数名の揺れは、スキャフォールドを充実させれば防げる類の摩擦。全体として MySQL + Phinx の ergonomics は検証目的を達成できた。
