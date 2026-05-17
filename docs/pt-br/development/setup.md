# Guia de Instalação Local

Este guia apresenta como configurar o NENE2 localmente, desde um clone limpo até uma API em funcionamento.

## Pré-requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (ou Docker Engine + plugin Compose)
- Git

Não é necessária instalação de PHP, Node.js ou MySQL no host. Todas as dependências de runtime rodam dentro do Docker.

## 1. Clonar e Configurar

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

Abra `.env` e ajuste os valores se necessário. Os padrões funcionam para desenvolvimento local sem alterações.

Variáveis de ambiente principais:

| Variável | Padrão | Propósito |
|---|---|---|
| `APP_ENV` | `local` | Ambiente de runtime |
| `NENE2_MACHINE_API_KEY` | *(vazio)* | Deixe vazio para desabilitar auth de machine-client no dev local |
| `DB_ADAPTER` | `mysql` | `sqlite` ou `mysql` |
| `DB_HOST` | `mysql` | Corresponde ao nome do serviço Docker Compose |

## 2. Construir e Instalar

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. Executar Verificações do Backend

```bash
docker compose run --rm app composer check
```

Este comando executa PHPUnit, PHPStan, PHP-CS-Fixer, validação OpenAPI e validação do catálogo MCP em sequência. Tudo deve passar em um clone limpo.

## 4. Iniciar o Servidor Web

```bash
docker compose up -d app
```

Verificar se está rodando:

```bash
curl -i http://localhost:8080/health
```

Resposta esperada:

```json
{"status":"ok","service":"NENE2"}
```

Outros endpoints locais úteis:

| URL | Descrição |
|---|---|
| `http://localhost:8080/` | Informações do framework |
| `http://localhost:8080/health` | Verificação de saúde |
| `http://localhost:8080/examples/ping` | Exemplo ping |
| `http://localhost:8080/examples/notes/{id}` | Nota por ID (requer DB) |
| `http://localhost:8080/openapi.php` | JSON OpenAPI bruto |
| `http://localhost:8080/docs/` | Interface Swagger |

## 5. Parar o Servidor

```bash
docker compose down
```

## Opcional: Configuração do Banco de Dados MySQL

A suite de testes padrão usa SQLite em memória. Para verificar o adaptador MySQL ou executar smoke tests de operações de escrita:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

## Opcional: Autenticação Machine-Client

O endpoint `/machine/health` requer uma chave de API. Para testá-lo localmente:

1. Defina `NENE2_MACHINE_API_KEY=local-dev-key` no `.env`.
2. Reinicie o serviço app: `docker compose up -d app`
3. Chame o endpoint protegido:

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

## Opcional: Configuração do Frontend

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

## Opcional: Servidor MCP Local

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Opcional: Verificar Request ID nos Logs

Cada request gera um `X-Request-Id` que é ecoado no header de resposta e anexado a cada registro Monolog.

1. Inicie o app: `docker compose up -d app`
2. Envie um request:
   ```bash
   curl -i http://localhost:8080/health
   # Procure por X-Request-Id nos headers de resposta
   ```
3. Observe a saída de log estruturada:
   ```bash
   docker compose logs app
   # Cada linha JSON inclui "extra":{"request_id":"<id>"}
   ```

Você também pode fornecer seu próprio ID:
```bash
curl -i -H 'X-Request-Id: my-trace-id' http://localhost:8080/health
```

## Solução de Problemas

**`composer check` falha em um clone limpo**
Execute `docker compose run --rm app composer install` primeiro. O diretório `vendor/` não está versionado.

**Porta 8080 já em uso**
Pare o que está usando, ou altere o mapeamento de porta no `compose.yaml`:
```yaml
ports:
  - "8081:80"   # usar 8081 em vez disso
```

**Conexão MySQL recusada durante migrações**
O container `mysql` leva alguns segundos para ficar pronto. Aguarde um momento e tente novamente.
