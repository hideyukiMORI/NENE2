# ドメインレイヤーポリシー

NENE2 はフレームワークインフラ（HTTP ランタイム・DI・設定・データベースアダプター）とアプリケーションロジック（ユースケース・リポジトリ・ドメインルール）を分離します。このドキュメントでは、HTTP ハンドラーとデータベースアダプターの間に位置するアプリケーションレイヤーの規約を定義します。

## ポジション

ドメインレイヤーは、リクエストの到着方法やデータの保存方法とは独立して、アプリケーションが何をするかを表現するユースケースとリポジトリインターフェースのセットです。

```text
HTTP handler（薄い）
  → UseCase（アプリケーションロジック・ビジネス不変条件）
    → RepositoryInterface（データアクセス契約）
      → PdoRepositoryAdapter（永続化の詳細）
```

フレームワークインフラは `src/` に存在します。アプリケーション固有のユースケースとリポジトリインターフェースは、クライアントプロジェクトが管理する名前空間に置く必要があります。NENE2 は規約と最小限の動作例を提供しますが、アプリケーションコードに名前空間を強制しません。

## UseCase 規約

ユースケースは一つのアプリケーション操作を表現します。readonly の入力 DTO を受け取り、ビジネス不変条件を適用し、型付き出力を返します。

### インターフェースの形

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

ルール:

- ユースケースインターフェースごとに一つのメソッド、常に `execute` と命名する。
- 入力と出力は型付き readonly DTO であり、生の配列や PSR-7 オブジェクトではない。
- インターフェースはアダプターの隣または上に置き、フレームワークディレクトリ内には置かない。
- ユースケースは、呼び出し元が対処しなければならない不変条件違反に対してドメイン固有の例外をスローできる。
- ユースケースは HTTP・セッション・テンプレート・キューを知らない。
- ユースケースは PSR-11 コンテナを直接呼び出さない。

### 入力 DTO

```php
final readonly class CreateItemInput
{
    public function __construct(
        public string $name,
        public int    $year,
    ) {
    }
}
```

- デフォルトで `readonly` と `final`。
- コンストラクターは検証済みの値を受け取る。フォーマット検証はユースケースを呼び出す前にハンドラーで行われる。
- ビジネス不変条件（一意性・状態ルール）はユースケース内で確認され、ここでは行わない。

### 出力 DTO

```php
final readonly class CreateItemOutput
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $year,
    ) {
    }
}
```

- 呼び出し元が必要とするものだけを保持する。呼び出し元が必要としない限り、永続化 ID や内部状態を公開しない。
- 副作用のある操作であっても型付き出力を返す。呼び出し元が結果を得るためにリポジトリに直接アクセスすべきではない。

### 実装

```php
final class CreateItemUseCase implements CreateItemUseCaseInterface
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
    ) {
    }

    public function execute(CreateItemInput $input): CreateItemOutput
    {
        if ($this->items->existsByName($input->name)) {
            throw new ItemAlreadyExistsException($input->name);
        }

        $id = $this->items->save(new Item(name: $input->name, year: $input->year));

        return new CreateItemOutput(id: $id, name: $input->name, year: $input->year);
    }
}
```

- コンストラクターインジェクションのみ。
- テスト可能にする必要がある依存関係に `new` を使わない。
- ユースケースがトランザクション境界を所有しない限り、データベーストランザクションをここには置かない。トランザクションはアダプターまたはトランザクションマネージャーサービスに属する。

## リポジトリインターフェース規約

リポジトリインターフェースは、一つの集約またはドメインコンセプトのデータアクセス契約を記述します。アダプターがそれを実装します。

### インターフェースの形

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

ルール:

- メソッドはドメイン用語を使い、SQL 動詞は使わない。`selectById` ではなく `findById`。
- 戻り値型はドメインオブジェクトまたはプリミティブを使い、PDO 結果行や生の配列は使わない。
- 不在が有効なケースの場合、スローではなく Nullable 戻り値（`?Item`）を使う。不在がプログラムエラーを示す場合のみスローする。
- インターフェースは `src/Database/` ではなく、アプリケーション名前空間に置く。

### ドメインオブジェクト

永続化の詳細をアダプター内に留めるべき場合、集約ルートに小さな readonly クラスを使用します:

```php
final readonly class Item
{
    public function __construct(
        public string $name,
        public int    $year,
        public ?int   $id = null,
    ) {
    }
}
```

- `id` は永続化前は nullable。
- ドメインオブジェクトを ORM アノテーションやデータベース結合から自由に保つ。

### PDO アダプター

```php
final class PdoItemRepository implements ItemRepositoryInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
    ) {
    }

    public function findById(int $id): ?Item
    {
        $row = $this->query->fetchOne('SELECT id, name, year FROM items WHERE id = ?', [$id]);

        return $row !== null
            ? new Item(name: $row['name'], year: (int) $row['year'], id: (int) $row['id'])
            : null;
    }

    public function existsByName(string $name): bool
    {
        return $this->query->fetchOne('SELECT 1 FROM items WHERE name = ?', [$name]) !== null;
    }

    public function save(Item $item): int
    {
        return $this->query->insert('INSERT INTO items (name, year) VALUES (?, ?)', [$item->name, $item->year]);
    }
}
```

- 生の PDO ではなく `src/Database/` の `DatabaseQueryExecutorInterface` を使う。
- すべての SQL はアダプター内に留める。ユースケースとドメインオブジェクトに SQL はない。
- データベース行の値を出力時に型付き PHP 値にキャストする。
- アダプタークラス名のプレフィックス: `Pdo`（例: `PdoItemRepository`）。

## ハンドラー（コントローラー）境界

ハンドラーは薄く保ちます。その役割は HTTP リクエストをユースケース入力にマッピングし、ユースケースを呼び出し、レスポンスを返すことです。

```php
final class CreateItemHandler
{
    public function __construct(
        private readonly CreateItemUseCaseInterface $useCase,
        private readonly JsonResponseFactory        $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $input = new CreateItemInput(
            name: (string) ($body['name'] ?? ''),
            year: (int) ($body['year'] ?? 0),
        );

        $output = $this->useCase->execute($input);

        return $this->response->ok(['id' => $output->id, 'name' => $output->name, 'year' => $output->year]);
    }
}
```

ルール:

- ハンドラーにビジネスロジックを含めない。
- フォーマット検証と DTO 構築はここで行う。ビジネス不変条件はユースケースに留める。
- ハンドラーはリポジトリを直接呼び出さない。
- ハンドラーはインターフェースに型付けされたユースケースをコンストラクターインジェクションで受け取る。

## コードレイアウト

フレームワークインフラはフレームワーク名前空間の下の `src/` に存在します:

```
src/
  Database/     DB アダプター境界（インターフェース + PDO 実装）
  DependencyInjection/
  Config/
  Http/
  Middleware/
  Routing/
  Validation/
  Error/
  View/
  Mcp/
```

アプリケーション固有のコード（ユースケース・リポジトリ・ドメインオブジェクト）は、クライアントプロジェクトに適したディレクトリと名前空間に置く必要があります。NENE2 のサンプルコードは、クライアントプロジェクトが独自の名前空間を定義するまで `src/` を直接使用します。

NENE2 を拡張するクライアントプロジェクトの推奨レイアウト:

```
src/
  Item/
    CreateItemInput.php
    CreateItemOutput.php
    CreateItemUseCaseInterface.php
    CreateItemUseCase.php
    Item.php
    ItemRepositoryInterface.php
    ItemAlreadyExistsException.php
    PdoItemRepository.php
    CreateItemHandler.php
```

レイヤータイプではなく、ドメインコンセプトでグループ化する。一つのコンセプトを無関係なファイルに散らす `UseCases/`・`Repositories/`・`Handlers/` のトップレベルディレクトリを避ける。

## PSR-11 コンテナーワイヤリング

フォーカスされたサービスプロバイダーにユースケースとリポジトリを登録します:

```php
final class ItemServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bind(ItemRepositoryInterface::class, static function (ContainerInterface $c): ItemRepositoryInterface {
            return new PdoItemRepository($c->get(DatabaseQueryExecutorInterface::class));
        });

        $builder->bind(CreateItemUseCaseInterface::class, static function (ContainerInterface $c): CreateItemUseCaseInterface {
            return new CreateItemUseCase($c->get(ItemRepositoryInterface::class));
        });

        $builder->bind(CreateItemHandler::class, static function (ContainerInterface $c): CreateItemHandler {
            return new CreateItemHandler(
                $c->get(CreateItemUseCaseInterface::class),
                $c->get(JsonResponseFactory::class),
            );
        });
    }
}
```

ルール:

- テストの代替が重要な場合、サービス識別子として具体的なクラスではなくインターフェースをバインドする。
- プロバイダーを小さくドメインコンセプトでグループ化する。
- ユースケースやドメインオブジェクト内でコンテナーをサービスロケーターとして使わない。
- `src/Http/RuntimeContainerFactory.php` または同等のブートストラップパスにプロバイダーを登録する。

## テスト

### ユースケースユニットテスト

ユースケーステストはデータベースなしで実行されます。リポジトリインターフェースを実装するテストダブルをインジェクトします。

```php
final class CreateItemUseCaseTest extends TestCase
{
    public function test_throws_when_item_name_already_exists(): void
    {
        $items = new InMemoryItemRepository([new Item(name: 'duplicate', year: 2026, id: 1)]);
        $useCase = new CreateItemUseCase($items);

        $this->expectException(ItemAlreadyExistsException::class);

        $useCase->execute(new CreateItemInput(name: 'duplicate', year: 2026));
    }

    public function test_returns_output_with_new_id(): void
    {
        $items = new InMemoryItemRepository([]);
        $useCase = new CreateItemUseCase($items);

        $output = $useCase->execute(new CreateItemInput(name: 'new-item', year: 2026));

        $this->assertSame('new-item', $output->name);
    }
}
```

`InMemoryItemRepository` は `ItemRepositoryInterface` を実装し、インメモリ配列を使用します。`tests/` に置き、本番コードには含まれません。

### リポジトリアダプター統合テスト

アダプターテストはテストデータベースに対して実際の SQL を実行します。フォーカスされたデータベーステストコマンドを使用します:

```bash
docker compose run --rm app composer test:database
```

サービスデータベースが必要なアダプターテストの場合:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

アダプターテストクラスはプロジェクトのデータベーステストケースを拡張します（`docs/development/test-database-strategy.md` を参照）。SQL の正確性・型キャスト・インメモリダブルでカバーできないエッジケースをテストします。

## エラーハンドリング

- ビジネス不変条件違反には名前付きドメイン例外をスローする（`ItemAlreadyExistsException`・`ItemNotFoundException`）。
- ユースケース内ではなく、HTTP エラー境界でドメイン例外を Problem Details にマッピングする。
- `src/Error/ErrorHandlerMiddleware.php` または同等のエラーハンドラーにマッピングエントリを追加する。
- エラーレスポンスに SQL エラー・スタックトレース・内部識別子を公開しない。

## 非目標

- Active record または Eloquent スタイルのモデル。
- OpenAPI またはデータベーススキーマからの自動コード生成。
- 最初のパスでの CQRS・イベントソーシング・サガパターン。
- リフレクションやアノテーションによる依存性注入。
- ユースケースやドメインオブジェクト内でのサービスロケーター呼び出し。
- ミドルウェアやルーターコールバック内のビジネスロジック。

## 関連ドキュメント

- コーディング規約: `docs/development/coding-standards.md`
- リクエストバリデーションポリシー: `docs/development/request-validation.md`
- データベースアダプター境界: `src/Database/`
- データベーステスト戦略: `docs/development/test-database-strategy.md`
- 依存性注入ポリシー: `docs/development/dependency-injection.md`
- エンドポイントスキャフォールドワークフロー: `docs/development/endpoint-scaffold.md`
- クライアントプロジェクト開始ガイド: `docs/development/client-project-start.md`
- GitHub Issue: `#182`
