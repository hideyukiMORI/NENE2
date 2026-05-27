# Como usar autenticação com Bearer token

O NENE2 inclui `BearerTokenMiddleware` e `LocalBearerTokenVerifier` para autenticação baseada em JWT. Este guia aborda configuração, emissão de tokens e armadilhas comuns.

## Configuração

Conecte o middleware ao `RuntimeApplicationFactory` usando o parâmetro nomeado `authMiddleware`:

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: 'change-me';
$verifier = new LocalBearerTokenVerifier($secret);
$bearer   = new BearerTokenMiddleware($problemDetails, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearer, // ← parâmetro nomeado é authMiddleware, não middlewares
))->create();
```

> **Nota:** O nome do parâmetro é `authMiddleware`, não `middlewares`. Usar `middlewares:` causa um `Error: Unknown named parameter` em tempo de execução.

## Proteger todas as rotas vs. proteção seletiva

`BearerTokenMiddleware` suporta quatro modos de correspondência de caminho (primeira correspondência vence):

```php
// 1. Proteger apenas caminhos específicos (allowlist)
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. Proteger caminhos começando com um prefixo (allowlist de prefixo)
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. Proteger tudo EXCETO caminhos listados (blocklist — padrão comum)
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. Proteger todos os caminhos (padrão — sem arrays fornecidos)
new BearerTokenMiddleware($problems, $verifier);
```

## Ler claims em um handler

Após verificação bem-sucedida, os claims são armazenados como atributo da requisição:

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

O tipo de credencial é armazenado separadamente:

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## Emitir tokens (local / teste)

`LocalBearerTokenVerifier` também implementa `TokenIssuerInterface`:

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← sempre inclua exp
]);
```

> **Sempre inclua `exp`.** Tokens sem `exp` são tratados como sem expiração. Isso é seguro para testes, mas perigoso se tais tokens chegarem à produção. Se `exp` estiver ausente, o verificador pula a verificação de expiração.

## Respostas de erro

Em caso de falha, `BearerTokenMiddleware` retorna uma resposta Problem Details `401 Unauthorized` com cabeçalho `WWW-Authenticate`:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

Códigos de erro em `WWW-Authenticate`:
- `missing_token` — sem cabeçalho `Authorization`
- `invalid_token` — esquema incorreto, expirado, assinatura inválida, malformado, algoritmo errado, `nbf` no futuro

## Propriedades de segurança do `LocalBearerTokenVerifier`

| Ameaça | Proteção |
|--------|---------|
| Falsificação de assinatura | HMAC-HS256, `hash_equals` em tempo constante |
| Substituição de algoritmo (`alg:none`) | Apenas `HS256` aceito |
| Token expirado | Claim `exp` verificado |
| Token ainda não válido | Claim `nbf` verificado |
| Payload adulterado | Assinatura cobre header + payload; adulteração quebra assinatura |

> `LocalBearerTokenVerifier` é projetado para desenvolvimento local e testes. Para produção, injete uma implementação de `TokenVerifierInterface` baseada em biblioteca (por exemplo, firebase/php-jwt) que suporte rotação de chaves e algoritmos assimétricos.

## Padrões de teste

```php
// Em setUp(): criar verificador com um segredo de teste
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// Emitir token válido para um usuário
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// Emitir token expirado (para teste negativo)
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// Emitir token ainda não válido
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
