# Como Fazer: Gerenciamento de Notas com Tags

## Visão Geral

Este guia cobre a construção de uma API de gerenciamento de notas com tags usando NENE2. As funcionalidades incluem isolamento por usuário, filtragem baseada em tags, pesquisa de palavras-chave em texto completo e CRUD com propriedade aplicada.

**Implementação de referência**: `../NENE2-FT/notelog/`

---

## Design do Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` remove tags automaticamente quando uma nota é excluída.

---

## Tabela de Rotas

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/notes` | Usuário | Criar uma nota |
| `GET` | `/notes` | Usuário | Listar notas próprias (opcional `?tag=` ou `?q=`) |
| `GET` | `/notes/{id}` | Usuário | Obter uma nota |
| `PUT` | `/notes/{id}` | Usuário | Atualizar campos da nota |
| `DELETE` | `/notes/{id}` | Usuário | Excluir uma nota |

---

## Filtragem por Tag

Filtrar por tag com `JOIN`:

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## Pesquisa por Palavra-chave

Pesquisa em texto completo em título e corpo usando `LIKE`:

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

O placeholder `:kw` é `'%' . $keyword . '%'`. Queries parametrizadas previnem SQL injection.

---

## Análise de Tags

Tags devem ser arrays de strings; normalizar para minúsculas:

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // rejeitar não-string → 422
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## Padrão IDOR / Propriedade

Todas as operações de leitura e escrita têm escopo para `user_id`. Retorne 404 (não 403) em leituras para evitar revelar existência; retorne 403 em escritas para que o usuário saiba que o recurso existe mas não tem permissão:

```php
// Leitura: 404 para prevenir divulgação de informação
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// Escrita: 403 quando o recurso existe mas não é de propriedade
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## Atualização Parcial (PUT)

Aceitar `null` para qualquer campo significa "sem alteração":

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

No repositório, atualizar apenas campos que não são nulos.

---

## Códigos de Status HTTP

| Situação | Status |
|----------|--------|
| Nota criada | 201 |
| Nota recuperada / listagem | 200 |
| Nota atualizada / excluída | 200 |
| Sem X-User-Id | 400 |
| Título vazio | 422 |
| Valores de tag não-string | 422 |
| Nota não encontrada (ou IDOR) | 404 |
| Atualizar/excluir nota de outro | 403 |
