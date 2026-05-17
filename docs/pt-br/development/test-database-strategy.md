# Estratégia de Teste de Banco de Dados

Os testes de adaptadores de banco de dados do NENE2 são determinísticos por padrão e não requerem um servidor de banco de dados específico do desenvolvedor.

## Estratégia Padrão

Os testes de adaptadores de banco de dados gerenciados pelo framework usam primeiro um banco de dados SQLite em memória.

Razões:

- Os testes rodam dentro do container PHP existente
- Nenhuma credencial MySQL ou PostgreSQL local é necessária
- Cada teste pode criar seu próprio schema
- Rápido o suficiente para `composer check`
- Fácil de inspecionar e entender

Comando padrão para verificações focadas de adaptadores de banco de dados:

```bash
docker compose run --rm app composer test:database
```

`composer check` continua executando a suite PHPUnit completa incluindo testes de adaptadores de banco de dados.

## Formato dos Testes

Testes de adaptadores de banco de dados devem:

- criar o schema no teste
- usar dados pequenos e determinísticos
- evitar credenciais próximas à produção
- evitar depender do estado das migrações (a menos que o teste seja explicitamente sobre migrações)
- preferir objetos de configuração tipados a variáveis de ambiente brutas
- colocar expectativas SQL perto do adaptador sendo testado

## Banco de Dados Externo

Para comportamentos de adaptador que o SQLite não pode cobrir, verificação MySQL via Docker Compose está disponível.

Inicie o serviço e execute o comando opt-in:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

Este caminho verifica criação de conexão PDO MySQL, execução de queries parametrizadas e rollback de transação contra um serviço MySQL real.

Testes de banco de dados externos permanecem opt-in até que containers de serviço documentados e credenciais seguras existam em CI. Eles não bloqueiam o caminho `composer check` local padrão.

Os padrões do Docker Compose são credenciais de desenvolvimento apenas locais. Sobrescreva com variáveis de ambiente se necessário, e não comite segredos reais de banco de dados.

## Testes de Migração

Testes de migração devem ser separados dos testes de repositório de adaptadores.

Ao introduzir testes de migração, defina:

- qual serviço de banco de dados usar em CI
- como resetar o schema entre execuções
- se seeds são permitidos
- qual comando Composer executar
- comportamento quando migrações são intencionalmente irreversíveis
