# How-to: Eine Einweg-Demo hinzufügen

Diese Anleitung zeigt, wie Sie Ihrem Produkt eine **Einweg-Demo im Rechnungsstil** geben: Ein Besucher
öffnet `GET /demo/{template}`, die Anwendung provisioniert eine brandneue Wegwerf-Organisation, befüllt
sie mit realistischen Branchendaten, richtet eine authentifizierte Session ein und leitet per 302-Redirect
in den frischen Tenant weiter. Ein Cron-Sweeper zerstört Demo-Organisationen nach Ablauf einer TTL.
„Demo zurücksetzen" bedeutet einfach, die URL erneut aufzurufen — jedes Mal eine neue Organisation.

Das Framework-Modul ist `Nene2\Demo`. Es besitzt die produktunabhängige Orchestrierung
(Gate → Throttle/Kapazität → Slug-Zuweisung mit Konflikt-Retry → Provisionierung → Seeding → Session-Einrichtung)
sowie die Sweep-Entscheidung (TTL + Überlauf). Sie implementieren vier kleine Interfaces, die
alles Produktspezifische tragen.

**Voraussetzung**: eine funktionierende NENE2-Anwendung mit einem mandantenfähigen Organisationsmodell
(Organisationen identifiziert per Slug) und einer Möglichkeit, eine Organisation zu erstellen und zu löschen.

---

## Was das Framework bereitstellt vs. was Sie implementieren

| Framework (`Nene2\Demo`) | Sie (das Produkt) |
|---|---|
| `StartDisposableDemoHandler` — die HTTP-Orchestrierung | `DisposableOrgProvisionerInterface` — eine Demo-Organisation + Admin erstellen |
| `DisposableDemoSweeper` — TTL-/Überlauf-Entscheidung, `SweepReport` | `DisposableOrgReaperInterface` — eine Organisation **samt ihren Kindern** zerstören |
| `CountingDemoCapacityGuard` — Obergrenze zur Erstellungszeit + Pro-IP-Throttle | `DemoSessionSeaterInterface` — Auth-Übergabe + Redirect |
| `DemoConfig` — typisierte `DEMO_*`-Einstellungen auf `AppConfig::$demo` | `DemoDataSeederInterface` — Branchen-Seed-Daten |
| `DemoRouteRegistrar` — registriert `GET /demo/{template}` | `DemoTemplateKeyInterface` — Ihr Template-Enum |
| `MinimalDemoErrorPageRenderer` — markenfreie Browser-Fehlerseite | `DemoErrorPageRendererInterface` — Fehlerseite im eigenen Branding (optional) |

---

## 1. Konfigurieren

Die `DEMO_*`-Variablen werden vom `ConfigLoader` in `AppConfig::$demo` geladen
(ein typisiertes `Nene2\Demo\DemoConfig`) — lesen Sie sie niemals mit `getenv()`:

```bash
DEMO_MODE=1            # streng geparst: nur 1/true/yes aktivieren es; standardmäßig aus
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

Ist `DEMO_MODE` nicht gesetzt, antwortet der Endpunkt mit einem schlichten 404 — Sie können die
Verdrahtung schlafend ausliefern und sie pro Deployment aktivieren.

## 2. Den Template-Schlüssel definieren

Ein string-backed Enum Ihrer befüllbaren Branchen-Presets:

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // das {template}-URL-Segment
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

## 3. Den Provisioner implementieren

Ein dünner Wrapper um Ihren bestehenden „Organisation erstellen"-Use-Case. Werfen Sie bei einem
bereits vergebenen Slug eine `SlugConflictException` (der Handler versucht es mit einem frischen
Zufalls-Slug erneut), erzeugen Sie intern Wegwerf-Admin-Zugangsdaten und geben Sie die Admin-ID
zurück — das Framework sucht den Admin niemals über ein Rollen-Literal:

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

## 4. Den Seeder implementieren

Der Seed-Inhalt gehört ganz Ihnen. Zwei harte Regeln:

- **Schreiben Sie über EINE injizierte Verbindung** — denselben Executor, den der Request bereits
  verwendet. Ein zweites PDO auf dieselbe Datenbank verursacht unter SQLite einen Deadlock
  (`database is locked`).
- **Jede Zeile trägt die explizite `$orgId`** — die Demo-Route ist beim Eintritt organisationslos,
  das Seeding ist also ein bewusster mandantenübergreifender Schreibvorgang in die gerade erstellte
  Organisation. Verlassen Sie sich hier niemals auf einen request-gebundenen Tenant-Holder.

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
        // Kunden, Positionen, Dokumente einfügen ... verankert an $this->clock->now()
    }
}
```

Verankern Sie befüllte Datumswerte an der injizierten Clock (relative „dieser Monat"-Daten halten
die Demo aktuell wirkend) und begrenzen Sie historische Ereignisse auf heute.

## 5. Den Seater implementieren

Hier lebt die Authentifizierung Ihres Produkts, vollständig isoliert. Ein Cookie-Session-Produkt
stellt seine Login-Cookies bezogen auf den neuen Tenant aus und leitet per 302 in die SPA weiter;
ein JWT-Bearer-Produkt landet die SPA auf seine eigene Weise:

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* auf den Tenant bezogenes Session-Cookie */);
    }
}
```

Behalten Sie produktspezifische Session-Semantik (z. B. ein Einmal-Cookie, bei dem ein Neuladen
auf den Login-Bildschirm zurückführt) innerhalb dieser Klasse — sie darf nicht in die
Orchestrierung durchsickern.

## 6. Den Reaper implementieren

> **Warnung**: Der typische „Organisation löschen"-Use-Case kaskadiert **nicht** auf Kindtabellen —
> wer nur die Organisationszeile löscht, lässt verwaiste Kinder für immer zurück. Der Reaper
> besitzt den vollständigen Abriss, und das Framework versucht bewusst nicht, Ihr Schema zu erraten.

Löschen Sie zuerst Kindzeilen (einschließlich Enkeln, die nur über einen Elternteil erreichbar sind),
dann die Organisation, dann alle Rückstände außerhalb der Datenbank (Datei-Stempel, Caches).
`reap()` muss **idempotent** sein: Eine bereits von einem parallelen Lauf weggeräumte Organisation
ist ein Erfolg, kein Fehler — `DisposableDemoSweeper` verlässt sich darauf und fängt Ihre
Exceptions nicht ab.

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
            // bereits weg (paralleler Sweep) — idempotenter Erfolg
        }
    }
}
```

## 7. Handler und Route verdrahten

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // Die Zählung injizieren — das Framework kennt Ihr Tenant-Schema nicht.
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // in der Produktion gemeinsam genutzter Speicher!
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

Der Endpunkt ist bewusst öffentlich und organisationslos (er *erstellt* Organisationen). Falls
Ihr Produkt eine Tenant-Resolution-Middleware hat, nehmen Sie `/demo/...` von der
Organisationsauflösung aus.

> **Warnung**: Für das Throttle des Guards gelten dieselben Vorbehalte wie in
> [Rate-Limiting hinzufügen](add-rate-limiting.md): `InMemoryRateLimitStorage` teilt seinen
> Zustand nicht zwischen PHP-FPM-Workern (verwenden Sie in der Produktion Redis/Memcached/DB),
> und hinter einem Reverse-Proxy injizieren Sie einen `keyExtractor`, der Ihren vertrauenswürdigen
> Forwarded-IP-Header liest — andernfalls teilen sich alle Clients einen einzigen Bucket.

Das Throttle des Guards erlaubt standardmäßig **30 Demo-Starts pro Stunde und Client-IP**.
Wenn Sie `throttleLimit` anpassen, bedenken Sie, dass dieser Demo-Stil konstruktionsbedingt
one-shot ist — „Demo zurücksetzen“ heißt, den Link erneut anzuklicken, und jeder Klick
verbraucht einen Start — und dass Büro- und Mobilfunk-NAT viele legitime Besucher hinter
einer einzigen IP bündelt. Ein Limit von 10/h hat in der Produktion die normale Nutzung
ausgehungert; gehen Sie nicht darunter.

## 8. Optional: die Browser-Fehlerseite branden

Die Demo-Start-Route ist die einzige Route, die echte Menschen in einem **Browser** öffnen
(ein Vertriebs-Prospect klickt auf einen Empfehlungslink). Der Handler verhandelt seine
Fehler daher per Content-Negotiation: Enthält der `Accept`-Header der Anfrage `text/html`,
wird das 4xx/5xx-Problem-Details-JSON durch eine HTML-Seite aus dem von Ihnen injizierten
`DemoErrorPageRendererInterface` ersetzt. Standard ist der mitgelieferte
`MinimalDemoErrorPageRenderer` — eine minimale, markenfreie englische Karte —, es
funktioniert also out of the box; ersetzen Sie ihn, um Texte, Sprache und Branding Ihres
Produkts zu liefern:

```php
final readonly class BrandedDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        // Feste Texte je Status; wandeln Sie $retryAfterSeconds in „in ~N Minuten erneut versuchen“ um.
    }
}

$handler = new StartDisposableDemoHandler(
    // ... wie in Schritt 7 ...
    errorPageRenderer: new BrandedDemoErrorPageRenderer($responseFactory),
);
```

Das Framework erzwingt die Transport-Invarianten unabhängig vom verdrahteten Renderer:
Die Seite behält den ursprünglichen Fehlerstatus und den ursprünglichen
`Retry-After`-Header (429) und erhält `X-Robots-Tag: noindex`. API-Clients (kein
`text/html` im `Accept`) und der Erfolgs-Redirect bleiben byteidentisch.

Zwei harte Regeln für eigene Renderer:

- **Niemals Request-Eingaben in die Seite übernehmen.** Das Interface erhält bewusst nur
  den Statuscode und die Retry-Sekunden — alle Texte müssen aus festem Text plus
  serverseitig berechneten Zahlen bestehen, sonst wird die Fehlerseite zum XSS-Vektor.
  Fügen Sie außerdem `<meta name="robots" content="noindex">` ein und referenzieren Sie
  keine externen Assets.
- **Achten Sie auf die Content-Security-Policy.** Ihre App führt mit ziemlicher
  Sicherheit `SecurityHeadersMiddleware` mit einem app-weiten `default-src 'self'` aus,
  das **die Inline-`<style>`/`<script>` blockiert, die eine in sich geschlossene
  Fehlerseite braucht** — die Seite erscheint dann als nackter, ungestylter Text. Diese
  Middleware fügt nur fehlende Header hinzu; liefern Sie also eine seitenspezifische CSP
  auf der Renderer-Antwort mit:

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'
  ```

  Fügen Sie `script-src 'unsafe-inline'` nur hinzu, wenn die Seite wirklich ein Script
  trägt (z. B. einen Retry-Countdown). Inline-Freigaben sind hier genau deshalb sicher,
  weil die Seite keinerlei Request-Eingaben enthält. Der mitgelieferte Renderer liefert
  diese CSP bereits mit.

Wenn Sie mehr brauchen als eine andere Fehlerseite — zusätzliche Gates, Logging,
Response-Nachbearbeitung —, akzeptiert `DemoRouteRegistrar` jedes
PSR-15-`RequestHandlerInterface`; Sie können `StartDisposableDemoHandler` also in einen
Decorator wickeln, statt die Routen-Registrierung neu zu implementieren.

## 9. Per Cron sweepen

```php
// tools/sweep-demo.php — stündlich ausführen
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

Zwei Kriterien wirken zusammen: Organisationen, die älter als `DEMO_TTL_HOURS` sind, laufen ab,
und unabhängig vom Alter überleben nur die neuesten `DEMO_MAX_ORGS` (Absicherung gegen
Ausreißer). Der Sweeper sieht immer nur die Datensätze, die Sie ihm übergeben — der
`LIKE 'demo-%'`-Filter Ihrer Abfrage ist das, was echte Organisationen schützt, weiten Sie ihn
also niemals aus.

---

## HTTP-Oberfläche

| Situation | Antwort |
|---|---|
| `DEMO_MODE` aus | 404 `not-found` (nicht unterscheidbar von einer fehlenden Route) |
| Unbekanntes `{template}` | 404 `not-found` |
| Pro-IP-Throttle überschritten | 429 `too-many-requests` + `Retry-After` |
| Demo-Organisations-Obergrenze erreicht | 503 `demo-capacity-exceeded` |
| Alle Slug-Versuche kollidiert | `SlugConflictException` entkommt → 500 über die Error-Middleware |
| Erfolg | was auch immer Ihr Seater zurückgibt (typischerweise 302 + `Cache-Control: no-store`) |

API-Clients erhalten RFC 9457 Problem Details; Browser-Clients (`Accept` enthält
`text/html`) erhalten die Fehlerseite aus Schritt 8 mit demselben Status und demselben
`Retry-After`.

## Warum zur Erstellungszeit absichern, wenn der Sweeper die Anzahl bereits deckelt?

Sweeping allein deckelt nur den **stationären Zustand**. Zwischen den Sweeps kann ein Crawler
oder Angreifer die Tenant-Tabelle unbegrenzt wachsen lassen — jeder Demo-Start schreibt eine
Organisation plus ihre vollständigen Seed-Daten. `CountingDemoCapacityGuard` schließt diese
Lücke, indem er die Obergrenze und die Pro-Client-Rate prüft, **bevor irgendetwas erstellt wird**.
