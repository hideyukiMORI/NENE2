# Como Fazer: Gerenciamento de Slug de URL com Histórico

> **Referência FT**: FT339 (`NENE2-FT/sluglog`) — Slugs gerados automaticamente a partir de títulos, contador de colisão, histórico de slugs para redirecionamentos 301 de slugs antigos, substituição explícita de slug, avaliação de vulnerabilidade, 17 testes / 50+ assertivas PASS.

Este guia mostra como gerar slugs de URL limpos a partir de títulos de conteúdo, tratar colisões com sufixos sequenciais, preservar slugs antigos em uma tabela de histórico para redirecionamentos permanentes e prevenir vetores de ataque comuns.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/articles` | Criar artigo (slug automático a partir do título) |
| `PUT`  | `/articles/{id}` | Atualizar artigo (slug regenerado ao alterar o título) |
| `GET`  | `/articles/by-slug/{slug}` | Obter por slug atual ou antigo |
| `GET`  | `/articles/{id}/slug-history` | Listar histórico de slugs |

## Geração de Slug

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

Regras:
1. Tudo em minúsculas
2. Substituir caracteres não alfanuméricos por `-`
3. Colapsar hífens consecutivos
4. Remover hífens iniciais/finais
5. Retornar `"untitled"` se o resultado estiver vazio

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### Resolução de Colisão

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` é uma callback de consulta ao banco: `fn(string $s): bool => (bool) $repo->findBySlug($s)`.

## Criar Artigo

```php
POST /articles
{"title": "Meu Primeiro Post", "body": "Conteúdo aqui."}
→ 201
{
  "id": 1,
  "title": "Meu Primeiro Post",
  "slug": "meu-primeiro-post",
  "body": "...",
  "created_at": "..."
}
```

## Atualizar Artigo

```php
PUT /articles/1
{"title": "Novo Título", "body": "Conteúdo atualizado."}
→ 200  {"slug": "novo-titulo", ...}
```

Quando o título muda, o novo slug é derivado e o slug antigo é salvo em `slug_history`.

```php
// Mesmo título — slug inalterado, sem entrada no histórico
PUT /articles/1  {"title": "Novo Título", "body": "Corpo diferente."}
→ 200  {"slug": "novo-titulo"}  // mesmo slug

// Substituição explícita de slug
PUT /articles/1  {"title": "Novo Título", "body": "Corpo.", "slug": "url-personalizada-aqui"}
→ 200  {"slug": "url-personalizada-aqui"}

// Colisão na atualização — resolvida automaticamente
// (se "popular" existe, renomeia para "popular-2")
PUT /articles/2  {"title": "Popular", "body": "Corpo."}
→ 200  {"slug": "popular-2"}

// Artigo desconhecido
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## Obter por Slug

```php
// Slug atual → 200
GET /articles/by-slug/novo-titulo
→ 200  {"id": 1, "slug": "novo-titulo", "title": "Novo Título", ...}

// Slug antigo → redirecionamento 301
GET /articles/by-slug/meu-primeiro-post
→ 301
{
  "redirect": true,
  "canonical_slug": "novo-titulo"
}

// Desconhecido → 404
GET /articles/by-slug/nao-existe
→ 404
```

Respostas 301 informam crawlers/clientes para atualizar seus links para o slug canônico.

## Histórico de Slug

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "novo-titulo",
  "slug_history": [
    {"old_slug": "meu-primeiro-post", "created_at": "..."}
  ]
}

// Novo artigo — histórico vazio
{"current_slug": "recente", "slug_history": []}

// Artigo desconhecido → 404
GET /articles/9999/slug-history → 404
```

Entradas no histórico se acumulam apenas quando o slug realmente muda. Atualizar o corpo sem alterar o título não toca o histórico.

---

## Avaliação de Vulnerabilidade

### V-01 — Path Traversal via Slug ✅ SAFE

**Risco**: Atacante envia `GET /articles/by-slug/../../../etc/passwd` para traversar diretórios do servidor.
**Descoberta**: SAFE — Buscas de slug são SQL `WHERE slug = ?` com parâmetro vinculado. O segmento de caminho nunca é interpretado como caminho de sistema de arquivos. O roteamento analisa o caminho antes que ele chegue ao controlador; `../` em um caminho de URL é canonicalizado pela camada HTTP.

---

### V-02 — Injeção SQL via Slug na URL ✅ SAFE

**Risco**: `GET /articles/by-slug/' OR '1'='1` vaza todos os artigos.
**Descoberta**: SAFE — Slug é passado como parâmetro vinculado em `WHERE slug = ?`. Injeção SQL é impossível independentemente do valor do slug.

---

### V-03 — Enumeração de Slug (Descoberta por Força Bruta) ⚠️ EXPOSED

**Risco**: Atacante itera slugs comuns (`/articles/by-slug/admin`, `/articles/by-slug/documento-secreto`) para descobrir artigos privados.
**Descoberta**: EXPOSED — Slugs são derivações previsíveis de títulos legíveis por humanos. Nenhum rate limiting ou autenticação é aplicado em `GET /articles/by-slug/{slug}`. Mitigação: exigir autenticação para conteúdo privado; adicionar rate limiting por IP; considerar IDs opacos para recursos sensíveis.

---

### V-04 — IDOR no Histórico de Slug ✅ SAFE

**Risco**: Atacante chama `GET /articles/{id}/slug-history` para o artigo de outro usuário para descobrir títulos anteriores.
**Descoberta**: SAFE — Histórico de slug é metadado público. Se artigos são públicos, seu histórico também é. Se artigos exigem autorização, aplique a mesma verificação de auth ao endpoint `/slug-history` de forma consistente.

---

### V-05 — Loop de Redirecionamento Infinito via Histórico de Slug ✅ SAFE

**Risco**: Artigo A renomeia para slug B; artigo B renomeia para slug A — `GET /by-slug/a` → redirecionamento para B → redirecionamento para A (loop infinito).
**Descoberta**: SAFE — A implementação busca o slug **atual** em `articles.slug`, depois verifica `slug_history` apenas para slugs antigos. Uma resposta 301 sempre aponta para o atual canônico. Clientes seguindo redirecionamentos alcançam o canônico em um salto.

---

### V-06 — Abuso de Colisão de Slug (Exaustão do Contador Sequencial) ⚠️ EXPOSED

**Risco**: Atacante cria milhares de artigos intitulados "popular" para reservar "popular-2" até "popular-9999", depois os exclui — ou para forçar varredura de contador cara.
**Descoberta**: EXPOSED — Sem rate limiting na criação de artigos. A varredura do contador `makeUnique` é O(n) queries no banco. Mitigação: limitar taxa de POST /articles por usuário; limitar o contador de slug em um valor razoável (ex.: 99); usar sufixo aleatório após o limite.

---

### V-07 — Injeção de Slug Explícito (Sobrescrever Slug de Outro Artigo) ✅ SAFE

**Risco**: Atacante usa `PUT /articles/2  {"slug": "popular"}` onde "popular" pertence ao artigo 1.
**Descoberta**: SAFE — `articles.slug` tem uma restrição `UNIQUE`. Tentar definir um slug já reivindicado por outro artigo dispara uma violação de restrição do banco, traduzida para 409 Conflict.

---

### V-08 — Ataque Unicode/Homógrafo em Slug ⚠️ EXPOSED

**Risco**: Atacante cria um artigo com título Unicode que normaliza para os mesmos bytes que um slug ASCII existente (ex.: `café` → `caf-`) para criar uma URL visualmente confusa.
**Descoberta**: EXPOSED — `SlugHelper::fromTitle()` usa `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`. Caracteres não-ASCII são substituídos por `-`, o que pode causar colisões inesperadas ou slugs vazios. Mitigação: normalizar Unicode para transliteração ASCII (ex.: `iconv`) antes da geração de slug; tratar todos os não-ASCII como `-` após a normalização.

---

### V-09 — XSS via Título Armazenado no Slug ✅ SAFE

**Risco**: Título `<script>alert(1)</script>` produz slug `script-alert-1-script` — saída alfanumérica segura.
**Descoberta**: SAFE — `SlugHelper::fromTitle()` remove todos os caracteres não alfanuméricos para `-`. A saída do slug é sempre `[a-z0-9-]`, tornando a injeção HTML impossível através do slug.

---

### V-10 — Busca de Slug Antigo Revela Conteúdo Renomeado ⚠️ EXPOSED

**Risco**: Artigo renomeado de "plano-secreto-v1" para "anuncio-publico"; atacante usa slug antigo para descobrir o título original via `canonical_slug` na resposta de redirecionamento.
**Descoberta**: EXPOSED — A resposta 301 expõe o novo slug canônico, o que pode revelar o conteúdo renomeado. O endpoint de histórico de slug também revela todos os nomes antigos. Para renomeações sensíveis, use tombstone em slugs antigos sem revelar a nova localização; ou use slugs opacos.

---

### Resumo VULN

| ID | Vulnerabilidade | Descoberta |
|----|-----------------|-----------|
| V-01 | Path traversal via slug | ✅ SAFE |
| V-02 | Injeção SQL via slug | ✅ SAFE |
| V-03 | Enumeração de slug | ⚠️ EXPOSED |
| V-04 | IDOR no histórico de slug | ✅ SAFE |
| V-05 | Loop de redirecionamento infinito | ✅ SAFE |
| V-06 | Exaustão do contador de colisão | ⚠️ EXPOSED |
| V-07 | Sobrescrita explícita de slug | ✅ SAFE |
| V-08 | Ataque homógrafo Unicode | ⚠️ EXPOSED |
| V-09 | XSS via título | ✅ SAFE |
| V-10 | Slug antigo revela conteúdo renomeado | ⚠️ EXPOSED |

**6 SAFE, 4 EXPOSED** — Limitar taxa na criação de artigos; adicionar autenticação para conteúdo privado; normalizar Unicode antes da geração de slug; considerar histórico de slug somente tombstone para renomeações sensíveis.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Interpolar slug diretamente no SQL | Injeção SQL via parâmetro de caminho do slug |
| Hard-delete no histórico de slug ao excluir artigo | URLs antigas retornam 404 em vez de 301; SEO e link rot |
| Sem restrição `UNIQUE` em `articles.slug` | Inserts concorrentes criam slugs duplicados |
| Retornar slug inalterado na atualização de título | Drift de slug — URL não reflete mais o conteúdo |
| Sem limite no contador em `makeUnique` | Atacante esgota o contador via criação em massa |
| Usar `!==` para comparar slugs existentes | Surpresas de coerção de tipo; sempre use `===` para comparação de slug |
