# URLs Assinadas

URLs assinadas fornecem acesso temporário e com escopo de recurso a recursos protegidos sem exigir que o chamador se autentique com uma conta. O padrão é usado para downloads de arquivos, slots de upload pré-assinados e qualquer caso em que você precise compartilhar acesso temporário com um terceiro.

## Conceito Central

Uma URL assinada contém tudo o que é necessário para autorizar o acesso: o ID do recurso, o tempo de expiração e uma assinatura HMAC que prova que a URL foi gerada por um servidor confiável. O servidor precisa apenas de sua chave secreta para verificar — nenhuma consulta ao banco de dados é necessária.

## Formato do Token

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

O HMAC cobre `resource_id|expires_at` juntos. Alterar qualquer uma das partes invalida a assinatura. Isso vincula o token a exatamente um recurso e uma janela de expiração.

## Implementação do Signer

```php
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {}

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $expectedMac = hash_hmac(self::ALGO, $resourceId . '|' . $expiresAt, $this->secret);

        // hash_equals() é obrigatório — usar === vaza informações de timing
        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }
}
```

`hash_equals()` é inegociável. Uma comparação de igualdade de string sai no primeiro mismatch, vazando quantos caracteres do HMAC correspondem. Um atacante pode explorar isso para forjar assinaturas byte a byte. `hash_equals()` sempre compara todos os caracteres.

## 410 Gone vs 401 Unauthorized para Tokens Expirados

Usuários se beneficiam de saber se seu link expirou (e devem solicitar um novo) em vez de se o link nunca foi válido. O signer verifica o HMAC primeiro, depois a expiração. Para distingui-los na resposta HTTP:

```php
$resourceId = $this->signer->verify($token, $now);

if ($resourceId === null) {
    // Extrair expiração sem verificação de HMAC
    $expiresAt = $this->signer->extractExpiresAt($token);
    if ($expiresAt !== null && $expiresAt < $now) {
        return $problems->create($request, 'gone', 'Este link expirou.', 410, '');
    }
    return $problems->create($request, 'unauthorized', 'Token inválido ou expirado.', 401, '');
}
```

`extractExpiresAt()` apenas decodifica base64 e divide no `|` — ele NÃO verifica o HMAC. Isso é seguro porque:
1. A expiração não é um segredo (é visível na URL assinada de qualquer forma).
2. Um atacante não pode forjar um token válido com uma expiração manipulada porque `verify()` irá rejeitá-lo.
3. A resposta 410 não fornece informações que ajudem a forjar tokens.

NÃO exponha mensagens de erro diferentes para "HMAC não confere" vs "expiração passada" — isso permitiria que um atacante construísse assinaturas válidas para valores de expiração arbitrários primeiro, e depois os usasse para sondar o timing.

## Gerando URLs Assinadas

```php
// POST /files/{id}/sign
$expiresAt = (new \DateTimeImmutable())
    ->add(new \DateInterval("PT{$ttlSeconds}S"))
    ->format('Y-m-d H:i:s');

$token = $this->signer->sign($file->id, $expiresAt);

return $json->create([
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttlSeconds,
    'url'         => '/download?token=' . urlencode($token),
]);
```

Sempre aplique `urlencode()` ao token antes de incorporar em URLs — caracteres base64url são seguros para URL, mas o preenchimento `=` (se presente) não é, e o separador `|` no payload decodificado não deve aparecer na forma codificada.

## Gerenciamento de Chave Secreta

- Injete o secret a partir de uma variável de ambiente — nunca o codifique diretamente.
- Use pelo menos 32 bytes de dados aleatórios (`random_bytes(32)` → hex ou base64).
- Para rotação de secret, suporte a verificação contra múltiplos secrets simultaneamente (tente cada um até que um tenha sucesso), depois elimine gradualmente o secret antigo.

```php
// Suporte a múltiplos secrets durante rotação
public function verifyWithRotation(string $token, string $now, array $secrets): ?int
{
    foreach ($secrets as $secret) {
        $signer = new HmacSigner($secret);
        $id = $signer->verify($token, $now);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}
```

## URLs Assinadas Stateless vs Stateful

Este padrão é **stateless** — o servidor não rastreia tokens emitidos. Esta é a principal vantagem (sem consulta ao banco de dados em cada download), mas significa:

- Você não pode revogar uma URL assinada antes que ela expire.
- Se o secret for rotacionado, todos os tokens emitidos anteriormente são invalidados imediatamente.

Para tokens revogáveis, mantenha uma tabela de blocklist (`revoked_tokens`) e verifique-a durante a verificação. Isso troca o benefício stateless pela revogabilidade.

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Usar `===` ou `strcmp()` para comparação de HMAC | Ataque de timing — permite forjar assinaturas |
| Assinar apenas `resource_id` sem expiração | Tokens são permanentes — não podem expirar |
| Assinar apenas `expires_at` sem resource_id | Um token concede acesso a todos os recursos |
| Usar a expiração para distinguir "adulterado" de "expirado" | Permite ataque de oráculo no HMAC |
| Incorporar a chave bruta no token | Derrota o propósito — o token deve ser opaco |
| TTLs longos (dias/semanas) | Aumenta a janela de exposição se o token vazar |
