# Recuperar a autenticação Bearer atrás de proxies que removem `Authorization`

Alguns proxies frontais de hospedagem compartilhada removem o cabeçalho padrão
`Authorization` antes que a requisição chegue ao PHP (observado em produção em
hospedagem da classe HETEML). Cabeçalhos personalizados passam, `Authorization` não —
então os truques usuais de recuperação também falham:

- `.htaccess` `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` — inútil,
  o Apache nunca vê o cabeçalho;
- `CGIPassAuth on` — pelo mesmo motivo.

O resultado: todo endpoint protegido por Bearer responde 401 `missing_token`, mesmo com o
navegador enviando um token perfeitamente válido.

O NENE2 traz uma correção padrão em duas partes (veja o ADR 0019):

1. **Frontend**: `@hideyukimori/nene2-client` (≥ 1.1.0) espelha o token em
   `X-Authorization: Bearer <token>` em cada requisição, junto com o cabeçalho padrão.
2. **Backend**: `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` adota o espelho
   **somente quando `Authorization` está ausente ou vazio**. Hosts que entregam o
   cabeçalho padrão permanecem intactos byte a byte.

---

## Habilitar no pipeline padrão

Uma única flag opt-in na `RuntimeApplicationFactory`:

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // desligado por padrão
))->create();
```

Quando habilitado, o fallback roda no início do estágio de autenticação — antes da
verificação da chave de API de máquina e antes de qualquer middleware de autenticação
injetado — de modo que todo middleware que lê credenciais vê o cabeçalho restaurado. Ele
independe de método e de caminho.

## Ou conectar manualmente

Em um pipeline montado à mão, coloque-o em qualquer ponto antes do seu middleware de
autenticação:

```php
$stack = [
    // ... request id, logging, cabeçalhos de segurança, CORS, tratamento de erros ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

Fora de um pipeline PSR-15, a transformação está disponível como helper estático:

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## Quando NÃO habilitar

Habilitar o fallback torna `X-Authorization` equivalente a `Authorization` como
credencial. Isso é exatamente o certo em hosts que removem o cabeçalho *acidentalmente* —
e exatamente o errado onde um upstream o remove *deliberadamente*:

- um gateway que realiza a autenticação por conta própria e encaminha uma identidade
  confiável;
- um WAF que filtra credenciais de entrada de clientes não confiáveis.

Nesses cenários o espelho seria um bypass controlado pelo cliente. Mantenha a flag
desligada, ou faça o upstream remover também o `X-Authorization`.

Além disso, trate `X-Authorization` com a mesma confidencialidade de `Authorization` em
logs de acesso e proxies intermediários.

## Notas

- O nome do cabeçalho é **fixo** (`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`,
  `X-Authorization`). É um contrato de fiação de toda a frota com o cliente frontend, não
  um botão de ajuste.
- O valor do espelho é adotado literalmente (incluindo `Bearer <token>`). A validação do
  token continua inteiramente a cargo do seu middleware de autenticação — um espelho
  inválido falha exatamente como um cabeçalho padrão inválido.
- A precedência é sempre: um `Authorization` não vazio vence; o espelho só é consultado
  quando o cabeçalho padrão está ausente ou vazio.
