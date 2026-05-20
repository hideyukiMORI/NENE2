# Field Trial 23 — budgetlog: DatabaseTransactionManagerInterface・QueryStringParser 複合フィルタ・集計クエリ実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.6（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **budgetlog** — 家計簿 API
- エンティティ: `Account`（name, balance）/ `Transaction`（account_id, amount, type, category, recurring）
- DB: SQLite（テストごとに一時ファイル生成・削除）
- テスト: PHPUnit 23/23・PHPStan level 8・PHP-CS-Fixer 全通過

## Goal

v1.5.6 で未検証だった公開 API の実地検証:

- `DatabaseTransactionManagerInterface` を使った原子的な複数ステップ操作（口座間送金）
- `QueryStringParser` の複合フィルタ（`string` + `int` + `bool` の同時使用）
- SQL `GROUP BY` / `SUM()` による集計クエリ（カテゴリ別支出合計）
- `PaginationQueryParser` との組み合わせ（フィルタ + ページネーション）

---

## 実装ログ

1. `/home/xi/docker/NENE2-FT/budgetlog/` に新規 PHP プロジェクト作成
2. `composer require hideyukimori/nene2:^1.5` — v1.5.6 インストール成功
3. SQLite スキーマ（accounts / transactions）と各リポジトリを実装
4. `RouteRegistrar` に GET・POST・フィルタ・集計・送金ルートを実装
5. `TransferFundsUseCase` で `DatabaseTransactionManagerInterface` を使用
6. テスト実行 → 1 件失敗（F-1: `PdoDatabaseQueryExecutor` が `PDO` を直接受け取れない）
7. 修正後 → F-2 発見（トランザクション内でのリポジトリ再利用不可）
8. `use-transactions.md` の記述どおりにコールバック内でリポジトリを再インスタンス化
9. 23/23 通過。PHPStan level 8: 0 エラー。PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（中）: `PdoDatabaseQueryExecutor` がコンストラクタに `PDO` を直接受け取れない

**状況**: テストでは `:memory:` の `PDO` を直接インスタンス化して渡したかった:

```php
$pdo = new \PDO('sqlite::memory:');
$executor = new PdoDatabaseQueryExecutor($pdo); // TypeError
```

しかし `PdoDatabaseQueryExecutor::__construct()` の第 1 引数は `DatabaseConnectionFactoryInterface` であり、`PDO` は受け取れない。

回避策として `DatabaseConfig` + `PdoConnectionFactory` を経由してインスタンス化する必要があった:

```php
$dbConfig = new DatabaseConfig(
    url: null, environment: 'test', adapter: 'sqlite',
    host: '', port: 1, name: $this->dbFile,
    user: '', password: '', charset: '',
);
$factory  = new PdoConnectionFactory($dbConfig);
$executor = new PdoDatabaseQueryExecutor($factory);
```

さらに `:memory:` が使えない理由が付随: `PdoConnectionFactory::create()` は毎回新規接続を開くため、`:memory:` では接続ごとに空のデータベースになってしまう。テストには一時ファイルが必要。

**期待する解決策**:

- `PdoDatabaseQueryExecutor` に `PDO` を直接渡せるファクトリメソッドまたはコンビニエンスコンストラクタを追加する
- または howto に「テストで直接 PDO を使う」パターンを記載する

---

### F-2（高）: `transactional()` コールバック内で注入済みリポジトリが使えない

**状況**: `TransferFundsUseCase` で注入済みの `AccountRepositoryInterface` をトランザクション内でそのまま使おうとした:

```php
// NG: コールバック外のリポジトリはトランザクション外の接続を使う
$this->txManager->transactional(function () use ($fromId, $toId, $amount): void {
    $from = $this->accounts->findById($fromId); // 別接続
    // ...
});
```

`PdoDatabaseTransactionManager::transactional()` はコールバックに**別接続のエグゼキューター**を渡す。注入済みリポジトリは別の接続を持っており、トランザクション外でクエリを実行する。ロールバックしても注入済みリポジトリ経由の変更は巻き戻らない。

正しいパターンは `docs/howto/use-transactions.md` に記載されているが、初見では気づきにくい:

```php
// OK: コールバック引数の $tx を使ってリポジトリを再インスタンス化
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount): void {
    $accounts     = new SqliteAccountRepository($tx);     // この $tx はトランザクションの接続
    $transactions = new SqliteTransactionRepository($tx);
    // ...
});
```

**影響**: 誤ったパターンで実装するとトランザクションが効いているように見えて実は効いていない（サイレント不整合）。

**期待する解決策**:

- `use-transactions.md` に「注入済みリポジトリを再利用してはいけない理由」のより目立つ警告を追加する
- または `transactional()` のコールバックシグネチャに PHPDoc で警告を追記する

---

### F-3（低）: `DatabaseConfig` の SQLite 向けフィールド要件が不明確

**状況**: SQLite で `DatabaseConfig` を構築する際、`host`・`user`・`password`・`charset` に空文字を渡せるか不明だった。
ソースを読んで `requiredValues()` が SQLite では `DB_ENV`・`DB_ADAPTER`・`DB_NAME` しか検証しないことを確認した。

```php
$dbConfig = new DatabaseConfig(
    url: null, environment: 'test', adapter: 'sqlite',
    host: '',  // OK — SQLite ではチェックされない
    port: 1,   // OK — SQLite ではポート範囲チェックなし
    name: $this->dbFile,
    user: '',     // OK
    password: '', // OK
    charset: '',  // OK
);
```

**期待する解決策**: `DatabaseConfig` の PHPDoc または howto に SQLite では `host`/`user`/`password`/`charset` が任意であることを明記する。

---

## QueryStringParser 複合フィルタ検証

| フィルタ | パラメータ | 検証結果 |
|---|---|---|
| `string` | `?category=food` | ✓ |
| `int` (min) | `?min_amount=500` | ✓ |
| `int` (max) | `?max_amount=200` | ✓ |
| `bool` (true) | `?recurring=true` | ✓ |
| `bool` (false) | `?recurring=false` | ✓ |
| フィルタなし | (なし) | ✓ 全件返却 |
| 複合 | `?category=food&min_amount=200` | ✓ AND 結合 |

3 種類のパラメータ型を同時に使用するシナリオで `QueryStringParser` が正常に動作することを確認。

---

## DatabaseTransactionManagerInterface 検証

| 検証内容 | 結果 |
|---|---|
| 送金で両口座の残高が原子的に更新される | ✓ |
| 送金で両口座に取引レコードが作成される | ✓ |
| 残高不足で 422 を返す | ✓ |
| 同一口座への送金を 422 で拒否 | ✓ |
| 存在しない送金元で 422 を返す | ✓ |
| コールバック内で例外を投げると ValidationException が正しく伝播 | ✓ |

---

## 集計クエリ検証

`SUM(amount) GROUP BY category` のパターンを `DatabaseQueryExecutorInterface::fetchAll()` で直接実行し、カテゴリ別支出合計を返す API を実装。

- food: 500 + 100 = 600 の集計が正確
- rent: 1000 の集計が正確
- income と expense を別クエリで集計し 1 レスポンスに統合

NENE2 には集計抽象化層はなく、`fetchAll()` に生 SQL を渡すのが標準パターン。

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testListAccountsReturnsEmpty` | GET /accounts → 空リスト |
| `testCreateAccountReturns201` | POST /accounts → 201 |
| `testCreateAccountRejectsMissingName` | 422・`required` コード |
| `testCreateAccountRejectsNegativeBalance` | 422 |
| `testGetAccountReturns404ForMissing` | 404 |
| `testCreateTransactionIncome` | income → 残高増加 |
| `testCreateTransactionExpense` | expense → 残高減少 |
| `testCreateTransactionRejectsInvalidType` | transfer タイプ → 422 |
| `testCreateTransactionRejectsZeroAmount` | 金額 0 → 422 |
| `testFilterByCategoryString` | ?category=food フィルタ |
| `testFilterByMinAmountInt` | ?min_amount=500 フィルタ |
| `testFilterByMaxAmountInt` | ?max_amount=200 フィルタ |
| `testFilterByRecurringTrue` | ?recurring=true フィルタ |
| `testFilterByRecurringFalse` | ?recurring=false フィルタ |
| `testNoFilterReturnsAll` | フィルタなし → 全件 |
| `testCombinedFilters` | category + min_amount の AND 複合 |
| `testPagination` | limit=2&offset=0 → 2 件 / total=4 |
| `testSummaryAggregatesByCategory` | カテゴリ別 SUM 集計 |
| `testTransferMovesFundsBetweenAccounts` | 送金 → 両残高更新 |
| `testTransferCreatesTransactionRecords` | 送金 → 両取引レコード生成 |
| `testTransferRejectsInsufficientBalance` | 残高不足 → 422 |
| `testTransferRejectsSameAccount` | 同一口座 → 422 |
| `testTransferRejectsNonExistentSourceAccount` | 存在しない口座 → 422 |

**合計**: 23/23 通過

---

## 総評

`DatabaseTransactionManagerInterface`・`QueryStringParser` 複合・集計クエリは NENE2 v1.5.6 で正常に動作した。

主な摩擦は F-2（トランザクション内でのリポジトリ再利用不可）の初見での発見しにくさ。
`use-transactions.md` に記載はあるが、インジェクションパターンに慣れた開発者が誤ったパターンで実装してもテストが一見通ってしまうリスクがある。

次のアクション候補:
1. F-1 → howto に「テストでの PDO 直接注入」パターンを追記（または `DatabaseQueryExecutorInterface` のコンビニエンスファクトリを公開）
2. F-2 → `use-transactions.md` に警告を強化、`transactional()` PHPDoc に注釈追加
3. F-3 → `DatabaseConfig` の SQLite 向けフィールド要件をドキュメント化
