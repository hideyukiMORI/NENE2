# Como Fazer: API de Versionamento de Artigos

> **Referência FT**: FT249 (`NENE2-FT/contentvlog`) — API de Versionamento de Artigos
> **VULN**: FT249 — avaliação de vulnerabilidades (V-01 a V-10)

Demonstra um sistema de versionamento de artigos onde uma coluna inteira `current_version`
na tabela `articles` rastreia a versão mais recente, cada atualização acrescenta à
`article_versions`, e o rollback cria uma nova versão a partir do conteúdo histórico.
Inclui uma avaliação de vulnerabilidades do design não autenticado.

---

## Rotas

| Método | Caminho                              | Descrição                                            |
|--------|--------------------------------------|------------------------------------------------------|
| `POST` | `/articles`                          | Criar um artigo (versão 1)                           |
| `GET`  | `/articles/{id}`                     | Obter um artigo (conteúdo atual)                     |
| `PUT`  | `/articles/{id}`                     | Atualizar artigo (cria nova versão)                  |
| `GET`  | `/articles/{id}/versions`            | Listar histórico de versões (apenas metadados)       |
| `GET`  | `/articles/{id}/versions/{version}`  | Obter uma versão específica                          |
| `POST` | `/articles/{id}/rollback`            | Rollback para uma versão (cria nova versão)          |

---

## Schema: coluna inteira `current_version`

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

A coluna `current_version` armazena o número de versão do conteúdo atual.
`UNIQUE(article_id, version)` previne números de versão duplicados para o mesmo artigo.

**Comparação com a abordagem de flag `is_current`** (veja `document-versioning.md`):

| Abordagem | Inteiro `current_version` | Flag `is_current` |
|---|---|---|
| Schema | Coluna na tabela `articles` | Coluna na tabela `versions` |
| Busca da versão atual | `SELECT * FROM articles WHERE id = ?` (sem JOIN) | `LEFT JOIN ... ON dv.is_current = 1` |
| Rastreamento do número de versão | Inteiro explícito na linha pai | Implícito pela contagem de linhas ou MAX |
| Atomicidade | Atualizar artigo + inserir versão (2 escritas) | UPDATE flag + INSERT (2 escritas) |

---

## Criar: inicialização com duas escritas

Criar um artigo escreve em ambas as tabelas:

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

Ambas as escritas acontecem sem uma transação envolvente. Se a segunda inserção falhar, a linha
`articles` existe mas `article_versions` não tem entrada correspondente — o artigo está na versão 1
sem registro de histórico. Envolva ambas em `$txManager->transactional()` para uso em produção.

---

## Atualizar: padrão ler-então-incrementar

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

O número de versão é lido, incrementado no PHP e depois gravado de volta. Sem uma
transação, atualizações concorrentes podem produzir números de versão duplicados — a
restrição `UNIQUE(article_id, version)` vai capturar isso, mas o `UPDATE` para
`articles` pode ter sucesso antes da `INSERT` para `article_versions` falhar, deixando
o `current_version` do artigo à frente do seu histórico.

---

## Rollback: não destrutivo (copia como nova versão)

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Rollback não deleta versões — copia o conteúdo da versão alvo
como uma nova versão (atual). O histórico é sempre preservado. Se um artigo estiver na
versão 5 e sofrer rollback para a versão 2:

```
v1 → v2 → v3 → v4 → v5 → v6 (cópia do conteúdo de v2)
```

---

## Lista de versões: apenas metadados (sem body)

`GET /articles/{id}/versions` retorna metadados de versão sem o body completo:

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` é excluído da listagem — os chamadores devem buscar versões individuais com
`GET /articles/{id}/versions/{version}` para obter o conteúdo. Isso evita enviar
conteúdo potencialmente grande na resposta de listagem.

---

## VULN — Avaliação de vulnerabilidades (FT249)

### V-01 — Sem autenticação: qualquer chamador pode atualizar ou deletar qualquer artigo

**Risco**: Todos os endpoints são não autenticados.

**Impacto**: Um atacante pode sobrescrever qualquer artigo, fazer rollback do seu conteúdo para uma versão anterior
ou enumerar todo o histórico de versões.

**Veredicto**: **EXPOSED** — adicione autenticação (chave de API, JWT ou sessão). Atualização/rollback
devem exigir que o proprietário do artigo esteja autenticado.

---

### V-02 — Sem propriedade: qualquer usuário autenticado pode modificar qualquer artigo

**Risco**: Mesmo com autenticação, não há consulta com escopo de propriedade. Qualquer usuário autenticado
pode atualizar o artigo de qualquer outro usuário.

**Impacto**: Sem `WHERE id = ? AND owner_id = ?`, os IDs de artigo são enumeráveis e
modificáveis por qualquer pessoa com um token válido.

**Veredicto**: **EXPOSED** — adicione uma coluna `owner_id` a `articles`. Aplique a propriedade
com `WHERE id = ? AND owner_id = ?` em todas as operações de escrita.

---

### V-03 — IDOR: ler o histórico de versões de outro usuário

**Risco**: `GET /articles/{id}/versions` retorna todo o histórico de versões para qualquer ID de artigo.

**Impacto**: Um atacante pode enumerar o histórico de conteúdo em rascunho que o autor pode não
ter pretendido tornar público.

**Veredicto**: **EXPOSED** — aplique escopo de propriedade em todas as leituras: somente o proprietário do artigo (ou funções
com permissão de leitura explícita) deve ver o histórico de versões.

---

### V-04 — Condição de corrida no incremento do número de versão

**Risco**: `update()` lê `current_version`, incrementa no PHP e depois escreve de volta.
Nenhuma transação ou bloqueio de linha envolve a sequência leitura-escrita.

**Ataque**: Duas requisições `PUT /articles/1` concorrentes ambas leem `current_version = 3`.
Ambas calculam `nextVersion = 4`. Uma tem sucesso (insere versão 4); a outra falha
na restrição `UNIQUE(article_id, version)` — mas o `UPDATE articles` pode ter
já ter tido sucesso, definindo `current_version = 4` para ambas, com apenas um registro de versão
no histórico.

**Veredicto**: **EXPOSED** — envolva `find` + `UPDATE` + `INSERT` em uma transação de banco de dados.
Use `UPDATE articles SET current_version = current_version + 1` para incremento atômico.

---

### V-05 — SQL injection via title ou body

**Ataque**: Incorpore metacaracteres SQL.

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**Observado**: Os valores são vinculados como placeholders parametrizados `?`. A injeção é armazenada
como texto literal.

**Veredicto**: **BLOCKED** — consultas parametrizadas previnem SQL injection.

---

### V-06 — Enumeração de versão: acesso ilimitado ao histórico

**Risco**: `GET /articles/{id}/versions` retorna o histórico completo de versões sem
paginação ou limite.

**Impacto**: Um artigo com milhares de versões retorna todas as linhas em uma única resposta,
causando pressão de memória e consultas lentas.

**Veredicto**: **EXPOSED** — adicione paginação (`LIMIT ? OFFSET ?`) ao endpoint de lista de versões.
Considere limitar o número máximo de versões por artigo.

---

### V-07 — Operações de duas escritas não transacionais

**Risco**: Tanto `create()` quanto `update()` realizam duas escritas sequenciais sem uma
transação de banco de dados envolvente.

**Impacto**: Se a segunda escrita falhar (ex.: violação de restrição, erro de conexão),
o sistema fica em estado inconsistente: `articles.current_version` pode diferir da
contagem de linhas `article_versions`, ou um artigo pode existir sem nenhum registro de versão.

**Veredicto**: **EXPOSED** — envolva escritas pareadas em `DatabaseTransactionManagerInterface::transactional()`.

---

### V-08 — Rollback para uma versão de outro artigo

**Ataque**: Envie um rollback com um número de `version` que existe para um artigo diferente.

```bash
# O Artigo 1 tem versões 1-3; o Artigo 2 tem versão 1
POST /articles/1/rollback  {"version": 1}
```

**Observado**: `findVersion(articleId=1, version=1)` usa `WHERE article_id = ? AND version = ?`
— encontra apenas versões pertencentes ao artigo 1. Uma versão que existe para o artigo 2
não é retornada.

**Veredicto**: **BLOCKED** — a busca de versão tem escopo por `article_id`.

---

### V-09 — Body grande: sem limite de tamanho no conteúdo do artigo

**Risco**: `body` aceita strings de comprimento arbitrário sem validação.

**Impacto**: Bodies de vários megabytes consomem armazenamento e memória em cada leitura.

**Veredicto**: **EXPOSED** — adicione uma verificação de comprimento de body (ex.: `strlen($body) > 1_000_000 → 422`).
Dependa do middleware de tamanho de requisição como limite externo.

---

### V-10 — Rollback para `version = 0` ou versão negativa

**Ataque**: Envie um rollback com versão 0 ou -1.

```json
{"version": 0}
{"version": -1}
```

**Observado**: `(int) $body['version']` aceita qualquer inteiro. `findVersion($id, 0)` e
`findVersion($id, -1)` retornam `null` (versão inexistente) → `404 Not Found`. Nenhuma versão 0
é jamais armazenada (as versões começam em 1).

**Veredicto**: **BLOCKED** — `findVersion` retorna `null` para versões inexistentes;
nenhum caso especial é necessário.

---

## Resumo VULN

| # | Vulnerabilidade | Veredicto |
|---|-----------------|-----------|
| V-01 | Sem autenticação nos endpoints de escrita | EXPOSED |
| V-02 | Sem verificação de propriedade (qualquer usuário pode modificar qualquer artigo) | EXPOSED |
| V-03 | IDOR no histórico de versões | EXPOSED |
| V-04 | Condição de corrida no incremento do número de versão | EXPOSED |
| V-05 | SQL injection via title/body | BLOCKED |
| V-06 | Lista de versões ilimitada (sem paginação) | EXPOSED |
| V-07 | Escritas pareadas não transacionais | EXPOSED |
| V-08 | Rollback para versão de outro artigo | BLOCKED |
| V-09 | Sem limite de tamanho do body | EXPOSED |
| V-10 | Rollback para versão 0 / negativa | BLOCKED |

**Correções críticas antes da produção**:
1. **V-01 / V-02 / V-03** — Adicione autenticação e aplicação de propriedade via `owner_id`
2. **V-04 / V-07** — Envolva todas as operações de múltiplas escritas em `transactional()`; use incremento atômico de versão
3. **V-06** — Adicione paginação `LIMIT ? OFFSET ?` à lista de versões
4. **V-09** — Adicione validação de tamanho do body

---

## Howtos relacionados

- [`document-versioning.md`](document-versioning.md) — abordagem de flag `is_current` com `DatabaseTransactionManagerInterface`
- [`content-versioning.md`](content-versioning.md) — versionamento de conteúdo com números de versão lineares
- [`transactions.md`](transactions.md) — padrões com DatabaseTransactionManagerInterface
- [`optimistic-locking.md`](optimistic-locking.md) — prevenção de condição de corrida com coluna de versão + UPDATE condicional
