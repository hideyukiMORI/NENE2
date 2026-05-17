# Política de Camada de Domínio

O NENE2 separa a infraestrutura do framework (runtime HTTP, DI, config, adaptadores de banco de dados) da lógica de aplicação (casos de uso, repositórios, regras de domínio). Este documento define as convenções para a camada de aplicação que fica entre os handlers HTTP e os adaptadores de banco de dados.

## Posição

A camada de domínio é o conjunto de casos de uso e interfaces de repositório que expressam o que a aplicação faz, independentemente de como os requests chegam ou como os dados são armazenados.

```text
HTTP handler (fino)
  → UseCase (lógica de aplicação, invariantes de negócio)
    → RepositoryInterface (contrato de acesso a dados)
      → PdoRepositoryAdapter (detalhe de persistência)
```

A infraestrutura do framework vive em `src/`. Casos de uso e interfaces de repositório específicos da aplicação devem viver em um namespace que o projeto cliente controla. O NENE2 fornece convenções e exemplos de trabalho mínimos; ele não força um namespace no código de aplicação.

## Convenção UseCase

Um caso de uso expressa uma operação de aplicação. Ele recebe um DTO de entrada readonly, aplica invariantes de negócio e retorna uma saída tipada.

### Formato da Interface

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

Regras:

- Um método por interface de caso de uso, sempre nomeado `execute`.
- Entrada e saída são DTOs readonly tipados, nunca arrays brutos ou objetos PSR-7.
- A interface vive ao lado ou acima de seus adaptadores, não dentro de um diretório do framework.
- Casos de uso podem lançar exceções específicas de domínio para violações de invariantes que chamadores devem tratar.
- Casos de uso não conhecem HTTP, sessões, templates ou filas.
- Casos de uso não chamam o container PSR-11 diretamente.

### DTO de Entrada

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

- `readonly` e `final` por padrão.
- O construtor recebe valores já validados; validação de formato acontece no handler antes de chamar o caso de uso.
- Invariantes de negócio (unicidade, regras de estado) são verificados dentro do caso de uso, não aqui.

### DTO de Saída

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

- Carregue apenas o que os chamadores precisam.
- Retorne uma saída tipada mesmo para operações com efeitos colaterais.

### Implementação

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

- Apenas injeção por construtor.
- Sem chamadas `new` para dependências que precisam ser testáveis.
- Transações de banco de dados pertencem ao adaptador ou a um serviço gerenciador de transações.

## Convenção de Interface de Repositório

Uma interface de repositório descreve um contrato de acesso a dados para um agregado ou conceito de domínio. Adaptadores a implementam.

### Formato da Interface

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

Regras:

- Métodos usam termos de domínio, não verbos SQL. `findById`, não `selectById`.
- Tipos de retorno usam objetos de domínio ou primitivos, não linhas PDO ou arrays brutos.
- Retorno nullable (`?Item`) em vez de lançar exceção quando a ausência é um caso válido.
- Interfaces vivem no namespace de aplicação, não em `src/Database/`.

### Objeto de Domínio

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

- `id` é nullable antes da persistência.
- Mantenha objetos de domínio livres de anotações ORM ou acoplamento com banco de dados.

### Adaptador PDO

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

- Use `DatabaseQueryExecutorInterface` de `src/Database/`, não PDO bruto.
- Todo o SQL fica dentro do adaptador.
- Faça cast dos valores de linhas do banco de dados para valores PHP tipados na saída.
- Prefixo do nome da classe adaptadora: `Pdo` (ex. `PdoItemRepository`).

## Fronteira do Handler (Controller)

Handlers permanecem finos. Seu trabalho é mapear o request HTTP em uma entrada de caso de uso, chamar o caso de uso e retornar uma resposta.

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

Regras:

- Handlers não contêm lógica de negócio.
- Validação de formato e construção do DTO acontecem aqui; invariantes de negócio ficam no caso de uso.
- Handlers não chamam repositórios diretamente.
- Handlers recebem o caso de uso por injeção de construtor, tipado à interface.

## Layout do Código

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

Agrupe por conceito de domínio, não por tipo de camada.

## Wiring do Container PSR-11

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

## Testes

### Testes Unitários de Casos de Uso

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
}
```

### Testes de Integração de Adaptadores de Repositório

```bash
docker compose run --rm app composer test:database
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Tratamento de Erros

- Lance exceções de domínio nomeadas para violações de invariantes de negócio.
- Mapeie exceções de domínio para Problem Details na fronteira de erro HTTP.
- Não exponha erros SQL, stack traces ou identificadores internos em respostas de erro.

## Não-objetivos

- Active record ou modelos estilo Eloquent.
- Geração automática de código a partir de OpenAPI ou schemas de banco de dados.
- CQRS, event sourcing ou padrões saga na primeira passagem.
- Injeção de dependência por reflexão ou anotação.
- Chamadas de service locator dentro de casos de uso ou objetos de domínio.

## Documentação Relacionada

- Padrões de codificação: `docs/development/coding-standards.md`
- Estratégia de teste de banco de dados: `docs/development/test-database-strategy.md`
- Workflow de scaffold de endpoint: `docs/development/endpoint-scaffold.md`
- Guia de início de projeto cliente: `docs/development/client-project-start.md`
