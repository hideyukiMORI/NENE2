# 品質ツール

このガイドでは、PHPStan、PHP-CS-Fixer、および NENE2 ベースのプロジェクトで実行する際の一般的な問題について説明します。

---

## PHPStan

NENE2 は PHPStan level 8 を対象としています。Composer 経由で `hideyukimori/nene2` をインストールしたコンシューマープロジェクトは、`phpstan.neon` ファイルで独自のソース用に PHPStan を設定できます。

### 最小構成

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### メモリ制限

PHPStan は多くのベンダーパッケージをインポートするプロジェクトの解析時にメモリ不足になる場合があります（nene2 + psr/* + nyholm/psr7 + phpunit スタブがすぐに積み重なります）。デフォルトの PHP メモリ制限は 128 MB で、多くの場合不足します。

**PHPStan 2.x では `memory_limit` は CLI のみのオプションです — `phpstan.neon` には設定できません。**

代わりにコマンドラインで渡してください:

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

または `composer.json` の `scripts` セクションに設定して一貫して適用されるようにします:

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **注意**: `phpstan.neon` の `parameters:` 以下に `memory_limit: 512M` を追加すると、
> PHPStan 2.x で `Invalid configuration: Unexpected item 'parameters › memory_limit'.` が発生します。
> 代わりに CLI フラグを使ってください。

### 誤検知の抑制

PHPStan が修正できない誤検知を出力する場合（例: ベンダークラスの PHPDoc の不一致）、インラインで無視します:

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

またはベースラインファイルを追加します:

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 はベースルールセットとして `@PSR12` を使用し、`strict_param` と `declare_strict_types` を追加しています。

### 最小構成

プロジェクトルートに `.php-cs-fixer.php` を作成します:

```php
<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        'strict_param'         => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
```

### よくある書式の問題

**コンストラクタボディの展開**: `@PSR12` は空でもコンストラクタボディを複数行にすることを要求します。CS-Fixer が自動的に展開します:

```php
// 修正前（手書き）
public function __construct(private Foo $foo) {}

// CS-Fixer 修正後
public function __construct(private Foo $foo)
{
}
```

新しいクラスを書いた後は `composer cs:fix` を実行して手動修正を避けてください。

**`declare(strict_types=1)` の配置**: `declare_strict_types` ルールはこの宣言を自動的に追加します。手動で書いた場合、CS-Fixer がその周りの空白を正規化します。

### ドライランと修正

```bash
composer cs        # ドライラン、変更内容を表示
composer cs:fix    # 修正を適用
```

---

## チェックの組み合わせ

すべてのツールを順番に実行する `check` Composer スクリプトを追加します:

```json
{
    "scripts": {
        "test":     "phpunit",
        "analyse":  "phpstan analyse --level=8 --memory-limit=512M src tests",
        "cs":       "php-cs-fixer fix --dry-run --diff",
        "cs:fix":   "php-cs-fixer fix",
        "check":    ["@test", "@analyse", "@cs"]
    }
}
```

問題を早期に発見するために毎回コミット前に `composer check` を実行してください。CI も同じコマンドを実行するべきです。
