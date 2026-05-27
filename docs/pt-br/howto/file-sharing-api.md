# Como Fazer: API de Compartilhamento de Arquivos

> **Referência FT**: FT303 (`NENE2-FT/filelog`) — API de compartilhamento de arquivos: arquivos privados retornam 404 (não 403) para não-proprietários, delete/mudança de visibilidade apenas para proprietário, níveis de permissão view-share vs edit-share, `user_id` no body ignorado (propriedade do header), limite de 255 caracteres no nome, `is_int()` estrito para size, VULN-A〜L todos SAFE, 59 testes / 82 asserções PASS.

Este guia mostra como construir uma API de metadados de arquivo onde usuários possuem arquivos, controlam visibilidade e compartilham acesso com outros usuários em nível view ou edit.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

Compartilhamento em dois níveis: `can_edit = 0` (somente visualização) e `can_edit = 1` (acesso de edição). `UNIQUE(file_id, shared_with_user_id)` previne entradas de compartilhamento duplicadas.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/files` | `X-User-Id` | Fazer upload de metadados do arquivo |
| `GET` | `/files` | `X-User-Id` | Listar arquivos próprios |
| `GET` | `/files/{fileId}` | `X-User-Id` | Obter arquivo (verificação de visibilidade) |
| `PUT` | `/files/{fileId}` | `X-User-Id` | Atualizar arquivo (proprietário ou edit-share) |
| `DELETE` | `/files/{fileId}` | `X-User-Id` | Deletar arquivo (apenas proprietário) |
| `POST` | `/files/{fileId}/shares` | `X-User-Id` (proprietário) | Adicionar compartilhamento |
| `DELETE` | `/files/{fileId}/shares/{userId}` | `X-User-Id` (proprietário) | Remover compartilhamento |

## Arquivo Privado → 404 (Não 403)

```php
// Não-proprietário não pode ver arquivos privados — 404 oculta a existência
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

Arquivos privados retornam 404 para não-proprietários e não-compartilhados. Retornar 403 revelaria que o arquivo existe. Arquivos públicos retornam 200 para todos os usuários autenticados.

## Propriedade do Header — Ignorar user_id do Body

```php
$userId = $this->requireUserId($request);
// ... validação ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

O `user_id` do arquivo é sempre obtido do header `X-User-Id`. Qualquer `user_id` no corpo da requisição é silenciosamente ignorado. Isso previne ataques de injeção de propriedade (VULN-E).

## View-Share vs Edit-Share — Dois Níveis

```php
// Proprietário sempre pode editar
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **Proprietário**: todas as operações (leitura, escrita, delete, gerenciamento de compartilhamentos, visibilidade)
- **Edit-share** (`can_edit=1`): pode atualizar nome/tamanho/mime/descrição — mas NÃO a visibilidade
- **View-share** (`can_edit=0`): somente leitura — qualquer tentativa de escrita → 403

Apenas proprietários podem alterar `visibility`:

```php
// Apenas o proprietário pode alterar visibility
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // silenciosamente ignora a requisição
}
```

## Validação Estrita de Entrada

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size`: `is_int()` rejeita floats como `1.5` (VULN-I)
- `name`: máximo 255 caracteres — previne crash por entrada excessiva (VULN-H)
- `visibility`: `in_array($value, ['private', 'public'], true)` allowlist estrita

## Remoção de Compartilhamento — Apenas Proprietário

```php
// Apenas o proprietário do arquivo pode remover compartilhamentos
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

O usuário com quem foi compartilhado não pode remover a si mesmo da lista de compartilhamentos — apenas o proprietário pode gerenciar compartilhamentos. Não-proprietários recebem 404 (não 403) para ocultar a existência do arquivo (VULN-F).

## Validação do ID de Usuário — Rejeitar Zero e Negativos

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` e `X-User-Id: -1` retornam 401 (VULN-L). Apenas inteiros positivos são IDs de usuário válidos.

---

## Avaliação de Vulnerabilidades

### V-01 — IDOR: arquivo privado acessível por outro usuário ✅ SAFE

**Risco**: Usuário B lê arquivo privado do Usuário A.
**Resultado**: SAFE — arquivos privados retornam 404 para não-proprietários sem entrada de compartilhamento.

---

### V-02 — IDOR: deletar arquivo de outro usuário ✅ SAFE

**Risco**: Usuário B deleta arquivo do Usuário A.
**Resultado**: SAFE — delete verifica propriedade; não-proprietário recebe 404. Arquivo ainda existe após tentativa falha.

---

### V-03 — IDOR: atualizar arquivo de outro usuário ✅ SAFE

**Risco**: Usuário B atualiza nome/metadados do arquivo do Usuário A.
**Resultado**: SAFE — atualização verifica propriedade; não-proprietário sem edit-share recebe 404.

---

### V-04 — Escalação de privilégio: view-share tenta editar ✅ SAFE

**Risco**: Usuário com compartilhamento somente-visualização chama PUT para modificar o arquivo.
**Resultado**: SAFE — verificação de edição requer `can_edit = 1`; view-share retorna 403.

---

### V-05 — Injeção de propriedade: user_id no corpo da requisição ✅ SAFE

**Risco**: `{ "user_id": 99, "name": "..." }` atribui arquivo ao usuário 99.
**Resultado**: SAFE — `user_id` do body é silenciosamente ignorado; propriedade sempre vem do header `X-User-Id`.

---

### V-06 — Remoção de compartilhamento por não-proprietário ✅ SAFE

**Risco**: Usuário compartilhado remove a si mesmo da lista de compartilhamentos.
**Resultado**: SAFE — endpoint de delete de compartilhamento verifica propriedade do arquivo; não-proprietário retorna 404.

---

### V-07 — SQL injection no campo name ✅ SAFE

**Risco**: `"name": "test'; DROP TABLE files; --"` destrói dados.
**Resultado**: SAFE — queries parametrizadas armazenam a string de injeção como dado literal. Tabela files intacta.

---

### V-08 — Nome excessivamente longo causa crash ✅ SAFE

**Risco**: Nome de 300 caracteres causa erro no BD ou exaustão de memória.
**Resultado**: SAFE — validação `strlen($name) > 255` retorna 422 antes de inserir.

---

### V-09 — Confusão de tipo float em size ✅ SAFE

**Risco**: `"size": 1.5` passa na validação e corrompe rastreamento de tamanho.
**Resultado**: SAFE — `is_int($size)` rejeita floats → 422.

---

### V-10 — Edit-share escalona visibility para público ✅ SAFE

**Risco**: Usuário edit-share define `"visibility": "public"` para expor arquivo privado.
**Resultado**: SAFE — alterações de visibility são apenas para proprietário; campo visibility do edit-share no body do PUT é silenciosamente ignorado.

---

### V-11 — Divulgação de existência de arquivo privado via 403 ✅ SAFE

**Risco**: Resposta 403 revela que o arquivo existe para usuários não autorizados.
**Resultado**: SAFE — não-proprietários recebem 404, não 403. Existência do arquivo não é divulgada.

---

### V-12 — Bypass de autenticação via X-User-Id: 0 ou negativo ✅ SAFE

**Risco**: `X-User-Id: 0` ou `X-User-Id: -1` bypassa verificação de usuário.
**Resultado**: SAFE — verificação `ctype_digit()` + `$userId <= 0` retorna 401 para valores zero e negativos.

---

### Resumo VULN

| ID | Vulnerabilidade | Resultado |
|----|----------------|-----------|
| V-01 | IDOR: acesso a arquivo privado | ✅ SAFE |
| V-02 | IDOR: deletar arquivo de outro usuário | ✅ SAFE |
| V-03 | IDOR: atualizar arquivo de outro usuário | ✅ SAFE |
| V-04 | Escalação de privilégio view-share | ✅ SAFE |
| V-05 | Injeção de propriedade via body | ✅ SAFE |
| V-06 | Remoção de compartilhamento por não-proprietário | ✅ SAFE |
| V-07 | SQL injection em name | ✅ SAFE |
| V-08 | Crash por nome excessivamente longo | ✅ SAFE |
| V-09 | Confusão de tipo float em size | ✅ SAFE |
| V-10 | Escalação de visibility pelo edit-share | ✅ SAFE |
| V-11 | Divulgação de existência de arquivo privado | ✅ SAFE |
| V-12 | Bypass de autenticação por ID de usuário inválido | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Padrão 404 para arquivo privado, propriedade apenas pelo header, permissões de compartilhamento em dois níveis, validação de tipo estrita e visibilidade apenas para proprietário previnem todos os vetores de IDOR e escalação de privilégio.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 403 para arquivo privado a não-proprietário | Revela existência do arquivo para usuários não autorizados |
| Aceitar `user_id` do corpo da requisição para propriedade | Qualquer usuário autenticado reivindica propriedade de qualquer arquivo |
| Permitir que view-share chame PUT | Visualizadores compartilhados podem modificar metadados do arquivo |
| Permitir que edit-share altere visibility | Editores compartilhados expõem arquivos privados ao público |
| Permitir que usuário compartilhado remova seu próprio compartilhamento | Usuários podem revogar o gerenciamento de acesso do proprietário |
| Aceitar `size: 1.5` (float) | Confusão de tipo; tamanhos de arquivo não inteiros corrompem rastreamento de tamanho |
| Sem limite de comprimento para `name` | Nomes de arquivo longos podem causar overflow de coluna no BD ou problemas de memória |
| `X-User-Id: 0` aceito como válido | User ID 0 pode corresponder a linhas não inicializadas ou bypass de verificações de propriedade |
| `ctype_digit()` sem verificação `> 0` | `"0"` passa `ctype_digit` mas não é um user ID válido |
