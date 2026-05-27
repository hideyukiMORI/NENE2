# Guia de Implementação de API de Importação em Massa via CSV

## Visão Geral

Este guia explica como implementar uma API de importação em massa via CSV usando o NENE2.
Oferece validação por linha, sucesso parcial, coleta de erros e gerenciamento de histórico de importação como uma API REST.

---

## Schema do BD

```sql
CREATE TABLE import_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'completed',
    total_rows    INTEGER NOT NULL DEFAULT 0,
    imported_rows INTEGER NOT NULL DEFAULT 0,
    failed_rows   INTEGER NOT NULL DEFAULT 0,
    errors        TEXT    NOT NULL DEFAULT '[]',
    created_at    TEXT    NOT NULL,
    completed_at  TEXT
);

CREATE TABLE imported_records (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    import_job_id INTEGER NOT NULL,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL,
    age           INTEGER,
    created_at    TEXT    NOT NULL,
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id)
);
```

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| POST | `/imports` | Importar CSV (processamento síncrono, com suporte a sucesso parcial) |
| GET | `/imports` | Listar jobs de importação |
| GET | `/imports/{importId}` | Obter resultado de importação + registros |

### Formato da Requisição

```json
POST /imports
{
  "csv": "name,email,age\nAlice,alice@example.com,30\nBob,bob@example.com,25",
  "filename": "users.csv"
}
```

O CSV é enviado como string no campo `csv` do corpo JSON. Isso facilita o teste no fluxo padrão de API JSON.

---

## Implementação

### CsvImporter (parser puro)

```php
class CsvImporter
{
    private const array REQUIRED_HEADERS = ['name', 'email', 'age'];

    /** @return array{rows: list<...>, errors: list<...>} */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        // ...

        foreach ($lines as $i => $line) {
            // PHP 8.4: $escape explícito necessário para evitar deprecation
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // pular cabeçalho
            }
            // ... validação e coleta
        }
    }

    public function validateHeader(string $csv): bool
    {
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false) {
            return false;
        }
        $headers = array_map(
            static fn(?string $h): string => trim((string) ($h ?? '')),
            str_getcsv($firstLine, ',', '"', '\\'),
        );
        return array_map('strtolower', $headers) === self::REQUIRED_HEADERS;
    }
}
```

### RouteRegistrar (trecho)

```php
private function handleCreateImport(ServerRequestInterface $request): ResponseInterface
{
    $body = (array) ($request->getParsedBody() ?? []);

    if (!isset($body['csv']) || !is_string($body['csv'])) {
        throw new ValidationException([new ValidationError('csv', 'csv is required', 'required')]);
    }

    $csv = $body['csv'];
    if (trim($csv) === '') {
        throw new ValidationException([new ValidationError('csv', 'csv must not be empty', 'required')]);
    }

    if (!$this->importer->validateHeader($csv)) {
        throw new ValidationException([
            new ValidationError('csv', 'CSV must have header row: name,email,age', 'invalid_format'),
        ]);
    }

    $parsed = $this->importer->parse($csv);
    $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $jobId = $this->repo->createJob(
        $filename,
        count($parsed['rows']) + count($parsed['errors']),
        count($parsed['rows']),
        count($parsed['errors']),
        $parsed['errors'],
        $now,
    );

    foreach ($parsed['rows'] as $row) {
        $this->repo->insertRecord($jobId, $row['name'], $row['email'], $row['age'], $now);
    }

    return $this->json->create($this->formatJob($this->repo->findJob($jobId)), 201);
}
```

---

## Pontos de Design

### PHP 8.4: Parâmetro $escape Obrigatório em str_getcsv()

No PHP 8.4, o parâmetro `$escape` em `str_getcsv()` passou a ser obrigatório (período de transição para mudança de valor padrão).
Omiti-lo gera deprecation.

```php
// Incorreto: deprecation no PHP 8.4
$fields = str_getcsv($line);

// Correto: especificar $escape explicitamente (compatível com RFC 4180)
$fields = str_getcsv($line, ',', '"', '\\');
```

Além disso, `str_getcsv()` pode retornar `null` para campos vazios. No PHP 8.4, `trim(null)` também gera deprecation, então trate-o explicitamente:

```php
$fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);
```

### Padrão de Sucesso Parcial

Na importação em massa, é prático **importar apenas linhas válidas + coletar erros de linhas inválidas** em vez de "sucesso total ou falha total":

```php
$parsed = $this->importer->parse($csv);
// $parsed['rows'] = lista de linhas válidas → INSERT
// $parsed['errors'] = [{row: 3, value: "bad@", error: "invalid email format"}, ...]
```

Retornar `imported_rows` / `failed_rows` / `errors` na resposta:

```json
{
  "imported_rows": 4,
  "failed_rows": 1,
  "errors": [{"row": 3, "value": "bad-email", "error": "invalid email format"}]
}
```

### Detecção de E-mails Duplicados no Lote

Mesmo que o mesmo arquivo CSV contenha múltiplas linhas com o mesmo e-mail, detecte isso no importador via hashmap em vez de depender de constraints do BD:

```php
$seenEmails = [];
// ...
if (isset($seenEmails[$email])) {
    $rowErrors[] = 'duplicate email in import batch';
}
// ...
$seenEmails[$email] = true;
```

Capturar erros de constraint do BD torna incerto se a linha foi inserida ou não,
e a mensagem de erro fica obscura. A detecção antecipada é mais explícita e oferece melhor UX.

### Suporte a CRLF

CSVs gerados no Windows usam `\r\n` como quebra de linha. Use `preg_split('/\r\n|\r|\n/', ...)` para tratar uniformemente:

```php
$lines = preg_split('/\r\n|\r|\n/', trim($csv));
```

### Persistência do Campo errors como JSON

`errors` é salvo como string JSON na coluna TEXT do BD e decodificado na leitura:

```php
// Salvar
json_encode($errors)

// Ler e formatar
$errors = json_decode((string) $job['errors'], true) ?? [];
```

O SQLite não tem tipo JSON, então usa-se TEXT como substituto. O MySQL também é igual (embora o tipo JSON possa ser usado, optou-se por TEXT para compatibilidade).

---

## Exemplos de Resposta

### POST /imports (sucesso parcial)

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 3,
  "imported_rows": 2,
  "failed_rows": 1,
  "errors": [
    {"row": 3, "value": "bad-email", "error": "invalid email format"}
  ],
  "created_at": "2026-01-01T00:00:00Z",
  "completed_at": "2026-01-01T00:00:00Z"
}
```

### GET /imports/{id} (incluindo registros)

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 2,
  "imported_rows": 2,
  "failed_rows": 0,
  "errors": [],
  "records": [
    {"id": 1, "name": "Alice", "email": "alice@example.com", "age": 30, "created_at": "..."},
    {"id": 2, "name": "Bob",   "email": "bob@example.com",   "age": null, "created_at": "..."}
  ]
}
```

---

## Testes de Integração com MySQL

Em ambiente MySQL, execute os testes de integração definindo a variável de ambiente `MYSQL_HOST`:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass phpunit
```

O que verificar nos testes de integração:
- Importação em massa de 100 linhas com todos os registros inseridos corretamente
- Sucesso parcial onde apenas linhas válidas são salvas no BD
- E-mails duplicados no lote são detectados e excluídos

---

## Implementação de Referência

`../NENE2-FT/importlog/` — Field Trial FT158 (22 testes + 5 testes de integração MySQL)
