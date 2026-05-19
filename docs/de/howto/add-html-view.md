# HTML-Ansichten hinzufügen

Diese Anleitung zeigt, wie Sie mit `NativePhpViewRenderer` und `HtmlResponseFactory` serverseitig gerenderte HTML-Antworten zu einer NENE2-Anwendung hinzufügen.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung mit mindestens einer Route. Falls nicht, beginnen Sie mit [Eine benutzerdefinierte Route hinzufügen](./add-custom-route.md).

---

## Übersicht

NENE2 liefert eine minimale, abhängigkeitsfreie HTML-Rendering-Schicht:

| Klasse | Rolle |
|---|---|
| `NativePhpViewRenderer` | Rendert `.php`-Templates in einem isolierten Scope |
| `HtmlEscaper` | Maskiert Werte für sichere HTML-Ausgabe (`htmlspecialchars` / UTF-8 / vollständige Anführungszeichen) |
| `HtmlResponseFactory` | Verpackt gerendertes HTML in eine `text/html; charset=utf-8` PSR-7-Antwort |
| `TemplateNotFoundException` | Wird geworfen, wenn eine Template-Datei fehlt oder der Pfad ungültig ist |

HTML-Antworten koexistieren mit JSON-Endpunkten — fügen Sie sie zur gleichen Anwendung hinzu, ohne bestehende Routen zu entfernen.

---

## 1. Templates erstellen

Legen Sie native PHP-Template-Dateien in einem einzigen Stammverzeichnis ab. Der übliche Ort ist `templates/` im Projektstamm.

> **Sicherheit**: Rufen Sie immer `$e(...)` für Werte auf, die aus Benutzereingaben, einer Datenbank oder einem externen System stammen. Das Weglassen führt zu XSS-Schwachstellen.

---

## 2. Renderer im ServiceProvider verdrahten

Registrieren Sie `NativePhpViewRenderer` und `HtmlResponseFactory` in Ihrem `ServiceProviderInterface`:

```php
$builder->set(NativePhpViewRenderer::class, static fn () =>
    new NativePhpViewRenderer(dirname(__DIR__) . '/templates')
);
$builder->set(HtmlResponseFactory::class, static fn ($c) =>
    new HtmlResponseFactory($c->get(ResponseFactoryInterface::class), $c->get(StreamFactoryInterface::class), $c->get(NativePhpViewRenderer::class))
);
```

---

## 3. HtmlResponseFactory im Handler verwenden

```php
final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', ['title' => 'Willkommen']);
    }
}
```

---

## 4. Route registrieren

```php
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. TemplateNotFoundException behandeln

Registrieren Sie einen Domain-Ausnahme-Handler, der eine sinnvolle HTTP-Antwort zurückgibt, wenn ein Template nicht gefunden wird.

---

## 6. HTML und JSON-Endpunkte mischen

JSON- und HTML-Endpunkte koexistieren im gleichen `Router` ohne spezielle Konfiguration.

---

## Design-Hinweise

- Templates laufen in einem Closure-Scope — kein Zugriff auf `$this`.
- `HtmlEscaper` verwendet `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`.
- Verzeichnis-Traversal (`../`) wird im Pfadauflösungsschritt blockiert.

---

## Nächste Schritte

- [Eine benutzerdefinierte Route hinzufügen](./add-custom-route.md)
- [Rate-Limiting hinzufügen](./add-rate-limiting.md)
- [JWT-Authentifizierung hinzufügen](./add-jwt-authentication.md)
