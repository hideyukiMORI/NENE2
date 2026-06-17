# Como Fazer: API de Conteúdo Multilíngue

> **Referência FT**: FT232 (`NENE2-FT/i18nlog`) — API de Conteúdo Multilíngue
> **ATK**: FT232 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API de artigos multilíngue onde o conteúdo é armazenado como
traduções com chave de locale separadas do próprio registro do artigo. Suporta
validação de locale BCP 47, semântica upsert para traduções, fallback de locale para
negociação de conteúdo e estado publicar/rascunho por artigo.

---

## Rotas

| Método | Caminho                                    | Descrição                                        |
|--------|---------------------------------------------|--------------------------------------------------|
| `POST` | `/articles`                                 | Criar um artigo (rascunho ou publicado)          |
| `GET`  | `/articles`                                 | Listar artigos publicados (opcional `?locale=`)  |
| `GET`  | `/articles/{id}`                            | Obter um único artigo (opcional `?locale=`)      |
| `PUT`  | `/articles/{id}/translations/{locale}`      | Criar ou atualizar uma tradução (upsert)         |

---

## Criando um artigo

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` define o idioma de fallback quando um locale solicitado não está disponível.
`published` controla a visibilidade da listagem — apenas artigos publicados aparecem em `GET /articles`.

```php
$defaultLocale = isset($body['default_locale']) && is_string($body['default_locale'])
    ? trim($body['default_locale']) : 'en';
$published = isset($body['published']) && $body['published'] === true;

if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLocale)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'default_locale', 'code' => 'invalid',
                      'message' => 'default_locale must be a BCP 47 language tag (e.g. en, ja, fr-FR).']],
    ]);
}
```

`$body['published'] === true` (igualdade estrita) significa que JSON `true` define o flag —
qualquer outro valor (string `"true"`, inteiro `1`, omitido) deixa o artigo como rascunho.

---

## Validação de locale BCP 47

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

Aceita:
- Duas letras minúsculas: `en`, `ja`, `fr`, `de`
- Duas minúsculas + hífen + duas maiúsculas: `fr-FR`, `zh-TW`, `pt-BR`

Rejeita:
- Caso errado: `EN`, `en_US`, `En`
- Underscores: `en_US` (BCP 47 usa hífens)
- Subtags além da região: `zh-Hant-TW`
- Traversal de caminho: `../../etc/passwd`
- String vazia: `""`

Esta regex é suficiente para as formas comuns `language` e `language-REGION`. Para
suporte completo a BCP 47 (códigos de escrita, tags de variante) uma biblioteca dedicada é necessária.

---

## Upsert de uma tradução

`PUT /articles/{id}/translations/{locale}` cria a tradução se ela não existir
ou a atualiza se existir — idempotente com semântica de última escrita vence:

```php
public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): Translation
{
    $existing = $this->executor->fetchAll(
        'SELECT * FROM article_translations WHERE article_id = ? AND locale = ?',
        [$articleId, $locale],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE article_translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
            [$title, $body, $now, $articleId, $locale],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO article_translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
    }
    // ... buscar e retornar a linha
}
```

A constraint `UNIQUE(article_id, locale)` no schema atua como backstop; o
SELECT-then-INSERT/UPDATE no nível da aplicação evita resolução silenciosa de conflitos e
permite o retorno explícito da linha persistida.

A validação do corpo rejeita título ou corpo vazios:

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
$text  = isset($body['body'])  && is_string($body['body'])  ? trim($body['body'])  : '';

$errors = [];
if ($title === '') {
    $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'title is required.'];
}
if ($text === '') {
    $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
}
```

`trim()` antes da verificação de string vazia garante que strings apenas com espaços em branco também falhem na validação.

---

## Fallback de locale para negociação de conteúdo

Quando o chamador passa `?locale=fr`, a entidade `Article` busca o
locale solicitado e recai para `default_locale` se nenhuma tradução existir:

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}

public function toArray(?string $locale = null): array
{
    $translation = $locale !== null
        ? $this->getTranslationWithFallback($locale)
        : null;

    return [
        'id'             => $this->id,
        'default_locale' => $this->defaultLocale,
        'published'      => $this->published,
        'title'          => $translation?->title,    // null se nenhuma tradução armazenada
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // indica qual locale foi servido
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

O campo `locale` na resposta informa ao chamador qual locale foi realmente servido —
útil quando ocorreu fallback (`?locale=zh` → artigo serve tradução `en` porque
nenhuma tradução em chinês existe ainda).

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT    NOT NULL DEFAULT 'en',
    published      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale     TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(article_id, locale)
);
```

Escolhas principais de design:
- `published` é armazenado como `INTEGER` (booleano SQLite: 0/1); PHP lê via `(bool) $row['published']`.
- `UNIQUE(article_id, locale)` aplica no máximo uma tradução por locale por artigo.
- Sem validação de idioma no banco de dados — a camada de aplicação aplica o formato BCP 47.
- `article_translations.body` é texto simples; chamadores de API JSON são responsáveis por sanitizar antes de renderizar em HTML.

---

## ATK — Teste de ataque com mentalidade de cracker (FT232)

### ATK-01 — Sem autenticação em nenhum endpoint

**Ataque**: Criar ou modificar artigos sem nenhuma credencial.

```bash
curl -s -X POST http://localhost:8200/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**Observado**: `201 Created` — nenhum token necessário. Qualquer chamador pode criar, traduzir ou
publicar artigos.

**Veredicto**: **EXPOSED** (por design para demo FT232). Adicione autenticação e autorização
para produção. Proteja `POST /articles` e `PUT .../translations/{locale}` atrás de uma
role de escritor ou admin.

---

### ATK-02 — Traversal de caminho no parâmetro de caminho locale

**Ataque**: Usar strings de traversal de caminho ou metacaracteres de shell como parâmetro de caminho `{locale}`.

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**Observado**: A regex BCP 47 `/^[a-z]{2}(-[A-Z]{2})?$/` rejeita todas estas — nenhuma
corresponde a duas letras minúsculas (opcionalmente seguidas por hífen e duas letras maiúsculas).
Resposta: `422 Unprocessable Entity`.

**Veredicto**: **BLOCKED** — regex estrita ancorada com `^` e `$` rejeita sequências de traversal.

---

### ATK-03 — SQL injection via parâmetro de caminho locale

**Ataque**: Incorporar metacaracteres SQL no valor `{locale}`.

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**Observado**:
1. A regex BCP 47 rejeita imediatamente estas strings → `422` antes de qualquer SQL ser executado.
2. Mesmo que a regex fosse contornada, o locale é passado como valor parametrizado `?` — sem concatenação de string com SQL.

**Veredicto**: **BLOCKED** — camada dupla: allowlist de regex + queries parametrizadas.

---

### ATK-04 — IDOR: traduzir artigo de outro usuário

**Ataque**: Escrever uma tradução para um artigo que o atacante não criou.

```bash
# O atacante sabe que o artigo ID 1 foi criado por outro usuário
curl -s -X PUT http://localhost:8200/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**Observado**: `200 OK` — a tradução é aceita e sobrescreve qualquer tradução francesa existente.
Nenhuma verificação de propriedade existe.

**Veredicto**: **EXPOSED** — sem modelo de propriedade. Adicione uma coluna `created_by` e compare
com o chamador autenticado antes de permitir escritas.

---

### ATK-05 — Título ou corpo apenas com espaços em branco

**Ataque**: Enviar um título ou corpo que fica em branco após o trim.

```json
{"title": "   ", "body": "\t\n"}
```

**Observado**: `trim()` reduz ambos para strings vazias. Ambos os campos são adicionados a `$errors`.
Resposta: `422 Unprocessable Entity` com erros de campo estruturados.

**Veredicto**: **BLOCKED** — `trim()` antes da verificação de string vazia lida com entrada apenas com espaços.

---

### ATK-06 — Payload XSS em título ou corpo

**Ataque**: Armazenar uma tag de script em um campo de tradução.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observado**: O conteúdo é armazenado como está e retornado verbatim em JSON. A própria API
não codifica HTML na saída — é uma API JSON, não um renderizador HTML.

**Veredicto**: **ACCEPTED BY DESIGN** — APIs JSON retornam conteúdo bruto; a camada de renderização
(navegador, aplicativo móvel) é responsável pelo escape HTML. Documente isso claramente na
especificação da API para que consumidores não renderizem conteúdo não confiável sem sanitização.

---

### ATK-07 — Comprimento ilimitado de título ou corpo

**Ataque**: Enviar um título ou corpo de múltiplos megabytes.

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**Observado**: Nenhum limite de comprimento é aplicado — payloads muito grandes são armazenados e retornados.
Uso de memória e I/O escala com o tamanho do payload. SQLite `TEXT` não tem limite de tamanho prático.

**Veredicto**: **EXPOSED** — adicione uma verificação de `maxlength`:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
Também aplique um limite de tamanho de requisição no middleware para limitar o total de bytes do corpo antes de analisar.

---

### ATK-08 — Bypass de caso e separador BCP 47

**Ataque**: Tentar variantes semanticamente similares mas sintaticamente erradas.

```
PUT /articles/1/translations/EN        → código de idioma em maiúsculas
PUT /articles/1/translations/en_US     → separador underscore (estilo POSIX)
PUT /articles/1/translations/en-us     → região em minúsculas
PUT /articles/1/translations/EN-us     → caso misto
PUT /articles/1/translations/fra       → código ISO 639-2 de três letras
```

**Observado**: Todos rejeitados por `/^[a-z]{2}(-[A-Z]{2})?$/`:
- `EN` — falha em `[a-z]`
- `en_US` — `_` falha em `(-[A-Z]{2})?`
- `en-us` — `us` falha em `[A-Z]`
- `fra` — três chars falham em `{2}` exatamente

**Veredicto**: **BLOCKED** — a regex é precisa; apenas formas BCP 47 exatas `ll` ou `ll-RR` passam.

---

### ATK-09 — Tradução para artigo inexistente

**Ataque**: Alvejar um ID de artigo que não existe.

```bash
curl -s -X PUT http://localhost:8200/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**Observado**: `findById(99999)` retorna `null`. O handler retorna `404 Not Found`
antes de processar o corpo.

**Veredicto**: **BLOCKED** — a existência do artigo é verificada antes de a tradução ser escrita.

---

### ATK-10 — Manipulação de publicação sem autenticação

**Ataque**: Criar um artigo como publicado para contornar a revisão de rascunho.

```json
{"default_locale": "en", "published": true}
```

**Observado**: `201 Created` — `published: true` é aceito imediatamente. Nenhuma revisão de rascunho
ou gate de aprovação existe; qualquer chamador pode publicar.

**Veredicto**: **EXPOSED** (mesma raiz que ATK-01). Uma ação de publicação deve requerer
no mínimo uma role de escritor. Separe o flag `published` do payload de criação — requeira
uma ação explícita `POST /articles/{id}/publish` protegida por autorização.

---

### ATK-11 — `?locale=` com locale desconhecido faz fallback silenciosamente

**Ataque**: Solicitar um artigo com um locale que não tem tradução armazenada.

```
GET /articles/1?locale=zh-TW
```

**Observado**: `getTranslationWithFallback('zh-TW')` não encontra tradução em chinês e
recai para `default_locale` (por ex. `en`). O campo `locale` na resposta mostra
`en` — indicando que ocorreu fallback. Nenhum 404 ou erro é retornado.

**Veredicto**: **ACCEPTED BY DESIGN** — fallback silencioso é correto para entrega de conteúdo.
Chamadores podem detectar fallback comparando o locale solicitado com `locale` na
resposta. Se aplicação estrita de locale for necessária, adicione um parâmetro `?strict=1`.

---

### ATK-12 — ID de artigo não-numérico

**Ataque**: Passar uma string ou float como ID do artigo.

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**Observado**:
- `GET /articles/abc` → Router corresponde ao parâmetro `{id}`; `(int) 'abc'` = `0`.
  `findById(0)` retorna `null` → `404 Not Found`.
- `GET /articles/1.5` → `(int) '1.5'` = `1`. Se o artigo 1 existir, ele é retornado.
  Isto é um truncamento silencioso, não um erro.

**Veredicto**: **PARTIALLY BLOCKED** — strings não-numéricas resolvem para 0 e retornam 404.
Floats são truncados silenciosamente. Para validação estrita, adicione:
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|----------------|-----------|
| ATK-01 | Sem autenticação | EXPOSED |
| ATK-02 | Traversal de caminho em locale | BLOCKED |
| ATK-03 | SQL injection via locale | BLOCKED |
| ATK-04 | IDOR: traduzir artigo de outro | EXPOSED |
| ATK-05 | Título/corpo apenas com espaços em branco | BLOCKED |
| ATK-06 | XSS em título/corpo | ACCEPTED BY DESIGN |
| ATK-07 | Comprimento ilimitado de título/corpo | EXPOSED |
| ATK-08 | Bypass de caso/separador BCP 47 | BLOCKED |
| ATK-09 | Tradução para artigo inexistente | BLOCKED |
| ATK-10 | Publicar sem autenticação | EXPOSED |
| ATK-11 | `?locale=` desconhecido faz fallback silencioso | ACCEPTED BY DESIGN |
| ATK-12 | ID de artigo não-numérico | PARTIALLY BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-01 / ATK-04 / ATK-10** — Adicionar autenticação, verificações de propriedade e ação de publicação separada
2. **ATK-07** — Adicionar limites de comprimento para título e corpo
3. **ATK-12** — Adicionar guarda `ctype_digit()` para parâmetros de ID

---

## Howtos relacionados

- [`approval-workflow.md`](approval-workflow.md) — máquina de estados para revisão de conteúdo antes de publicar
- [`bulk-status-update.md`](bulk-status-update.md) — padrões de mutação em massa com sucesso parcial
- [`media-watchlist.md`](media-watchlist.md) — status backed por enum e campos opcionais anuláveis
