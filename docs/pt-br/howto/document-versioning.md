# Como Fazer: API de Versionamento de Documentos

> **Referência FT**: FT239 (`NENE2-FT/doclog`) — API de Versionamento de Documentos

Demonstra um sistema de versionamento de documentos append-only onde a versão atual é
rastreada com uma flag `is_current`, a reversão cria uma nova versão (não-destrutivo),
e todas as escritas multi-etapa são envolvidas em transações via `DatabaseTransactionManagerInterface`.

---

## Rotas

| Método | Caminho                                      | Descrição                                          |
|--------|----------------------------------------------|----------------------------------------------------|
| `POST` | `/documents`                                 | Criar um documento com sua primeira versão         |
| `GET`  | `/documents`                                 | Listar documentos (paginado) com versão atual      |
| `GET`  | `/documents/{id}`                            | Obter um documento com sua versão atual            |
| `GET`  | `/documents/{id}/versions`                   | Listar histórico de versões (paginado)             |
| `POST` | `/documents/{id}/versions`                   | Adicionar uma nova versão                          |
| `POST` | `/documents/{id}/revert/{version}`           | Reverter para um número de versão específico       |

Sub-rotas estáticas (`/documents/{id}/versions`) são registradas antes da rota
parametrizada `/documents/{id}` para garantir despacho correto.

---

## Schema: padrão de flag `is_current`

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    content     TEXT    NOT NULL,
    version_num INTEGER NOT NULL,
    is_current  INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0, 1)),
    created_at  TEXT    NOT NULL,
    UNIQUE(document_id, version_num)
);
CREATE INDEX IF NOT EXISTS idx_versions_document ON document_versions(document_id);
```

`is_current` é uma flag booleana (0/1) armazenada como INTEGER, restringida por `CHECK`. No
máximo uma linha por documento deve ter `is_current = 1`. `UNIQUE(document_id, version_num)`
previne números de versão duplicados para o mesmo documento.

**Comparação com `current_version` inteiro**: a abordagem com flag `is_current` evita
a necessidade de atualizar uma coluna na tabela pai `documents` a cada mudança de versão.
A flag é alternada na tabela `document_versions` diretamente na mesma
transação que insere a nova versão.

---

## Buscando a versão atual com JOIN

As consultas de listagem e show usam um `LEFT JOIN` filtrado em `is_current = 1` para recuperar
a versão atual em uma única consulta:

```php
$row = $this->executor->fetchOne(
    'SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current,
            dv.created_at AS version_created_at
     FROM documents d
     LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
     WHERE d.id = ?',
    [$id],
);
```

`LEFT JOIN ... AND dv.is_current = 1` — a condição de join filtra para a versão atual
apenas. Um documento sem versões retorna uma linha de join `NULL`, hidratada como
`currentVersion: null`.

---

## Adicionando uma versão: transação em três etapas

Adicionar uma versão requer três operações em sequência, envolvidas em uma transação:

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // Etapa 1: Computar próximo número de versão
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Etapa 2: Desativar a versão atual
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Etapa 3: Inserir a nova versão como atual
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // Etapa 4: Atualizar updated_at do documento
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` envolve o closure em uma transação.
Se qualquer etapa lançar uma exceção, a transação é revertida. O parâmetro `$tx` é o executor
com escopo para a transação — não é necessária uma conexão separada.

---

## Reversão não-destrutiva: copiar como nova versão

Reversões não alteram o histórico existente — elas criam uma nova versão contendo o
conteúdo da versão alvo:

```php
public function revertToVersion(int $documentId, int $versionNum, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $versionNum, $now): Document {
        $targetRow = $tx->fetchOne(
            'SELECT * FROM document_versions WHERE document_id = ? AND version_num = ?',
            [$documentId, $versionNum],
        );

        if ($targetRow === null) {
            throw new VersionNotFoundException($documentId, $versionNum);
        }

        // Computar próximo número de versão para a cópia de reversão
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Desativar versão atual
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Inserir uma cópia do conteúdo alvo como nova versão atual
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

Se um documento está na versão 5 e é revertido para a versão 2, a versão 6 é criada com
o conteúdo da versão 2. O histórico fica:
```
v1 → v2 → v3 → v4 → v5 → v6 (cópia de v2)
```

Essa abordagem preserva a trilha de auditoria completa — a própria reversão é visível no
histórico como uma nova entrada. É impossível "perder" histórico.

---

## VersionNotFoundException com contexto estruturado

`VersionNotFoundException` carrega tanto o ID do documento quanto o número da versão:

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

A exceção é lançada dentro do closure da transação. O handler de exceção a mapeia
para uma resposta `404 Not Found`. Como a exceção é lançada antes de qualquer operação de
escrita na reversão, a transação é revertida de forma limpa.

---

## Utilitários internos do NENE2: PaginationQueryParser e PaginationResponse

Endpoints de listagem usam os helpers de paginação do NENE2:

```php
private function listDocuments(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request);
    $items      = $this->repository->findAll($pagination->limit, $pagination->offset);
    $total      = $this->repository->countAll();

    $response = new PaginationResponse(
        items: array_map($this->serializeDocument(...), $items),
        limit: $pagination->limit,
        offset: $pagination->offset,
        total: $total,
    );

    return $this->json->create($response->toArray());
}
```

`PaginationQueryParser::parse()` lê `?limit=` e `?offset=` dos parâmetros de consulta com
padrões e limites seguros. `PaginationResponse::toArray()` produz um envelope consistente:
`{ items, total, limit, offset }`.

---

## Utilitários internos do NENE2: ValidationException e ValidationError

A validação de entrada usa os helpers de validação estruturada do NENE2:

```php
$errors = [];
if (!isset($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
    $errors[] = new ValidationError('title', 'title is required.', 'required');
}
if (!isset($body['content']) || !is_string($body['content'])) {
    $errors[] = new ValidationError('content', 'content is required.', 'required');
}
if ($errors !== []) {
    throw new ValidationException($errors);
}
```

`ValidationException` é capturada pelo handler de erros do NENE2 e convertida em uma
resposta Problem Details `422 Unprocessable Entity` com um array `errors` estruturado —
idêntico a chamar `ProblemDetailsResponseFactory::create()` com extensão `errors`,
mas via o caminho baseado em exceção.

---

## Howtos relacionados

- [`content-versioning.md`](content-versioning.md) — padrão current_version baseado em inteiro
- [`audit-trail.md`](audit-trail.md) — padrões de histórico append-only
- [`transactions.md`](transactions.md) — padrões de DatabaseTransactionManagerInterface
- [`use-transactions.md`](use-transactions.md) — envolvendo operações de escrita múltipla
