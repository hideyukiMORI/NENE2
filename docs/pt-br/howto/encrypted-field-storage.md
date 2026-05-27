# Como Construir Armazenamento de Campos Criptografados

> **Referência FT**: FT267 (`NENE2-FT/encryptlog`) — Criptografia de campo AES-256-GCM: criptografar-na-escrita / descriptografar-na-leitura, índice cego para texto cifrado pesquisável, separação de chaves entre chaves de criptografia e de índice
>
> **Avaliação VULN**: V-01 a V-10 incluídos no final deste documento.
>
> **Padrão também comprovado pelo FT187 encryptlog** — criptografia por campo AES-256-GCM com índice cego HMAC-SHA256 para armazenamento pesquisável de PII.

---

## O que este guia cobre

Armazenar campos sensíveis (nome, e-mail, CPF, cartão de crédito) criptografados em repouso, mantendo-os pesquisáveis:

1. **AES-256-GCM** — criptografia autenticada; cada registro recebe seu próprio nonce
2. **Índice cego** — HMAC-SHA256 do valor do campo habilita `WHERE email_idx = ?` sem descriptografia
3. **Detecção de adulteração AEAD** — incompatibilidade de tag causa `\RuntimeException`, não 400
4. **Texto cifrado nunca em respostas da API** — a camada VO / toArray() sempre retorna texto simples
5. **Prevenção IDOR** — todas as leituras/escritas escopam `WHERE id AND user_id`

---

## Formato do texto cifrado

```
base64( nonce ‖ ciphertext ‖ tag )
```

| Componente | Tamanho | Propósito |
|---|---|---|
| `nonce` | 12 bytes | IV aleatório por criptografia (padrão GCM) |
| `ciphertext` | variável | Texto simples criptografado com AES-256-GCM |
| `tag` | 16 bytes | Tag de autenticação — detecta adulteração |

Armazenado como uma única coluna `TEXT`. Mesmo texto simples → texto cifrado diferente a cada vez (nonce diferente).

---

## Schema

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- índice cego HMAC-SHA256 para busca
    notes_enc  TEXT,               -- campo criptografado nullable
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` tem um índice — `WHERE email_idx = ?` é rápido. O texto cifrado `email_enc` nunca é pesquisado.

---

## Helper FieldCrypto

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // deve ter 32 bytes
        private string $indexKey, // deve ter 32 bytes
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // IV fresco por valor
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * Determinístico — mesma entrada sempre → mesma saída.
     * Permite WHERE email_idx = ? sem descriptografar o texto cifrado armazenado.
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## Padrão central: escrita criptografa, leitura descriptografa

```php
// CREATE — criptografar todos os campos sensíveis antes do INSERT
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // determinístico para busca
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// READ — descriptografar transparentemente na hidratação
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## Padrão central: busca por índice cego

```php
// SEARCH — computar índice cego do parâmetro de consulta, nunca descriptografar linhas durante a busca
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // mesma chave → mesmo índice
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // as linhas são então descriptografadas em hydrateRow()
}
```

**Quando o e-mail muda na atualização, reindexar:**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← deve atualizar juntos
]);
```

---

## Padrão central: texto cifrado nunca nas respostas

```php
// VaultRecord::toArray() — apenas retorna texto simples descriptografado
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // texto simples
        'email'      => $this->email, // texto simples
        'notes'      => $this->notes, // texto simples ou null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc, email_enc, email_idx, notes_enc — nunca expostos
    ];
}
```

Um atacante que lê a resposta da API não consegue recuperar o texto cifrado para realizar ataques offline.

---

## Padrão central: detecção de adulteração é um 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // Incompatibilidade de tag = linha DB adulterada OU chave errada
    // Lançar — deixar o handler global de erros retornar 500
    // NÃO retorne 400 — um 400 é um erro do cliente; este é um falha de integridade interna
    throw new \RuntimeException('Decryption failed.');
}
```

Retornar 400 implicaria que o cliente enviou dados ruins. Um 500 sinaliza corretamente "problema de integridade no lado do servidor" e não vaza qual campo falhou ou por quê.

---

## Diretrizes de gerenciamento de chaves

```php
// Produção: derivar chaves de um KMS ou gerenciador de segredos
$encKey   = random_bytes(32); // 32 bytes = AES-256
$indexKey = random_bytes(32); // chave separada — domínio HMAC diferente

// NUNCA codifique chaves no fonte; use variáveis de ambiente ou derivação de chaves:
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 chars hex → 32 bytes
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 chars hex → 32 bytes
```

**Duas chaves separadas:**
- `encKey` — AES-256-GCM. Rotacionável: re-criptografar linhas com nova chave, atualizar prefixo de versão.
- `indexKey` — HMAC-SHA256. Não pode ser rotacionada sem re-hashar todos os índices.

---

## Resultados de testes (FT187)

```
51 testes / 110 asserções — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
```

| Área de teste | Cobertura |
|---|---|
| Unitário FieldCrypto | round-trip encrypt/decrypt, unicidade de nonce, determinismo de índice cego, detecção de adulteração, rejeição de chave curta |
| Caminho feliz | criar/obter/listar/atualizar/deletar/buscar |
| Isolamento de texto cifrado | `name_enc`, `email_enc`, `email_idx`, `notes_enc` não na resposta |
| Prevenção IDOR | cross-user get/update/delete todos retornam 404 |
| Mass assignment | `name_enc`, `email_idx`, `user_id` do corpo ignorados |
| Validação | nome/e-mail/notas ausentes/longos/tipo-errado, limit |
| Reindexação de índice cego | atualização de e-mail mantém índice sincronizado |

---

## Avaliação VULN (FT267)

Avaliação de segurança do `NENE2-FT/encryptlog` sob o modelo de ameaças de criptografia de campo.

### V-01 — Gerenciamento de chaves: carregamento de env ✅ BLOQUEADO

**Ameaça**: Chaves de criptografia comprometidas no VCS ou codificadas no fonte.
**Mitigação**: Chaves carregadas via `getenv()` no `ConfigLoader`, comprimento validado na inicialização. O arquivo `.env` está no git-ignore. Nenhum material de chave aparece no código fonte.
**Residual**: Rotação de chaves (substituir ambas as chaves, re-criptografar todas as linhas) não está implementada. Aceito para escopo do FT; sistema de produção precisa de plano de rotação.

---

### V-02 — Reutilização de nonce (GCM) ✅ BLOQUEADO

**Ameaça**: Se o mesmo nonce for usado duas vezes com a mesma chave, GCM perde todas as garantias de confidencialidade e autenticidade.
**Mitigação**: `random_bytes(12)` é chamado dentro de `encrypt()` em cada invocação. O espaço de nonce de 96 bits e `random_bytes()` tornam a probabilidade de colisão negligenciável para qualquer volume de uso realista (< 2^32 criptografias por vida útil da chave é o limite seguro).
**Descoberta**: Seguro.

---

### V-03 — Verificação de tag de autenticação ✅ BLOQUEADO

**Ameaça**: Adulteração de texto cifrado passa desapercebida; atacante inverte bits para manipular texto simples descriptografado.
**Mitigação**: `openssl_decrypt()` verifica a tag de autenticação GCM de 16 bytes antes de retornar o texto simples. Qualquer modificação de um único bit retorna `false`, que `FieldCrypto::decrypt()` converte em um `\RuntimeException` lançado. A aplicação o captura e retorna `500`; nenhum texto simples parcial é exposto.
**Descoberta**: Seguro.

---

### V-04 — Detalhe de erro de descriptografia na resposta da API ⚠️ EXPOSTO

**Ameaça**: Handler de erros serializa `\RuntimeException::getMessage()` ("Decryption failed — tag mismatch or corrupt ciphertext.") na resposta da API, vazando um sinal de integridade para atacantes.
**Descoberta**: No modo `APP_DEBUG=true` a mensagem completa e stack trace podem aparecer. No modo `APP_DEBUG=false`, o handler padrão ainda pode expor o nome da classe da exceção.
**Recomendação**: Adicionar um `DecryptionFailedExceptionHandler` dedicado que mapeia para `500` com um corpo Problem Details genérico `"internal-error"` independentemente do modo de debug. Falha na verificação de tag deve ser registrada apenas no lado do servidor.

---

### V-05 — Colisão de índice cego / dicionário offline ✅ BLOQUEADO

**Ameaça**: Atacante constrói um dicionário de valores `blindIndex(candidato)` offline e compara com a coluna `email_idx`.
**Mitigação**: HMAC-SHA256 com chave secreta de 256 bits. Sem `VAULT_INDEX_KEY`, pré-computar qualquer valor de índice é computacionalmente inviável. O índice cego suporta apenas correspondência exata (`WHERE email_idx = ?`); busca por wildcard ou substring não é possível.
**Residual**: Se `VAULT_INDEX_KEY` for comprometida, todos os índices cegos de e-mail se tornam brute-forceable para uma lista finita de e-mails conhecidos. A confidencialidade da chave é essencial.

---

### V-06 — Sem autenticação/autorização nos endpoints ⚠️ EXPOSTO

**Ameaça**: Qualquer chamador não autenticado pode criar, ler, atualizar e deletar registros de vault para valores arbitrários de `user_id`.
**Descoberta**: O FT expõe `/vault/{userId}/records` sem verificação de API key, JWT ou sessão. O parâmetro de caminho `user_id` é fornecido pelo chamador.
**Recomendação**: Exigir autenticação (API key ou JWT) e derivar `$userId` do token verificado — nunca confiar em `user_id` fornecido pelo chamador. Adicionar `requireScope()` ou middleware de auth equivalente.
**Nota do FT**: Restrição de escopo deliberada para o FT. Uso em produção exige auth.

---

### V-07 — IDOR em atualização/deleção ✅ BLOQUEADO

**Ameaça**: Usuário autenticado-mas-errado modifica o registro criptografado de outro usuário.
**Mitigação**: Todas as consultas de escrita incluem `AND user_id = :user_id`. Se o registro pertence a um usuário diferente, `rowCount()` retorna 0 e o controller retorna 404. O atacante aprende apenas que o registro não existe (para ele).
**Descoberta**: Seguro, assumindo que autenticação está presente (veja V-06).

---

### V-08 — Lacuna de rotação de chaves / re-criptografia ⚠️ EXPOSTO

**Ameaça**: Quando `VAULT_ENC_KEY` é rotacionada, o texto cifrado antigo criptografado sob a chave anterior não pode ser descriptografado. Não há estratégia de migração de re-criptografia.
**Descoberta**: Sem versionamento de chave, sem utilitário de re-criptografia e sem migração documentada.
**Recomendação**: Prefixar cada blob criptografado com um byte de versão de chave (ex.: `v1:<base64>`). Na descriptografia, ler versão, selecionar chave. Fornecer script de migração que descriptografa sob chave antiga e re-criptografa sob chave nova em uma transação.

---

### V-09 — Comparação temporizada de índice cego ✅ BLOQUEADO

**Ameaça**: Comparar `email_idx` de uma fonte não confiável com `===` vaza informações de temporização caractere por caractere.
**Mitigação**: `findByEmail()` passa o índice cego computado como parâmetro SQL. A comparação ocorre dentro da busca no índice B-tree do SQLite, que não é um oráculo de temporização do lado PHP. Nenhuma comparação de string PHP do lado de valores de índice cego ocorre.
**Descoberta**: Seguro.

---

### V-10 — Dados descriptografados em memória/logs ⚠️ EXPOSTO

**Ameaça**: Texto simples descriptografado (nome, e-mail, notas) aparece em: rastros de exceção PHP, middleware de logging de requisições (se o corpo for logado), saída de erros, spans APM.
**Descoberta**: O middleware de logging de requisições registra o corpo do POST antes da criptografia ocorrer — campos em texto simples estão no log. Se `VaultRecord` for incluído em um contexto de exceção, os campos descriptografados aparecem no stack trace.
**Recomendação**:
1. Excluir payloads de vault em texto simples do logging do corpo de requisição (mascarar ou pular rotas `/vault`).
2. Implementar `__debugInfo()` em `VaultRecord` para redigir campos sensíveis do var_dump / serialização de exceção.
3. Garantir que integrações de rastreamento de erros (Sentry, etc.) limpem campos em texto simples antes da transmissão.

---

### Resumo VULN

| ID | Ameaça | Status |
|----|--------|--------|
| V-01 | Chave comprometida no VCS | ✅ BLOQUEADO |
| V-02 | Reutilização de nonce (GCM) | ✅ BLOQUEADO |
| V-03 | Texto cifrado adulterado aceito | ✅ BLOQUEADO |
| V-04 | Detalhe de erro de descriptografia na resposta | ⚠️ EXPOSTO |
| V-05 | Dicionário offline de índice cego | ✅ BLOQUEADO |
| V-06 | Sem autenticação nos endpoints | ⚠️ EXPOSTO |
| V-07 | IDOR em atualização/deleção | ✅ BLOQUEADO |
| V-08 | Lacuna de rotação de chaves / re-criptografia | ⚠️ EXPOSTO |
| V-09 | Comparação temporizada de índice cego | ✅ BLOQUEADO |
| V-10 | Dados descriptografados em logs/exceções | ⚠️ EXPOSTO |

**Pontuação**: 6 BLOQUEADOS, 4 EXPOSTOS.

As quatro exposições são em estratégia de rotação de chaves (V-08), autenticação (V-06, escopo deliberado do FT), vazamento de detalhe de erro (V-04) e higiene de logs (V-10). Nenhuma representa uma falha no design criptográfico AES-256-GCM ou de índice cego — são lacunas operacionais e de integração que devem ser tratadas antes do uso em produção.
