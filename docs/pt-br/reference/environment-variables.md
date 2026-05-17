# Variáveis de Ambiente

Todas as variáveis de ambiente reconhecidas pelo NENE2.
Defina-as no arquivo `.env` (carregado pelo phpdotenv) ou exporte-as antes de iniciar o servidor.

## Aplicação

| Variável | Tipo | Padrão | Descrição |
|---|---|---|---|
| `APP_ENV` | string | `local` | Ambiente de execução. Valores aceitos: `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Ativa a saída de depuração. Use `true` apenas em desenvolvimento. |
| `APP_NAME` | string | `NENE2` | Nome da aplicação usado nos logs. Não pode ser vazio. |

## Autenticação

| Variável | Tipo | Padrão | Descrição |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(vazio — desativado)* | Chave API esperada no cabeçalho `X-NENE2-API-Key` para endpoints de cliente máquina. Deixe vazio para desativar. |
| `NENE2_LOCAL_JWT_SECRET` | string | *(vazio — desativado)* | Segredo HMAC-HS256 para proteger as ferramentas de escrita do servidor MCP local. Deixe vazio para acesso somente leitura sem autenticação. |

## Servidor MCP local

| Variável | Tipo | Padrão | Descrição |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(obrigatório)* | URL base usada pelo servidor MCP para proxy de chamadas de API (ex.: `http://app`). Necessário ao executar com Docker Compose. |

## Banco de dados

| Variável | Tipo | Padrão | Descrição |
|---|---|---|---|
| `DATABASE_URL` | string | *(vazio — usa `DB_*`)* | URL completa de conexão. Quando não vazia, substitui todas as variáveis `DB_*`. |
| `DB_ADAPTER` | string | `mysql` | Driver do banco de dados. Aceito: `sqlite`, `mysql`. |
| `DB_HOST` | string | `127.0.0.1` | Host do banco de dados. |
| `DB_PORT` | integer | `3306` | Porta do banco de dados (1–65535). |
| `DB_NAME` | string | `nene2` | Nome do banco de dados. |
| `DB_USER` | string | `nene2` | Usuário do banco de dados. |
| `DB_PASSWORD` | string | *(vazio)* | Senha do banco de dados. |
| `DB_CHARSET` | string | `utf8mb4` | Conjunto de caracteres do banco de dados. |

::: warning Nunca comite segredos
Não comite arquivos `.env` contendo senhas, chaves de API ou segredos JWT no controle de versão.
:::
