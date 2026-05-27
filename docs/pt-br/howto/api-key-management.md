# Gerenciamento de Chaves de API

> **Referência FT**: FT266 (`NENE2-FT/apikeylog`) — Ciclo de vida de chaves de API: geração, armazenamento de hash SHA-256, busca por prefixo, aplicação de escopo, rotação

Este guia cobre a implementação de gerenciamento de chaves de API em aplicações NENE2: geração de chaves, armazenamento seguro, autorização baseada em escopo, revogação e rotação.

## Princípios de design fundamentais

1. **Nunca armazene chaves brutas** — somente hashes SHA-256 no banco de dados.
2. **Retorne a chave bruta uma vez** — somente no momento da criação, nunca mais.
3. **Busca por prefixo, verificação por hash** — o prefixo estreita a consulta ao banco de dados; `hash_equals()` faz a autenticação real.
4. **Hierarquia de escopo** — admin ⊃ write ⊃ read; verificada por endpoint.
5. **Rotação segura** — crie a nova chave antes de revogar a antiga para evitar bloqueio.

## Formato da chave

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 chars de base64url(32 bytes aleatórios) -----^
|
prefixo de tipo (identificável nos logs)
```

`random_bytes(32)` fornece 256 bits de entropia. Isso é computacionalmente inviável de fazer força bruta, independentemente da velocidade do hash, então SHA-256 (rápido, para uso único) é apropriado — ao contrário de senhas, chaves de API não são vulneráveis a ataques de dicionário.

## Schema

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- primeiros 16 chars da chave bruta (índice de busca)
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

A coluna `prefix` armazena os **primeiros 16 caracteres da chave bruta** (não o prefixo de tipo `nk`). Isso fornece ~78 bits de diferenciação, tornando cada prefixo efetivamente único e permitindo busca por índice em O(1).

**Crítico**: NÃO use o prefixo de tipo (`nk`) como prefixo de busca no banco de dados. Todas as chaves compartilham o mesmo prefixo de tipo, então `WHERE prefix = 'nk'` varreria a tabela inteira — busca O(n) e um canal de temporização proporcional ao número de chaves.

## Geração de chaves

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // Primeiros 16 chars da chave completa — único por chave, seguro para indexar
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` é obrigatório. Usar `===` ou `==` para comparação de hash vaza informações de temporização: uma string hex de 64 chars comparada com `===` sai na primeira incompatibilidade, revelando quantos caracteres iniciais correspondem.

## Fluxo de autenticação

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

A abordagem em dois passos:
1. Busca por índice com prefixo (consulta rápida ao banco de dados)
2. Verificação com `hash_equals()` contra o hash armazenado

Retorne o mesmo `null` e `401` para todos os casos de falha (não encontrado, hash errado, expirado, revogado) — os chamadores não devem distinguir entre eles.

## Hierarquia de escopo

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

Aplique escopo no nível do endpoint:

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

Retorne `401` para não autenticado, `403` para autenticado mas com escopo insuficiente — nunca vaze se a chave existe.

## Filtragem de resposta

O método `toArray()` em `ApiKey` **não deve** incluir `key_hash`. A chave bruta está disponível apenas via `ApiKeyCreateResult::toArray()` imediatamente após a criação.

```php
// ApiKey::toArray() — seguro para retornar de qualquer endpoint
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash está intencionalmente ausente
    ];
}

// ApiKeyCreateResult::toArray() — apenas no endpoint de criação
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## Rotação de chaves — ordem segura

**Sempre crie a nova chave antes de revogar a antiga.**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // Criar primeiro — se isso falhar, a chave antiga permanece ativa (sem bloqueio)
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // Revogar depois — se isso falhar, ambas as chaves existem temporariamente (recuperável via listagem)
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

Revogar-então-criar é perigoso: se CREATE falhar após REVOKE, o proprietário fica permanentemente bloqueado. O inverso (criar-então-revogar) significa que o pior caso é duas chaves ativas temporariamente — observável e recuperável.

## Expiração

Armazene `expires_at` como uma string ISO datetime. Verifique em `isActive()`:

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

O fluxo de autenticação passa `$now` como parâmetro, tornando a lógica testável com timestamps fixos.

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar chave bruta no banco de dados | Exposição completa em caso de vazamento do banco de dados |
| Usar `===` para comparação de hash | Ataque de temporização vaza comprimento do prefixo do hash |
| Usar prefixo de tipo (`nk`) como índice de busca no banco de dados | Varredura O(n) da tabela; canal de temporização |
| Retornar `key_hash` em respostas de listagem/detalhe | Ataque de dicionário offline nos hashes |
| Revogar chave antiga antes de criar nova na rotação | Bloqueio do proprietário em caso de erro de banco de dados |
| Retornar erros diferentes para "chave não encontrada" vs "chave expirada" | Oracle para existência de chave |
| Registrar cabeçalho `X-Api-Key` em logs | Chave vaza para o armazenamento de logs |
