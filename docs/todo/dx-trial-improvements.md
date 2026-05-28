# DX Trial 改善バックログ

Trial 01〜26（ショッピングカート〜予定管理）の78試験で見つかった実改善ポイント。
実装は後回し — 先にシナリオ試験を続ける。

## 優先度：高

### IMP-01 `Router::param()` を vendor に収録する
- 発見: Persona C（シニア）が `src/` に存在する `Router::param()` を vendor（`^1.5`）で使おうとし実行時500エラーに遭遇
- 対応: 次リリースで公開 API に昇格 or howto に「`src/` と `vendor/` の乖離に注意」明記

### IMP-02 `transactional()` コールバック内の読み取り警告を howto に追記
- 発見: Persona C が `createWithExecutor` 内で `$this->findById()` を呼んだら null が返るバグを自力発見
- 理由: コールバック外の `$this->db` と引数の `$db`（トランザクション用）は別接続
- 対応: `add-database-endpoint.md` の transactional セクションに「コールバック内では必ず引数の `$db` を使う」警告を追記

## 優先度：中

### IMP-03 SQLite `FOR UPDATE` 非サポートを howto に注記
- 発見: Persona C が `SELECT ... FOR UPDATE` を書いてクエリエラー
- 対応: `flash-sale-api.md` または `add-database-endpoint.md` に「SQLite は FOR UPDATE 非対応。アトミック UPDATE で代替」を記載

### IMP-04 `DatabaseConfig::sqlite($path)` ショートハンドを追加
- 発見: Persona C がテストごとに 8 引数のコンストラクタを書く必要があることに不満
- 対応: `DatabaseConfig` に `public static function sqlite(string $path): self` ファクトリメソッドを追加

## 優先度：低

### IMP-06 `JsonResponseFactory::createEmpty(int $status)` を howto に明記
- 発見: Persona 04-B が `$json->create(null, 204)` を試みて失敗、vendor ソースで `createEmpty()` を発見
- 対応: `add-database-endpoint.md` または新規 howto に 204/201 レスポンスの正しいメソッド一覧を追記

### IMP-07 `DatabaseConstraintException` を vendor に収録する ✅ 完了済み
- 発見: 新卒・ロースキル・シニア全ペルソナが複数シナリオで遭遇（Blog/FlashSale で UNIQUE 違反検知に使いたかった）
- 詳細: Trial 05-C シニアが「v1.5.23 以降のため未収録」と明記。DX trial 実施時点では未リリースだった
- **状態**: PR #669/#670 で `src/Database/DatabaseConstraintException.php` に追加・ADR 0009 stable public API に既載・v1.5.114 以降の Packagist タグに含まれている（現在 v1.5.323）
- 対応 (#1305): TODO を完了マークに更新するのみ。実コード変更不要

### IMP-05 transactional integrity のテスト例を howto に追記
- 発見: Persona A が非トランザクションで実装したが全テストが PASS — テストが整合性を検証していない
- 対応: howto に「在庫減算と注文作成の途中失敗をシミュレートするテスト例」を追加

### IMP-09 Namespace 二重指定の罠を howto に明記 ★ 最優先
- 発見: Trial 10-16 で **7回**発生（10-A, 11-B, 12-A, 14-B, 15-A, 16-A, 16-B）。新卒・ロースキル両方で繰り返し
- 検証: Trial 17-21 ではプロンプトヒントで0件だったが、Trial 22-A（新卒）でプロンプトヒントがあっても再発
- 結論: プロンプトヒントで軽減はされるが根絶はできない → **howto への恒久追記が唯一の対策**
- 原因: PSR-4 `"Ticket\\": "src/Ticket/"` のとき、`src/Ticket/` 内ファイルの namespace は `Ticket\` のみで正しい
- 誤り例: `namespace Ticket\Ticket;` / 正解: `namespace Ticket;`
- 対応: `add-database-endpoint.md` の冒頭に「autoload 設定と namespace の対応関係」を図解で追記（緊急）

### IMP-10 `transactional()` + PHPStan `property.onlyWritten` を howto に解説
- 発見: Trial 08-C（Points シニア）で、コールバック内で新規インスタンスを作ったため DI 注入プロパティが未使用として検出
- 解決パターン: コールバック内で `new Repository($txExecutor)` を使う（DI 注入プロパティを使わない）
- 対応: `use-transactions.md` に「コールバック内では新規インスタンス化パターンを使う」コード例を追記

### IMP-11 `JsonRequestBodyParser` テストで空オブジェクトには `new \stdClass()` を使う
- 発見: Trial 07-C, 10-A で `json_encode([])` → `"[]"` → 400 エラー（JSON 配列は不可）
- 正解: `json_encode(new \stdClass())` → `"{}"` → 正常パース
- 対応: howto のテストサンプルに「空ボディは `new \stdClass()`」注記を追記

### IMP-12 SQLite HAVING + プレースホルダー型問題を howto に追記
- 発見: Trial 15 の全3ペルソナで `HAVING aggregated_value <= ?` が文字列比較になりフィルタ失敗
- 解決: `CAST(? AS INTEGER)` パターン（`add-database-endpoint.md` に既記載だが目立たない）
- 対応: `add-database-endpoint.md` の HAVING 節に「`CAST(? AS INTEGER)` を必ず使う」警告ボックスを追記

### IMP-13 `withParsedBody()` がテストで `JsonRequestBodyParser` をバイパスする問題
- 発見: Trial 16-C（Quiz シニア）が発見
- 原因: `withParsedBody()` は Content-Type ヘッダーを設定しないため JsonRequestBodyParser が処理しない
- 正解: `withBody(stream) + withHeader('Content-Type', 'application/json')`
- 対応: howto のテスト記法サンプルに「`withParsedBody()` ではなく `withBody()` + JSON stream」を明記

### IMP-14 `PdoConnectionFactory` に生 PDO を渡せない — テスト用ヘルパー追加
- 発見: Trial 13-C（Budget シニア）、Trial 15-A（Inventory 新卒）
- 問題: `PdoDatabaseQueryExecutor` 第2引数への PDO 直注入を使う必要があるが `@internal` 扱い
- 対応: `DatabaseConfig::sqlite($path)` ファクトリ（IMP-04）と合わせてテスト用ヘルパーを公式化

### IMP-15 `createList()` / `createEmpty()` / `create()` の使い分けを一覧化
- 発見: Trial 16-A, 16-C で `createList()` 不使用（試行錯誤で発見）; Trial 13-A で `createEmpty()` 不使用
- 問題: 3メソッドの使い分けが howto に散在
- 対応: `add-database-endpoint.md` にレスポンスメソッド比較表を追加:
  - `create($data, $status)` — スカラー/オブジェクト
  - `createList($items)` — トップレベル配列
  - `createEmpty($status)` — 204 No Content など

### IMP-16 PHP 数値文字列キーと PHPStan `array<int|string,*>` を howto に注記
- 発見: Trial 20-A（Review 新卒）で `rating_distribution` の `['1' => 0, ...]` が PHPStan エラー
- 原因: PHP は数値文字列キー `'1'` を内部で `int(1)` に変換 → `array<string,int>` 宣言と不一致
- 解決: `@return array<int|string, int>` で宣言
- 対応: `add-database-endpoint.md` に「数値文字列キーを持つ配列は `array<int|string,*>` で宣言」を追記

### IMP-17 `Psr\Http\Server\RequestHandlerInterface` の正しい namespace を howto に明記
- 発見: Trial 19-B（Message ロースキル）で `Psr\Http\Message\RequestHandlerInterface` と誤混入
- 原因: PSR-7 (`Psr\Http\Message`) と PSR-15 (`Psr\Http\Server`) でインターフェース名が似すぎる
- PHPStan が型エラーで即検出するが、混乱コストがある
- 対応: howto のサンプルコードで `use Psr\Http\Server\RequestHandlerInterface;` を明示

### IMP-18 in-memory SQLite + `transactional()` 非互換を howto の注意事項に昇格
- 発見: Trial 17-B（Coupon ロースキル）で再確認（過去 Trial でも発生済み）
- 原因: `DatabaseTransactionManagerInterface` が別コネクションを開くため `:memory:` DB が空になる
- 解決: `sys_get_temp_dir() . '/name-' . uniqid() . '.db'` のファイルベース SQLite を使う
- 対応: `use-transactions.md` の「テストの注意事項」セクションに `:memory:` 禁止を警告ボックスで明記

### IMP-19 `src/AppFactory.php` の autoload 罠を howto に明記 ★★★ 新規
- 発見: Trial 22-C, 23-C, 25-C — シニアが3試験連続で `src/AppFactory.php` + `namespace Ns;` を書いてクラスが見つからないエラー
- 原因: `"Ns\\": "src/Ns/"` マッピング下では `src/` 直下のファイルは autoload されない。正しくは `src/Ns/AppFactory.php`
- Laravel 移行者の混乱源: Laravel では `App\` が `app/` にフラットマッピング → `app/MyClass.php` で動く習慣
- 対応: `add-database-endpoint.md` のファイル構成図に「AppFactory を含む全ファイルは PSR-4 マッピング先ディレクトリ内に置く」を明記

### IMP-20 SQLite 型アフィニティの適用範囲を howto で拡大（WHERE 内サブクエリ）
- 発見: Trial 24-A（ランキング新卒）で `WHERE sub.max_score > CAST(? AS INTEGER)` が必要
- 拡大: IMP-12 は HAVING 句の問題として記録したが、WHERE 内のサブクエリ結果との比較でも同じ問題が発生
- 対応: `add-database-endpoint.md` の CAST パターン説明を「プレースホルダーと数値比較を行う場合は全般的に CAST が必要」と一般化

### IMP-21 PHP regex デリミタと `$` の干渉を howto に注記
- 発見: Trial 25-B（アセット管理ロースキル）で `#type/subtype$#` の `$` が regex modifier として誤解釈
- 解決: `$` を末尾に使う場合は `#type/subtype\z#` か `explode()` に切り替え
- 対応: `add-database-endpoint.md` または新規 howto の regex サンプルに注意書き追加（マイナーだが再発しやすい）

---

*作成: 2026-05-27 / Trial 01 COMPARISON_REPORT.md より*  
*更新: 2026-05-27 / Trial 22-26 比較レポートより追記*
