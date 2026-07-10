# Adicionar um demo descartável

Este guia mostra como dar ao seu produto um **demo descartável no estilo de faturas**: um visitante
abre `GET /demo/{template}`, a aplicação provisiona uma organização descartável totalmente nova, a semeia
com dados realistas do setor, estabelece uma sessão autenticada e redireciona com 302 para o
tenant recém-criado. Um sweeper via cron destrói as organizações de demo após um TTL. "Reiniciar o demo" é simplesmente
acessar a URL novamente — uma nova organização a cada vez.

O módulo do framework é `Nene2\Demo`. Ele é dono da orquestração independente de produto
(gate → throttle/capacidade → alocação de slug com retry em conflito → provisionar → semear → estabelecer sessão)
e da decisão de varredura (TTL + excedente). Você implementa quatro pequenas interfaces que carregam
tudo o que é específico do produto.

**Pré-requisito**: uma aplicação NENE2 funcionando com um modelo de organização multi-tenant
(organizações identificadas por slug) e alguma forma de criar e excluir uma organização.

---

## O que o framework fornece vs. o que você implementa

| Framework (`Nene2\Demo`) | Você (o produto) |
|---|---|
| `StartDisposableDemoHandler` — a orquestração HTTP | `DisposableOrgProvisionerInterface` — cria uma organização de demo + admin |
| `DisposableDemoSweeper` — decisão de TTL / excedente, `SweepReport` | `DisposableOrgReaperInterface` — destrói uma organização **e seus filhos** |
| `CountingDemoCapacityGuard` — teto no momento da criação + throttle por IP | `DemoSessionSeaterInterface` — handoff de autenticação + redirect |
| `DemoConfig` — configurações tipadas `DEMO_*` em `AppConfig::$demo` | `DemoDataSeederInterface` — dados de seed do setor |
| `DemoRouteRegistrar` — registra `GET /demo/{template}` | `DemoTemplateKeyInterface` — seu enum de templates |
| `MinimalDemoErrorPageRenderer` — página de erro de navegador sem marca | `DemoErrorPageRendererInterface` — página de erro com a sua marca (opcional) |

---

## 1. Configurar

As variáveis `DEMO_*` são carregadas pelo `ConfigLoader` em `AppConfig::$demo`
(um `Nene2\Demo\DemoConfig` tipado) — nunca as leia com `getenv()`:

```bash
DEMO_MODE=1            # análise estrita: apenas 1/true/yes habilitam; desativado por padrão
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

Com `DEMO_MODE` não definido, o endpoint responde com um simples 404 — você pode entregar a fiação dormente
e habilitá-la por implantação.

## 2. Definir a chave de template

Um enum com backing de string dos seus presets de setor semeáveis:

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // o segmento {template} da URL
    case Seisaku = 'seisaku';

    public function value(): string
    {
        return $this->value;
    }

    public static function tryFromValue(string $value): ?static
    {
        return self::tryFrom($value);
    }
}
```

## 3. Implementar o provisioner

Um wrapper fino sobre o seu caso de uso existente de "criar organização". Lance
`SlugConflictException` quando o slug já estiver ocupado (o handler tenta novamente com um novo slug aleatório),
gere credenciais de admin descartáveis internamente e retorne o id do admin — o
framework nunca busca o admin por literal de papel (role):

```php
final readonly class DemoOrgProvisioner implements DisposableOrgProvisionerInterface
{
    public function __construct(private CreateOrganizationUseCaseInterface $createOrg)
    {
    }

    public function provision(string $slug, string $template): ProvisionedDemoOrg
    {
        try {
            $org = $this->createOrg->execute(null, new CreateOrganizationInput(
                name: $this->companyName($template),
                slug: $slug,
                adminEmail: 'admin@' . $slug . '.demo.local',
                adminPassword: SecureTokenHelper::generate(16),
            ));
        } catch (OrganizationSlugConflictException $e) {
            throw new SlugConflictException($slug, previous: $e);
        }

        return new ProvisionedDemoOrg($org->id, $org->slug, $org->adminUserId);
    }
}
```

## 4. Implementar o seeder

O conteúdo de seed é inteiramente seu. Duas regras rígidas:

- **Escreva através de UMA conexão injetada** — o mesmo executor que a requisição já usa.
  Um segundo PDO para o mesmo banco de dados causa deadlock no SQLite (`database is locked`).
- **Toda linha carrega o `$orgId` explícito** — a rota de demo não tem organização na entrada, então
  a semeadura é uma escrita cross-tenant deliberada na organização que você acabou de criar. Nunca dependa de
  um holder de tenant com escopo de requisição aqui.

```php
final class DemoDataSeeder implements DemoDataSeederInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
        private readonly ClockInterface $clock,
    ) {
    }

    public function seed(int $orgId, DemoTemplateKeyInterface $template): void
    {
        // insira clientes, itens, documentos ... ancorados em $this->clock->now()
    }
}
```

Ancore as datas semeadas no clock injetado (datas relativas "deste mês" mantêm o demo
com aparência atual) e limite eventos históricos até hoje.

## 5. Implementar o seater

É aqui que vive a autenticação do seu produto, totalmente isolada. Um produto com sessão por cookie
emite seus cookies de login com escopo do novo tenant e faz 302 para a SPA; um
produto com JWT bearer leva a SPA ao destino do seu próprio jeito:

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* cookie de sessão com escopo do tenant */);
    }
}
```

Mantenha a semântica de sessão específica do produto (ex.: um cookie de uso único em que recarregar a página cai
na tela de login) dentro desta classe — ela não deve vazar para a orquestração.

## 6. Implementar o reaper

> **Aviso**: o caso de uso típico de "excluir organização" **não** faz cascata para as tabelas
> filhas — excluir apenas a linha da organização deixa filhos órfãos para sempre. O reaper é dono do
> teardown completo, e o framework deliberadamente não tenta adivinhar o seu schema.

Exclua as linhas filhas primeiro (incluindo netos alcançáveis apenas através de um pai), depois
a organização e, por fim, qualquer resíduo fora do banco de dados (arquivos de marcação, caches). `reap()` deve ser
**idempotente**: uma organização já varrida por uma execução concorrente é sucesso, não erro —
`DisposableDemoSweeper` depende disso e não captura suas exceções.

```php
final readonly class DemoOrgReaper implements DisposableOrgReaperInterface
{
    public function reap(int $orgId): void
    {
        foreach (self::CHILD_TABLES as $table) {
            $this->query->execute("DELETE FROM {$table} WHERE organization_id = ?", [$orgId]);
        }

        try {
            $this->deleteOrg->execute(null, $orgId);
        } catch (OrganizationNotFoundException) {
            // já removida (varredura concorrente) — sucesso idempotente
        }
    }
}
```

## 7. Conectar o handler e a rota

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // Injete a contagem — o framework não tem conhecimento do seu schema de tenants.
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // armazenamento compartilhado em produção!
);

$handler = new StartDisposableDemoHandler(
    $config->demo,
    $guard,
    new DemoOrgProvisioner($createOrg),
    new DemoDataSeeder($query, $clock),
    new DemoSessionSeater(...),
    $problemDetails,
    DemoTemplate::class,
);

(new DemoRouteRegistrar($handler))($router);   // GET /demo/{template}
```

O endpoint é público e sem organização por design (ele *cria* organizações). Se o seu produto tem
middleware de resolução de tenant, isente `/demo/...` da resolução de organização.

> **Aviso**: as mesmas ressalvas de [Adicionar limitação de taxa](add-rate-limiting.md) se aplicam ao
> throttle do guard: `InMemoryRateLimitStorage` não compartilha estado entre workers PHP-FPM
> (use Redis/Memcached/DB em produção) e, atrás de um reverse proxy, injete um
> `keyExtractor` que leia o seu cabeçalho confiável de IP encaminhado — caso contrário, todos os clientes
> compartilham um único bucket.

O throttle do guard tem como padrão **30 inícios de demo por hora por IP de cliente**.
Ao ajustar `throttleLimit`, lembre-se de que esse estilo de demo é one-shot por design —
"resetar a demo" significa clicar de novo no link, e cada clique consome um início — e de
que o NAT de escritórios e operadoras móveis coloca muitos visitantes legítimos atrás de
um único IP. Um limite de 10/h esgotou o uso normal em produção; não use um valor menor.

## 8. Opcional: aplicar sua marca à página de erro do navegador

A rota de início da demo é a única rota que pessoas reais abrem em um **navegador** (um
prospect de vendas clicando em um link de indicação). Por isso o handler negocia o
conteúdo de seus erros: quando o cabeçalho `Accept` da requisição contém `text/html`, o
JSON Problem Details 4xx/5xx é substituído por uma página HTML vinda do
`DemoErrorPageRendererInterface` que você injeta. O padrão é o
`MinimalDemoErrorPageRenderer` incluído — um cartão mínimo, sem marca, em inglês — então
funciona de imediato; substitua-o para fornecer os textos, o idioma e a marca do seu
produto:

```php
final readonly class BrandedDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        // Textos fixos por status; converta $retryAfterSeconds em "tente novamente em ~N minutos".
    }
}

$handler = new StartDisposableDemoHandler(
    // ... como no passo 7 ...
    errorPageRenderer: new BrandedDemoErrorPageRenderer($responseFactory),
);
```

O framework impõe os invariantes de transporte independentemente do renderer conectado:
a página mantém o status de erro original e o cabeçalho `Retry-After` original (429), e
recebe `X-Robots-Tag: noindex`. Clientes de API (sem `text/html` no `Accept`) e o
redirect de sucesso permanecem idênticos byte a byte.

Duas regras rígidas para renderers personalizados:

- **Nunca coloque entrada da requisição na página.** A interface recebe deliberadamente
  apenas o código de status e os segundos de retry — todo o texto deve ser texto fixo
  mais números calculados no servidor, ou a página de erro vira um vetor de XSS. Inclua
  também `<meta name="robots" content="noindex">` e não referencie assets externos.
- **Cuidado com a Content-Security-Policy.** Sua aplicação quase certamente executa o
  `SecurityHeadersMiddleware` com um `default-src 'self'` global, que **bloqueia os
  `<style>`/`<script>` inline de que uma página de erro autocontida precisa** — a página
  aparece como texto puro sem estilo. Esse middleware só adiciona cabeçalhos ausentes,
  então envie uma CSP específica da página na resposta do renderer:

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'
  ```

  Adicione `script-src 'unsafe-inline'` apenas se a página realmente tiver um script
  (por exemplo, uma contagem regressiva de retry). As permissões inline são seguras aqui
  precisamente porque a página não contém nenhuma entrada da requisição. O renderer
  incluído já envia essa CSP.

Se você precisa de mais do que uma página de erro diferente — gates adicionais, logging,
pós-processamento de respostas — o `DemoRouteRegistrar` aceita qualquer
`RequestHandlerInterface` PSR-15, então você pode envolver o `StartDisposableDemoHandler`
em um decorator em vez de reimplementar o registro da rota.

## 9. Varrer via cron

```php
// tools/sweep-demo.php — execute a cada hora
$sweeper = new DisposableDemoSweeper($config->demo, new DemoOrgReaper(...), new UtcClock());

$rows = $query->fetchAll(
    'SELECT id, created_at FROM organizations WHERE slug LIKE ?',
    [$config->demo->slugPrefix . '%'],
);
$report = $sweeper->sweep(array_map(
    static fn (array $row): DemoOrgRecord => new DemoOrgRecord(
        (int) $row['id'],
        new DateTimeImmutable((string) $row['created_at']),
    ),
    $rows,
));

echo count($report->reapedOrgIds) . " demo orgs swept\n";
```

Dois critérios se combinam: organizações mais antigas que `DEMO_TTL_HOURS` expiram, e apenas as
`DEMO_MAX_ORGS` mais novas sobrevivem independentemente da idade (seguro contra crescimento descontrolado). O sweeper só vê
os registros que você passa a ele — o filtro `LIKE 'demo-%'` da sua consulta é o que protege as organizações
reais, portanto nunca o amplie.

---

## Superfície HTTP

| Situação | Resposta |
|---|---|
| `DEMO_MODE` desativado | 404 `not-found` (indistinguível de rota inexistente) |
| `{template}` desconhecido | 404 `not-found` |
| Throttle por IP excedido | 429 `too-many-requests` + `Retry-After` |
| Teto de organizações de demo atingido | 503 `demo-capacity-exceeded` |
| Todas as tentativas de slug colidiram | `SlugConflictException` escapa → 500 via middleware de erro |
| Sucesso | o que quer que o seu seater retorne (tipicamente 302 + `Cache-Control: no-store`) |

Clientes de API recebem Problem Details RFC 9457; clientes de navegador (`Accept`
contendo `text/html`) recebem a página de erro do passo 8 com o mesmo status e o mesmo
`Retry-After`.

## Por que proteger no momento da criação se o sweeper já limita a contagem?

A varredura sozinha limita o **estado estacionário**. Entre varreduras, um crawler ou atacante pode fazer crescer
a tabela de tenants sem limite — cada início de demo escreve uma organização mais todos os seus dados de seed.
`CountingDemoCapacityGuard` fecha essa lacuna verificando o teto e a taxa por cliente
**antes que qualquer coisa seja criada**.
