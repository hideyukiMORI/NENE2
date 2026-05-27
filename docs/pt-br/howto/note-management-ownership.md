# Como Fazer: Gerenciamento de Notas com Propriedade

> **Referência FT**: FT240 (`NENE2-FT/noteslog`) — API de Gerenciamento de Notas
> **ATK**: FT240 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API de gerenciamento de notas com operações com escopo de proprietário, identificação via header
`X-Auth-User`, prevenção de IDOR via `WHERE id = ? AND owner_id = ?`, e
atualizações de merge de campos que preservam campos não especificados.

---

## Rotas

| Método   | Caminho          | Descrição                                               |
|----------|------------------|---------------------------------------------------------|
| `POST`   | `/notes`         | Criar uma nota (requer header `X-Auth-User`)            |
| `GET`    | `/notes`         | Listar notas de propriedade do chamador                 |
| `GET`    | `/notes/{id}`    | Obter uma única nota (404 se não encontrada ou não proprietário) |
| `PUT`    | `/notes/{id}`    | Atualizar uma nota (merge de campos: campos omitidos são mantidos) |
| `DELETE` | `/notes/{id}`    | Excluir uma nota (404 se não encontrada ou não proprietário) |

---

## Identificação via header `X-Auth-User`

A API usa um header string `X-Auth-User` mínimo como identidade do chamador:

```php
private function resolveAuthUser(ServerRequestInterface $request): ?string
{
    $userId = trim($request->getHeaderLine('X-Auth-User'));

    return $userId !== '' ? $userId : null;
}
```

`trim()` remove espaços em branco no início/fim. Header vazio após trim → `null` →
`401 Unauthorized`. Qualquer string não-vazia é aceita como ID de usuário válido — não há
verificação de token.

Isso é intencionalmente fraco para fins de demonstração. Em produção, substitua por claims
JWT verificadas ou sessões com suporte de cookie de sessão.

---

## Prevenção de IDOR: `WHERE id = ? AND owner_id = ?`

Toda operação que toca uma nota específica inclui `owner_id` na query:

```php
/**
 * Retorna a nota apenas se ela pertencer ao proprietário fornecido.
 * Retorna null tanto para "não encontrado" quanto para "proprietário errado" — chamadores retornam 404 em ambos os casos
 * para prevenir vazamento de informações de IDOR (não expor se um recurso existe).
 */
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    $row = $this->db->fetchOne(
        'SELECT * FROM notes WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

O método retorna `null` tanto para "não encontrado" quanto para "proprietário errado". O controller usa
a mesma resposta `404 Not Found` em ambos os casos:

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 não 403: não revelar se o recurso existe (prevenção de IDOR)
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

Retornar `403 Forbidden` confirmaria que o recurso existe — a abordagem `404`
previne ataques de enumeração. Um chamador não aprende nada sobre as notas de outros usuários.

---

## Atualização por merge de campos

`PUT /notes/{id}` mantém os valores existentes para campos omitidos do corpo da requisição:

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

Se apenas `title` for fornecido, `body` mantém seu valor atual — e vice-versa. Isso
difere de uma substituição completa (semântica `PUT`) — comporta-se mais próximo de `PATCH`. Para
semântica `PUT` estrita, exija ambos os campos e retorne `422` se algum estiver ausente.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

`body` tem padrão `''` — sem coluna anulável para o corpo do texto. `owner_id` é uma
string livre (o valor de `X-Auth-User`); sem chave estrangeira para uma tabela de usuários.

---

## ATK — Teste de ataque com mentalidade de cracker (FT240)

### ATK-01 — `X-Auth-User` é trivialmente falsificável

**Ataque**: Personificar outro usuário enviando seu ID de usuário no header.

```bash
curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: alice'

curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: bob'
```

**Observado**: Cada requisição retorna notas de propriedade do ID de usuário no header. Qualquer
chamador pode personificar qualquer usuário conhecendo ou adivinhando sua string de ID.

**Veredicto**: **EXPOSED** — o header não carrega prova criptográfica de identidade. Use
tokens JWT assinados ou cookies de sessão para autenticação em produção.

---

### ATK-02 — Injeção de newline em `X-Auth-User`

**Ataque**: Incorporar caracteres de injeção de header HTTP (CR/LF) no valor do header.

```
X-Auth-User: alice\r\nX-Injected: evil
```

**Observado**: PSR-7 (Nyholm) retira ou rejeita caracteres de header inválidos. O valor do header
é uma string simples — injeção CRLF na camada HTTP é tratada pelo servidor
(Swoole, Apache, Nginx) antes de chegar à aplicação. `trim()` remove espaços em branco no início/
fim mas não adiciona uma defesa adicional contra chars de controle embutidos.

**Veredicto**: **BLOCKED** na prática — servidores HTTP rejeitam headers malformados antes
de chegarem à camada de aplicação.

---

### ATK-03 — IDOR: ler nota de outro usuário

**Ataque**: Adivinhar ou enumerar IDs de notas pertencentes a outro usuário.

```bash
curl -s http://localhost:8080/notes/1 -H 'X-Auth-User: bob'
# Nota 1 foi criada por alice
```

**Observado**: `findByIdAndOwner(1, 'bob')` não encontra linha correspondendo a `id = 1 AND owner_id = 'bob'`
→ retorna `null` → `404 Not Found`. Bob não consegue determinar que a nota 1 existe.

**Veredicto**: **BLOCKED** — query com escopo de propriedade + 404 previne IDOR.

---

### ATK-04 — SQL injection via título ou corpo

**Ataque**: Incorporar metacaracteres SQL no corpo da requisição.

```json
{"title": "'; DROP TABLE notes; --", "body": "\" OR \"1\"=\"1"}
```

**Observado**: Os valores são armazenados como valores parametrizados `?` — sem
concatenação de string com SQL. Os payloads de injeção são armazenados como texto literal.

**Veredicto**: **BLOCKED** — queries parametrizadas previnem toda SQL injection via campos do corpo.

---

### ATK-05 — Título vazio

**Ataque**: Criar uma nota com título apenas com espaços ou vazio.

```json
{"title": "   "}
{"title": ""}
```

**Observado**: `trim($body['title'])` reduz ambos para `""`. A verificação `title === ''`
dispara → `422 Unprocessable Entity`.

**Veredicto**: **BLOCKED** — `trim()` + verificação de string vazia lida com entrada apenas com espaços.

---

### ATK-06 — Header `X-Auth-User` ausente

**Ataque**: Enviar uma requisição sem o header `X-Auth-User`.

```bash
curl -s http://localhost:8080/notes
```

**Observado**: `getHeaderLine('X-Auth-User')` retorna `""`. Após `trim()` ainda é
`""`. `$userId !== ''` falha → `resolveAuthUser()` retorna `null` → `401 Unauthorized`
com uma resposta Problem Details estruturada.

**Veredicto**: **BLOCKED** — header ausente é tratado como não autenticado.

---

### ATK-07 — Personificação via valor arbitrário de `X-Auth-User`

**Ataque**: Criar notas como uma string de ID de usuário privilegiado.

```bash
# Assumindo que 'admin' é um usuário especial
curl -s -X POST http://localhost:8080/notes \
  -H 'X-Auth-User: admin' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Admin note"}'
```

**Observado**: `201 Created` — a nota é criada com `owner_id = 'admin'`. Qualquer
string é aceita como identidade do chamador.

**Veredicto**: **EXPOSED** (mesma raiz que ATK-01). Sem autenticação criptográfica, não há
forma de distinguir um admin real de um atacante que conhece a string `"admin"`.

---

### ATK-08 — Payload XSS em título ou corpo

**Ataque**: Armazenar uma tag de script.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observado**: O conteúdo é armazenado como está e retornado verbatim em JSON. A API JSON não
codifica HTML na saída.

**Veredicto**: **ACCEPTED BY DESIGN** — APIs JSON retornam conteúdo bruto. A camada de renderização
deve sanitizar antes de inserir em HTML. Documente esta expectativa para consumidores da API.

---

### ATK-09 — Atualização parcial perde campos não intencionais

**Ataque**: Tentar sobrescrever `body` para vazio omitindo-o da atualização.

```json
{"title": "New title"}
// Chamador espera que body seja limpo; na verdade ele é preservado
```

**Observado**: A lógica de merge de campos preserva `body` se ausente da requisição:
`$noteBody = isset($body['body']) ? $body['body'] : $note->body`. O corpo
fica inalterado — isso corresponde à intenção para uma API de merge-update mas pode surpreender chamadores
esperando substituição completa (semântica `PUT`).

**Veredicto**: **ACCEPTED BY DESIGN** — comportamento de merge-update documentado. Se semântica
`PUT` estrita for desejada, exija todos os campos.

---

### ATK-10 — ID de nota não-numérico

**Ataque**: Passar uma string ou float como `{id}`.

```
GET /notes/abc
GET /notes/1.5
```

**Observado**: `(int) 'abc'` = 0, `(int) '1.5'` = 1.
- `abc` → `findByIdAndOwner(0, ...)` → sem linha → `404 Not Found`.
- `1.5` → `findByIdAndOwner(1, ...)` → se nota 1 for de propriedade do chamador, retorna ela.

**Veredicto**: **PARTIALLY BLOCKED** — strings não-numéricas mapeiam para 404. Floats são
truncados silenciosamente. Adicione guarda `ctype_digit()` para validação estrita.

---

### ATK-11 — Excluir nota inexistente ou sem propriedade

**Ataque**: DELETE em um ID de nota que não existe ou pertence a outro usuário.

```bash
curl -s -X DELETE http://localhost:8080/notes/99999 -H 'X-Auth-User: alice'
curl -s -X DELETE http://localhost:8080/notes/1    -H 'X-Auth-User: eve'
# (nota 1 pertence a alice)
```

**Observado**: O repositório executa `DELETE FROM notes WHERE id = ? AND owner_id = ?`.
Se nenhuma linha corresponde (inexistente ou proprietário errado), `$deleted = false` → `404 Not Found`.
A tentativa de Eve retorna o mesmo 404 que uma nota inexistente.

**Veredicto**: **BLOCKED** — DELETE com escopo de proprietário + resposta 404 previne exclusão entre usuários.

---

### ATK-12 — `X-Auth-User` apenas com espaços em branco

**Ataque**: Enviar um header contendo apenas espaços ou tabs.

```
X-Auth-User:    
X-Auth-User: \t
```

**Observado**: `trim('   ')` = `""` → `$userId !== ''` falha → `401 Unauthorized`.

**Veredicto**: **BLOCKED** — `trim()` normaliza headers apenas com espaços para vazio.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|----------------|-----------|
| ATK-01 | X-Auth-User é trivialmente falsificável | EXPOSED |
| ATK-02 | Injeção de newline em X-Auth-User | BLOCKED |
| ATK-03 | IDOR: ler nota de outro usuário | BLOCKED |
| ATK-04 | SQL injection via título/corpo | BLOCKED |
| ATK-05 | Título vazio | BLOCKED |
| ATK-06 | Header X-Auth-User ausente | BLOCKED |
| ATK-07 | Personificação via valor arbitrário de header | EXPOSED |
| ATK-08 | XSS em título/corpo | ACCEPTED BY DESIGN |
| ATK-09 | Surpresa de merge de campos em atualização parcial | ACCEPTED BY DESIGN |
| ATK-10 | ID de nota não-numérico | PARTIALLY BLOCKED |
| ATK-11 | Excluir nota sem propriedade/inexistente | BLOCKED |
| ATK-12 | X-Auth-User apenas com espaços em branco | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-01 / ATK-07** — Substituir `X-Auth-User` por JWT assinado ou verificação de sessão
2. **ATK-10** — Adicionar guarda `ctype_digit()` para parâmetros de caminho de ID

---

## Howtos relacionados

- [`use-bearer-auth.md`](use-bearer-auth.md) — autenticação com token Bearer assinado
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — padrões de prevenção de IDOR
- [`jwt-authentication.md`](jwt-authentication.md) — verificação de JWT para identificação de usuário
- [`scheduled-reminders.md`](scheduled-reminders.md) — padrão de validação de header V::userId()
