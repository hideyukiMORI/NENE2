# Como Fazer: Middleware de Bearer Token (Casos Extremos de Auth JWT)

> **Referência FT**: FT273 (`NENE2-FT/authlog`) — Auth JWT com BearerTokenMiddleware: rejeição de alg=none, detecção de adulteração de assinatura, aplicação de exp/nbf, cabeçalho WWW-Authenticate, isolamento de dados por sub, IDOR → 404, 18 testes / 26 asserções PASS.
>
> **Avaliação VULN**: V-01 a V-10 incluídos no final deste documento.

Demonstra o uso do `BearerTokenMiddleware` + `LocalBearerTokenVerifier` (HMAC-HS256) do NENE2 para proteger rotas. Todos os casos extremos de validação JWT são tratados pelo middleware; os controllers recebem apenas as claims decodificadas via `nene2.auth.claims`.

---

## Configuração

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

O middleware define `nene2.auth.claims` na requisição antes que qualquer handler de rota seja executado. Se a validação falhar, retorna 401 com `WWW-Authenticate: Bearer` antes de invocar o handler.

---

## Extraindo claims em um controller

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

A claim `sub` é a identidade canônica do usuário. Usá-la como `owner_id` garante isolamento de dados por usuário sem nenhuma busca adicional.

---

## Cabeçalho WWW-Authenticate

Em 401, o middleware emite `WWW-Authenticate: Bearer realm="api"`.
Para tokens expirados, o cabeçalho inclui `error="invalid_token"`:

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

A conformidade com RFC 6750 permite que os clientes distingam "sem token" de "token inválido".

---

## Avaliação de Vulnerabilidades

### V-01 — Substituição de algoritmo alg=none ✅ SAFE

**Risco**: Um atacante cria um JWT com `"alg":"none"` e um payload não assinado reivindicando `sub: admin`.
**Resultado**: SAFE — `LocalBearerTokenVerifier` aceita apenas HMAC-HS256. Tokens com `alg=none` são rejeitados na verificação de assinatura; o teste `testWrongAlgorithmHeaderReturns401` confirma 401.

---

### V-02 — Adulteração de assinatura ✅ SAFE

**Risco**: Atacante intercepta um JWT válido e modifica o payload (ex.: muda `sub` para `admin`) mantendo o cabeçalho e a assinatura original.
**Resultado**: SAFE — a assinatura HMAC-HS256 cobre `header.payload`. Qualquer modificação invalida o MAC; `testTamperedPayloadReturns401` confirma 401.

---

### V-03 — Replay de token expirado ✅ SAFE

**Risco**: Um token expirado é replayado após a sessão estar inválida.
**Resultado**: SAFE — a claim `exp` é validada; tokens com `exp < time()` são rejeitados. `testExpiredTokenReturns401` confirma 401 com `invalid_token` em `WWW-Authenticate`.

---

### V-04 — Bypass de not-before (nbf) ✅ SAFE

**Risco**: Um token com `nbf` futuro (ainda não válido) é usado antes de seu tempo de ativação.
**Resultado**: SAFE — `nbf` é aplicado; `testNbfInFutureReturns401` confirma 401.

---

### V-05 — Esquema de Authorization errado ✅ SAFE

**Risco**: Atacante envia `Authorization: Basic dXNlcjpwYXNz` ou omite o prefixo `Bearer `.
**Resultado**: SAFE — o middleware aceita apenas tokens prefixados com `Bearer `. Strings `Basic` e tokens sem prefixo retornam 401.

---

### V-06 — Estrutura de token malformada ✅ SAFE

**Risco**: Atacante envia tokens com 2 partes, 4 partes, payload não-base64, ou strings aleatórias para sondar o tratamento de erros.
**Resultado**: SAFE — todas as variantes malformadas retornam 401. Tokens que não têm 3 partes e base64 inválido são rejeitados antes de qualquer extração de claim.

---

### V-07 — Segredo de assinatura errado ✅ SAFE

**Risco**: Um atacante com conhecimento do formato JWT assina um token com um segredo diferente.
**Resultado**: SAFE — a verificação HMAC falha se o segredo difere; `testWrongSecretSignatureReturns401` confirma 401.

---

### V-08 — IDOR: acesso a dados entre usuários ✅ SAFE

**Risco**: Usuário A tenta ler os dados do Usuário B conhecendo ou adivinhando o ID da entrada.
**Resultado**: SAFE — `findByIdAndOwner($id, $ownerId)` limita a busca ao `sub` do JWT. Uma requisição entre usuários retorna 404 (não 403) para evitar revelar que a entrada existe.

---

### V-09 — Isolamento de dados por usuário ✅ SAFE

**Risco**: As escritas do Usuário A são visíveis para o Usuário B.
**Resultado**: SAFE — todas as leituras têm escopo por `owner_id = sub`. `testEntriesAreIsolatedByToken` verifica que as entradas de Alice e Bob são completamente separadas.

---

### V-10 — Token sem claim exp ✅ SAFE (aceitável)

**Risco**: Um token sem claim `exp` é emitido, tornando-se efetivamente não expirante.
**Resultado**: SAFE (por design) — `LocalBearerTokenVerifier` somente valida `exp` se a claim estiver presente. Tokens sem `exp` são aceitos. Esse é um trade-off deliberado para cenários service-to-service; implantações em produção devem aplicar `exp` via um verificador mais estrito, se necessário.

---

### Resumo VULN

| ID | Vulnerabilidade | Resultado |
|----|-----------------|-----------|
| V-01 | Substituição de algoritmo alg=none | ✅ SAFE |
| V-02 | Adulteração de assinatura | ✅ SAFE |
| V-03 | Replay de token expirado | ✅ SAFE |
| V-04 | Bypass de not-before (nbf) | ✅ SAFE |
| V-05 | Esquema de Authorization errado | ✅ SAFE |
| V-06 | Estrutura de token malformada | ✅ SAFE |
| V-07 | Segredo de assinatura errado | ✅ SAFE |
| V-08 | IDOR acesso a dados entre usuários | ✅ SAFE |
| V-09 | Isolamento de dados por usuário | ✅ SAFE |
| V-10 | Token sem claim exp | ✅ SAFE (por design) |

**10 SAFE, 0 EXPOSED**
Nenhuma vulnerabilidade crítica. O `BearerTokenMiddleware` trata todos os vetores de ataque JWT padrão; o código da aplicação só precisa usar a claim `sub` para escopo de propriedade.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar tokens `alg=none` | Atacante pode forjar qualquer identidade omitindo a assinatura |
| Ignorar validação de `exp` | Tokens roubados permanecem válidos indefinidamente |
| Retornar 403 em IDOR | Revela que o recurso existe e pertence a outra pessoa |
| Usar cabeçalho `X-User-Id` em vez de `sub` do JWT | O cabeçalho é trivialmente falsificável; a claim JWT é criptograficamente vinculada |
| Compartilhar o segredo de assinatura entre ambientes | Um vazamento no ambiente de dev compromete tokens de produção |
| Usar chaves `RS256` menores que 2048 bits | Vulnerável a ataques de fatoração |
