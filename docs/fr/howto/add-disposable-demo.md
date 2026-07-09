# How-to : Ajouter une démo jetable

Ce guide montre comment doter votre produit d'une **démo jetable façon facturation** : un visiteur
ouvre `GET /demo/{template}`, l'application provisionne une toute nouvelle organisation éphémère,
la remplit avec des données sectorielles réalistes, installe une session authentifiée et redirige
en 302 vers le tenant fraîchement créé. Un balayeur cron détruit les organisations de démo après un
TTL. « Réinitialiser la démo » consiste simplement à rappeler l'URL — une nouvelle organisation à
chaque fois.

Le module du framework est `Nene2\Demo`. Il détient l'orchestration indépendante du produit
(gate → throttle/capacité → allocation de slug avec réessai en cas de conflit → provision → seed → session)
et la décision de balayage (TTL + dépassement). Vous implémentez quatre petites interfaces qui portent
tout ce qui est spécifique au produit.

**Prérequis** : une application NENE2 fonctionnelle avec un modèle d'organisation multi-tenant
(organisations identifiées par slug) et un moyen de créer et supprimer une organisation.

---

## Ce que le framework fournit vs ce que vous implémentez

| Framework (`Nene2\Demo`) | Vous (le produit) |
|---|---|
| `StartDisposableDemoHandler` — l'orchestration HTTP | `DisposableOrgProvisionerInterface` — créer une organisation de démo + admin |
| `DisposableDemoSweeper` — décision TTL / dépassement, `SweepReport` | `DisposableOrgReaperInterface` — détruire une organisation **et ses enfants** |
| `CountingDemoCapacityGuard` — plafond à la création + throttle par IP | `DemoSessionSeaterInterface` — passation d'authentification + redirection |
| `DemoConfig` — réglages `DEMO_*` typés sur `AppConfig::$demo` | `DemoDataSeederInterface` — données seed sectorielles |
| `DemoRouteRegistrar` — enregistre `GET /demo/{template}` | `DemoTemplateKeyInterface` — votre enum de templates |

---

## 1. Configurer

Les variables `DEMO_*` sont chargées par `ConfigLoader` dans `AppConfig::$demo`
(un `Nene2\Demo\DemoConfig` typé) — ne les lisez jamais avec `getenv()` :

```bash
DEMO_MODE=1            # analyse stricte : seuls 1/true/yes l'activent ; désactivé par défaut
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

Avec `DEMO_MODE` non défini, l'endpoint répond un simple 404 — vous pouvez livrer le câblage
dormant et l'activer par déploiement.

## 2. Définir la clé de template

Un enum adossé à des chaînes de vos préréglages sectoriels seedables :

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // le segment d'URL {template}
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

## 3. Implémenter le provisioner

Une fine enveloppe autour de votre use case « créer une organisation » existant. Lancez
`SlugConflictException` sur un slug déjà pris (le handler réessaie avec un nouveau slug aléatoire),
générez les identifiants admin éphémères en interne et retournez l'id de l'admin — le
framework ne recherche jamais l'admin par un littéral de rôle :

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

## 4. Implémenter le seeder

Le contenu du seed vous appartient entièrement. Deux règles strictes :

- **Écrivez via UNE SEULE connexion injectée** — le même exécuteur que la requête utilise déjà.
  Un second PDO vers la même base de données provoque un deadlock sous SQLite (`database is locked`).
- **Chaque ligne porte le `$orgId` explicite** — la route de démo est sans organisation à l'entrée,
  le seed est donc une écriture cross-tenant délibérée dans l'organisation que vous venez de créer.
  Ne comptez jamais sur un holder de tenant à portée requête ici.

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
        // insérer clients, articles, documents ... ancrés sur $this->clock->now()
    }
}
```

Ancrez les dates du seed sur l'horloge injectée (des dates relatives « ce mois-ci » gardent la démo
d'apparence actuelle) et bornez les événements historiques à aujourd'hui.

## 5. Implémenter le seater

C'est ici que vit l'authentification de votre produit, totalement isolée. Un produit à session
par cookie émet ses cookies de connexion limités au nouveau tenant et redirige en 302 vers la SPA ;
un produit à JWT bearer fait atterrir la SPA à sa manière :

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* cookie de session limité au tenant */);
    }
}
```

Gardez la sémantique de session spécifique au produit (par ex. un cookie à usage unique où un
rechargement renvoie à l'écran de connexion) à l'intérieur de cette classe — elle ne doit pas
fuir dans l'orchestration.

## 6. Implémenter le reaper

> **Avertissement** : le use case « supprimer une organisation » typique ne cascade **pas** vers
> les tables enfants — supprimer uniquement la ligne d'organisation laisse des enfants orphelins
> pour toujours. Le reaper détient le démantèlement complet, et le framework s'abstient
> délibérément de deviner votre schéma.

Supprimez d'abord les lignes enfants (y compris les petits-enfants accessibles uniquement via un
parent), puis l'organisation, puis tout résidu hors base de données (fichiers, caches). `reap()`
doit être **idempotent** : une organisation déjà balayée par une exécution concurrente est un
succès, pas une erreur — `DisposableDemoSweeper` s'appuie dessus et n'intercepte pas vos exceptions.

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
            // déjà supprimée (balayage concurrent) — succès idempotent
        }
    }
}
```

## 7. Câbler le handler et la route

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // Injectez le comptage — le framework ne connaît rien de votre schéma de tenants.
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // stockage partagé en production !
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

L'endpoint est public et sans organisation par conception (il *crée* des organisations). Si votre
produit a un middleware de résolution de tenant, exemptez `/demo/...` de la résolution d'organisation.

> **Avertissement** : les mêmes mises en garde que dans [Ajouter la limitation de débit](add-rate-limiting.md)
> s'appliquent au throttle du guard : `InMemoryRateLimitStorage` ne partage pas son état entre les
> workers PHP-FPM (utilisez Redis/Memcached/DB en production), et derrière un reverse proxy injectez
> un `keyExtractor` qui lit votre en-tête d'IP transmise de confiance — sinon tous les clients
> partagent un seul compteur.

## 8. Balayer via cron

```php
// tools/sweep-demo.php — exécuter toutes les heures
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

Deux critères se combinent : les organisations plus anciennes que `DEMO_TTL_HOURS` expirent, et
seules les `DEMO_MAX_ORGS` plus récentes survivent quel que soit leur âge (assurance anti-emballement).
Le sweeper ne voit jamais que les enregistrements que vous lui passez — le filtre `LIKE 'demo-%'`
de votre requête est ce qui protège les organisations réelles, ne l'élargissez donc jamais.

---

## Surface HTTP

| Situation | Réponse |
|---|---|
| `DEMO_MODE` désactivé | 404 `not-found` (indiscernable d'une route absente) |
| `{template}` inconnu | 404 `not-found` |
| Throttle par IP dépassé | 429 `too-many-requests` + `Retry-After` |
| Plafond d'organisations de démo atteint | 503 `demo-capacity-exceeded` |
| Toutes les tentatives de slug en conflit | `SlugConflictException` s'échappe → 500 via le middleware d'erreur |
| Succès | ce que retourne votre seater (typiquement 302 + `Cache-Control: no-store`) |

Toutes les réponses d'erreur sont des Problem Details RFC 9457.

## Pourquoi garder à la création alors que le sweeper plafonne déjà le comptage ?

Le balayage seul plafonne le **régime permanent**. Entre deux balayages, un crawler ou un attaquant
peut faire croître la table des tenants sans limite — chaque démarrage de démo écrit une organisation
plus toutes ses données seed. `CountingDemoCapacityGuard` comble cette faille en vérifiant le plafond
et le débit par client **avant que quoi que ce soit ne soit créé**.
