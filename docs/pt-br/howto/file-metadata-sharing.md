# Guia de Implementação de API de Gerenciamento e Compartilhamento de Metadados de Arquivo

## Visão Geral

Este guia explica como implementar uma API de gerenciamento de metadados de arquivos usando NENE2.
Não armazena arquivos reais, apenas gerencia metadados (nome, tamanho, tipo MIME, descrição, visibilidade),
e suporta compartilhamento entre usuários (permissões view/edit).

---

## Schema do BD

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

**Pontos de design**

- `visibility CHECK (visibility IN ('private', 'public'))` — restringe valores válidos no nível do BD
- `can_edit CHECK (can_edit IN (0, 1))` — booleano no SQLite é INTEGER 0/1
- `UNIQUE (file_id, shared_with_user_id)` — previne compartilhamento duplo para o mesmo usuário

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/files` | Listar arquivos acessíveis (próprios + compartilhados) |
| `POST` | `/files` | Criar metadados de arquivo |
| `GET` | `/files/{fileId}` | Obter arquivo (apenas proprietário, público ou compartilhado) |
| `PUT` | `/files/{fileId}` | Atualizar (proprietário ou compartilhado com edit) |
| `DELETE` | `/files/{fileId}` | Deletar (apenas proprietário) |
| `POST` | `/files/{fileId}/shares` | Compartilhar com usuário |
| `DELETE` | `/files/{fileId}/shares/{userId}` | Remover compartilhamento (apenas proprietário) |

---

## Design do Controle de Acesso

### 3 Níveis de Acesso

```
Proprietário (user_id = X-User-Id)
  → Todas as operações permitidas
  
Compartilhamento edit (file_shares.can_edit = 1)
  → GET / PUT permitidos
  → Não pode alterar visibility (apenas proprietário)
  → DELETE não permitido
  
Compartilhamento view (file_shares.can_edit = 0) ou arquivo público
  → Apenas GET permitido
```

### Ocultação de Existência (Prevenção de IDOR)

Arquivos privados de outros usuários retornam **404** (não 403).
403 implica "arquivo existe mas sem permissão de acesso", facilitando ataques de adivinhação de ID.

```php
if ((int) $file['user_id'] !== $userId) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->json->create(['error' => 'File not found'], 404); // 404, não 403
    }
}
```

---

## Query de Listagem de Arquivos Acessíveis

```php
return $this->db->fetchAll(
    'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
            f.visibility, f.created_at, f.updated_at,
            u.name AS owner_name,
            CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
            CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
     FROM files f
     JOIN users u ON u.id = f.user_id
     LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
     WHERE f.user_id = ? OR fs.shared_with_user_id = ?
     ORDER BY f.created_at DESC, f.id DESC',
    [$userId, $userId, $userId, $userId, $userId]
);
```

- `LEFT JOIN` une a tabela de compartilhamentos, e `WHERE` obtém "próprios OU compartilhados"
- Arquivos públicos não são incluídos na listagem (podem ser visualizados individualmente via GET)
- `CASE WHEN` calcula flag de proprietário e permissão de edição

---

## Prevenção de Escalação de Visibility

Mesmo compartilhadores com permissão edit não podem alterar `visibility`. Apenas o proprietário pode alterar.

```php
// Only owner can change visibility
if ($ownerId !== $userId) {
    $visibility = (string) $file['visibility']; // Sobrescreve com valor atual
}

$this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
```

---

## Limpeza de Entradas de Compartilhamento ao Deletar Arquivo

```php
public function delete(int $id): void
{
    $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
    $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
}
```

Devido à constraint FK, deletar `file_shares` primeiro e depois `files`.

---

## Design de Validação

```php
// name: obrigatório, máximo 255 caracteres
if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
    $errors[] = new ValidationError('name', 'name is required', 'required');
} elseif (mb_strlen($body['name']) > 255) {
    $errors[] = new ValidationError('name', 'name is too long', 'too_long');
}

// size: tipo inteiro obrigatório, >= 0
if (!isset($body['size']) || !is_int($body['size'])) {
    $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
}

// visibility: verificação de valor enumerado
if (!in_array($body['visibility'], ['private', 'public'], true)) {
    $errors[] = new ValidationError('visibility', 'visibility must be private or public', 'invalid_value');
}
```

---

## Resultados do Diagnóstico de Vulnerabilidades (FT156)

| ID | Vulnerabilidade | Resultado |
|----|----------------|-----------|
| VULN-A | IDOR: acesso direto a arquivo privado de outro usuário | Pass (retorna 404) |
| VULN-B | IDOR: deletar arquivo de outro usuário | Pass (retorna 404) |
| VULN-C | IDOR: atualizar arquivo de outro usuário | Pass (retorna 404) |
| VULN-D | Escalação de privilégio: compartilhador view tenta operação edit | Pass (retorna 403) |
| VULN-E | Injeção de propriedade: user_id no body | Pass (ignorado) |
| VULN-F | Personificação na exclusão de compartilhamento: destinatário deleta seu próprio compartilhamento | Pass (retorna 404) |
| VULN-G | SQL injection: nome do arquivo | Pass (query parametrizada) |
| VULN-H | Nome muito longo: 300 caracteres | Pass (retorna 422) |
| VULN-I | Confusão de tipo: float em size | Pass (retorna 422) |
| VULN-J | Escalação de visibilidade: compartilhador edit altera visibility | Pass (ignorado) |
| VULN-K | Adivinhação de existência: 403 vs 404 | Pass (retorna 404) |
| VULN-L | Bypass de autenticação: X-User-Id=0 / negativo | Pass (retorna 401) |

---

## Resultados do Teste de Ataque de Cracker (FT156)

| ID | Cenário de Ataque | Resultado |
|----|------------------|-----------|
| ATK-01 | Personificação: GET de arquivo de outro usuário | Pass (retorna 404) |
| ATK-02 | Personificação: DELETE de arquivo de outro usuário | Pass (retorna 404) |
| ATK-03 | Compartilhador view tenta editar via PUT | Pass (retorna 403) |
| ATK-04 | Injeção de user_id no body para falsificar proprietário | Pass (ignorado) |
| ATK-05 | Path traversal: `../../etc/passwd` | Pass (retorna 404) |
| ATK-06 | Tentativa de acesso com ID em string | Pass (retorna 404) |
| ATK-07 | Envio de header X-User-Id vazio | Pass (retorna 401) |
| ATK-08 | SQL injection: campo mime_type | Pass (query parametrizada) |
| ATK-09 | Envio de description muito longa (10000 caracteres) | Pass (armazenado sem truncar; name > 255 retorna 422) |
| ATK-10 | Compartilhador edit escalona visibility para public | Pass (ignorado) |
| ATK-11 | Destinatário tenta deletar seu próprio compartilhamento | Pass (retorna 404) |
| ATK-12 | Sondagem de existência: adivinhar ID de arquivo alheio | Pass (retorna 404) |

---

## Pontos-Chave nos Testes

```php
// Arquivo privado de outro usuário retorna 404 (não 403)
$res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
$this->assertSame(404, $res->getStatusCode());

// Compartilhador edit não pode alterar visibility
$this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
    'name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'public',
]);
$check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
$this->assertSame('private', $this->json($check)['visibility']);

// user_id no body é ignorado (obtido de X-User-Id)
$res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'test.txt', 'size' => 1, 'mime_type' => 'text/plain', 'user_id' => 2]);
$this->assertSame(1, $this->json($res)['user_id']);
```
