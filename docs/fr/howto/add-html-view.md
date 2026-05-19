# Ajouter des vues HTML

Ce guide explique comment ajouter des réponses HTML rendues côté serveur à une application NENE2
en utilisant `NativePhpViewRenderer` et `HtmlResponseFactory`.

**Prérequis** : Vous avez une application NENE2 fonctionnelle avec au moins une route. Sinon, commencez par [Ajouter une route personnalisée](./add-custom-route.md).

---

## Vue d'ensemble

NENE2 fournit une couche de rendu HTML minimale sans dépendances externes :

| Classe | Rôle |
|---|---|
| `NativePhpViewRenderer` | Rend les templates `.php` dans une portée isolée |
| `HtmlEscaper` | Échappe les valeurs pour une sortie HTML sûre (`htmlspecialchars` / UTF-8 / guillemets complets) |
| `HtmlResponseFactory` | Encapsule le HTML rendu dans une réponse PSR-7 `text/html; charset=utf-8` |
| `TemplateNotFoundException` | Levée si le fichier template n'existe pas ou si le chemin est invalide |

Les réponses HTML coexistent avec les endpoints JSON. Vous pouvez les ajouter à la même application sans supprimer les routes existantes.

---

## 1. Créer les templates

NENE2 s'attend à ce que les fichiers templates PHP natifs soient placés sous un seul répertoire racine. L'emplacement standard est `templates/` à la racine du projet.

```
my-app/
├── templates/
│   ├── layout.php       (layout partagé optionnel)
│   ├── home.php
│   └── notes/
│       ├── index.php
│       └── show.php
```

Ce que chaque template reçoit :

- `$e` — assistant d'échappement (`HtmlEscaper::escape()`). Toujours l'utiliser pour les valeurs utilisateur.
- Chaque clé du tableau `$data` passé à `render()` est injectée comme variable individuelle.

**`templates/home.php`**

```php
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title><?= $e($title) ?></title></head>
<body>
  <h1><?= $e($title) ?></h1>
  <p><?= $e($description) ?></p>
</body>
</html>
```

**`templates/notes/index.php`**

```php
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Liste des notes</title></head>
<body>
  <h1>Notes</h1>
  <ul>
    <?php foreach ($notes as $note): ?>
      <li><a href="/notes/<?= $e($note['id']) ?>"><?= $e($note['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</body>
</html>
```

> **Sécurité** : Utilisez toujours `$e(...)` pour les valeurs provenant de l'utilisateur, de la base de données ou de systèmes externes. L'omettre crée des vulnérabilités XSS.

---

## 2. Câbler le renderer dans le ServiceProvider

Enregistrez `NativePhpViewRenderer` et `HtmlResponseFactory` dans le `ServiceProviderInterface` de votre application :

```php
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                NativePhpViewRenderer::class,
                static function (ContainerInterface $container): NativePhpViewRenderer {
                    return new NativePhpViewRenderer(dirname(__DIR__) . '/templates');
                },
            )
            ->set(
                HtmlResponseFactory::class,
                static function (ContainerInterface $container): HtmlResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory   = $container->get(StreamFactoryInterface::class);
                    $renderer        = $container->get(NativePhpViewRenderer::class);

                    return new HtmlResponseFactory($responseFactory, $streamFactory, $renderer);
                },
            );
    }
}
```

---

## 3. Utiliser HtmlResponseFactory dans un handler

Injectez `HtmlResponseFactory` dans votre handler et appelez `create()` :

```php
use Nene2\View\HtmlResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', [
            'title'       => 'Bienvenue',
            'description' => 'Application construite avec NENE2.',
        ]);
    }
}
```

Signature de `create()` :

```php
public function create(
    string $template,           // chemin relatif depuis templateRoot
    array  $data    = [],       // variables transmises au template
    int    $status  = 200,      // code de statut HTTP
    array  $headers = [],       // en-têtes de réponse supplémentaires
): ResponseInterface
```

---

## 4. Enregistrer les routes

```php
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
$router->get('/notes', new NoteListHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. Gérer TemplateNotFoundException

`NativePhpViewRenderer::render()` lève `TemplateNotFoundException` dans ces cas :

- Le fichier template n'existe pas
- Le chemin du template est vide
- Le chemin du template contient `..` (traversée de répertoire bloquée)

Enregistrez un handler d'exception de domaine pour retourner une réponse HTTP appropriée :

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\View\TemplateNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TemplateNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(private ProblemDetailsResponseFactory $problemDetails) {}

    public function handles(\Throwable $exception): bool
    {
        return $exception instanceof TemplateNotFoundException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create($request, 'not-found', 'Not Found', 404);
    }
}
```

---

## 6. Mélanger des endpoints HTML et JSON

```php
$registerRoutes = static function (Router $router) use ($container): void {
    // API JSON
    $router->get('/api/notes',     $container->get(NoteListJsonHandler::class));
    $router->post('/api/notes',    $container->get(NoteCreateHandler::class));

    // Vues HTML
    $router->get('/',              $container->get(HomeHandler::class));
    $router->get('/notes',         $container->get(NoteListHtmlHandler::class));
    $router->get('/notes/{id}',    $container->get(NoteShowHtmlHandler::class));
};
```

---

## 7. Tester les handlers HTML

```php
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

public function testHomeReturnsHtml(): void
{
    $templateRoot = sys_get_temp_dir() . '/test-templates-' . bin2hex(random_bytes(4));
    mkdir($templateRoot);
    file_put_contents($templateRoot . '/home.php', '<h1><?= $e($title) ?></h1>');

    $psr17   = new Psr17Factory();
    $factory = new HtmlResponseFactory($psr17, $psr17, new NativePhpViewRenderer($templateRoot));
    $handler = new HomeHandler($factory);

    $response = $handler(new ServerRequest('GET', '/'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    self::assertStringContainsString('<h1>Bienvenue</h1>', (string) $response->getBody());

    unlink($templateRoot . '/home.php');
    rmdir($templateRoot);
}
```

---

## Notes de conception

- Les templates s'exécutent dans une portée de closure, ils n'ont donc pas accès à `$this` ni aux internes de classe. Les variables sont injectées via `extract()` + `EXTR_SKIP` (les variables existantes ne sont pas écrasées).
- `HtmlEscaper` utilise `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`. `"` et `'` sont tous deux échappés, et les séquences UTF-8 invalides sont substituées plutôt que supprimées.
- La traversée de répertoire (`../`) est bloquée à l'étape de résolution de chemin. Aucun accès au système de fichiers n'a lieu avant cette vérification.

Pour les garanties d'API stable de `Nene2\View\*`, consultez [ADR 0009](../adr/0009-v1.0-public-api-scope.md).

---

## Étapes suivantes

- [Ajouter une route personnalisée](./add-custom-route.md)
- [Ajouter la limitation de débit](./add-rate-limiting.md)
- [Ajouter l'authentification JWT](./add-jwt-authentication.md)
