# Como adicionar controle de concorrência otimista (ETag / If-Match)

O bloqueio otimista previne o **problema de atualização perdida**: dois clientes leem o mesmo recurso,
ambos o modificam, e a segunda escrita silenciosamente sobrescreve a primeira.

O NENE2 inclui `ConditionalWriteHelper` para o lado de escrita (PUT, PATCH, DELETE) e
`ConditionalGetHelper` para o lado de leitura (GET → 304 Not Modified).

---

## 1. Adicionar um contador de versão ao schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. Retornar um ETag em todo GET e resposta de escrita

Use o número de versão como um ETag simples e depurável:

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// No handler GET:
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// No handler POST (criar):
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. Verificar `If-Match` em PUT / PATCH / DELETE

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed ou 428 Precondition Required
    }

    // ETag correspondeu — seguro para escrever
    $updated = $this->repo->updateIfMatch($id, /* novos valores */, $doc->version);
    if ($updated === null) {
        // Modificação concorrente após nossa verificação
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### Códigos de status retornados por `ConditionalWriteHelper::check()`

| Cabeçalho `If-Match` | ETag do servidor | Resultado |
|----------------------|-----------------|-----------|
| ausente | qualquer | **428** Precondition Required (cabeçalho é obrigatório) |
| `*` | qualquer | **null** — passa (wildcard, qualquer versão) |
| `"v3"` | `"v3"` | **null** — passa (correspondência exata) |
| `"v2"` | `"v3"` | **412** Precondition Failed (versão desatualizada) |

Para tornar `If-Match` opcional, passe `require: false`:

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. Usar um UPDATE condicional no repositório

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // incompatibilidade de versão ou não encontrado
    }
    return new Document($id, $title, $newVer, $now);
}
```

A cláusula `WHERE version = ?` é a guarda de bloqueio no nível do banco de dados. Se a versão da linha
já foi avançada por um escritor concorrente, `execute()` retorna `0` (nenhuma linha atualizada) e o
chamador pode retornar uma segunda resposta 412.

---

## 5. Testar o cenário de atualização perdida

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice lê a versão 1 e atualiza → versão torna-se 2
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob tenta atualizar com ETag v1 desatualizado → deve falhar
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // A atualização de Alice é preservada
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## Notas

- **Formato ETag**: `"v{version}"` (baseado em inteiro) é simples e previsível em testes.
  ETags baseados em hash de conteúdo (`'"' . md5($body) . '"'`) são mais robustos para recursos endereçáveis por conteúdo,
  mas mais difíceis de prever em testes sem pré-calcular o hash.
- **Wildcard `If-Match: *`**: RFC 9110 define `*` como "ter sucesso se o recurso tiver qualquer
  representação atual" — ou seja, ele existe. Útil para "atualizar se existir" sem conhecer
  a versão. O chamador ainda deve retornar 404 quando o recurso está ausente.
- **428 Precondition Required** (RFC 6585 §3): o status correto quando `If-Match` é obrigatório
  mas está ausente. Use em vez de 400 ou 422 — a requisição é bem formada; a pré-condição está ausente.
- **Janela TOCTOU**: o padrão `findById()` + UPDATE condicional tem uma breve janela de corrida em
  bancos de dados com múltiplos escritores. Sob a serialização de escritas do SQLite, isso é inofensivo. No PostgreSQL
  sob alta concorrência, envolva ambas as operações em uma transação `SERIALIZABLE`.
