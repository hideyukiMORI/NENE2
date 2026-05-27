# Como Fazer: JSON Merge Patch & Detecção de Conflito com ETag

**FT178 — patchlog**

Implementando semânticas PATCH (RFC 7396 JSON Merge Patch) e PUT com
bloqueio otimista via ETag, proteção de campos imutáveis e integração com V.php.

---

## O problema com PUT

`PUT` substitui o recurso inteiro. Clientes devem enviar todos os campos, mesmo os
que não mudaram. Isso cria:

- **Condições de corrida**: leitores concorrentes ambos veem versão 1, ambos fazem PUT, o último
  vence e silenciosamente descarta as mudanças do outro.
- **Desperdício de banda**: payload completo mesmo para mudança de um campo.
- **Confusão de permissões**: escrevendo campos que o cliente não possui.

`PATCH` com **JSON Merge Patch (RFC 7396)** resolve os dois primeiros; `ETag` /
`If-Match` resolve a condição de corrida para PATCH e PUT.

---

## Semânticas do JSON Merge Patch (RFC 7396)

O documento de patch descreve mudanças usando uma regra simples:

| Valor do patch | Significado |
|-------------|---------|
| `"novo valor"` | Definir o campo para este valor |
| `null` | Resetar o campo (deletar ou reverter para padrão) |
| *(chave ausente)* | Deixar o campo sem alteração |

```json
// Documento antes do PATCH:
{ "title": "Hello", "body": "World", "status": "draft" }

// Corpo do PATCH:
{ "title": "Goodbye", "status": null }

// Resultado:
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              sem alter  null → resetar para padrão
```

### Campos imutáveis

Alguns campos nunca devem ser modificáveis via PATCH ou PUT:

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### PATCH vazio é válido (sem efeito)

RFC 7396 §3 permite explicitamente um patch vazio `{}`:

```php
// Sem chaves em $patch → pular UPDATE, retornar documento atual sem alteração
if ($patch === []) {
    return $doc;  // sem efeito; versão NÃO incrementada
}
```

---

## ETag e If-Match para bloqueio otimista

### Formato do ETag

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // ex.: "doc-42-7"
}
```

Retorne `ETag` em todas as respostas GET/PATCH/PUT:

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### Detecção de conflito

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match ausente**: atualização otimista sem verificação de conflito (última escrita vence).
**If-Match presente e correspondente**: atualização concorrente segura.
**If-Match presente mas desatualizado**: 412 — cliente deve re-buscar e retentar.

### Incremento de versão em SQL

Use o banco de dados para incrementar atomicamente a versão:

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

A cláusula `WHERE version = ?` verifica duas vezes o bloqueio otimista no nível do BD,
prevenindo uma escrita concorrente de se infiltrar entre nossa leitura e escrita.

---

## Integração com V.php

FT178 é o primeiro FT a usar `Nene2\Validation\V` como utilitário compartilhado:

```php
// Parâmetros de query
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// Header de auth
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// Campos string (com limites de comprimento explícitos)
$title = V::str($body['title'] ?? null, 200);

// Validação de enum
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### A armadilha `?? ''` para campos de corpo opcionais

```php
// ❌ ERRADO — ignora o retorno null de V::str para entrada com comprimento excessivo
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ CORRETO — validar quando presente, padrão quando ausente
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` retorna `null` porque `null` não é uma string.  
`V::str(string_muito_longa, 10000)` também retorna `null`.  
Usar `?? ''` colapsa ambos os casos em string vazia — silenciosamente aceitando a entrada com comprimento excessivo.

---

## Extração de parâmetros de rota

O Router do NENE2 armazena parâmetros de caminho no atributo `nene2.route.parameters`,
não como atributos de requisição individuais:

```php
// ❌ ERRADO
$id = $request->getAttribute('id');  // sempre null para parâmetros de caminho

// ✅ CORRETO
$id = Router::param($request, 'id');  // lê de nene2.route.parameters
```

---

## Checklist de ataques (ATK-01 a ATK-12)

| # | Teste | Expectativa |
|---|------|-------------|
| ATK-01 | PATCH `{"id": 999}` | 422 — campo imutável |
| ATK-02 | PATCH `{"owner_id": 99}` | 422 — campo imutável |
| ATK-03 | PATCH `{"version": 999}` | 422 — campo imutável |
| ATK-04 | PATCH `{"title": 42}` (confusão de tipo) | 422 — V::str rejeita não-string |
| ATK-05 | PATCH por não-proprietário | 404 — proteção IDOR |
| ATK-06 | If-Match com ETag desatualizado | 412 — conflito de bloqueio otimista |
| ATK-07 | PUT com title obrigatório ausente | 422 |
| ATK-08 | PATCH vazio `{}` | 200 — sem efeito válido (RFC 7396 §3) |
| ATK-09 | PATCH `{"status": null}` | 200 — resetar para padrão `draft` |
| ATK-10 | PATCH `{"status": 2}` (confusão de tipo) | 422 — V::enum rejeita não-string |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200 — chave desconhecida ignorada, sem crash |
| ATK-12 | `?limit=999999`, `?page=-1`, overflow de 20 dígitos | 422 — guardas V::queryInt |
