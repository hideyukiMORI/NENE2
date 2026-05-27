# How-to: Request-scoped State zwischen Middleware und Handlern weitergeben

Manche Middlewares extrahieren einen Wert aus der eingehenden Anfrage — eine Mandanten-ID, einen dekodieren JWT-Claim, einen Trace-Kontext — und Route-Handler benötigen diesen Wert nachgelagert. Diese Anleitung zeigt das empfohlene Muster mit `RequestScopedHolder`.

## Das Holder-Muster

`RequestScopedHolder<T>` ist ein kleiner mutierbarer Container. Eine **gemeinsame Instanz** in sowohl die Middleware, die sie schreibt, als auch den Handler (oder das Repository), der sie liest, injizieren:

```php
use Nene2\Http\RequestScopedHolder;

// Gemeinsame Instanz — einmal am Composition-Root verdrahtet.
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// Middleware schreibt sie.
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// Route-Handler liest sie.
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

Innerhalb der Middleware:

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... validieren ...
    $this->teamId->set((int) $raw);   // schreiben
    return $handler->handle($request);
}
```

Innerhalb des Route-Handlers:

```php
$id = $this->teamId->get();  // lesen — wirft LogicException wenn Middleware nicht ausgeführt wurde
```

## Warum nicht PSR-7-Request-Attribute?

PSR-7-Request-Attribute sind unveränderlich — jeder `withAttribute()`-Aufruf gibt eine neue Instanz zurück. Um ein Attribut von einer Middleware zu einem Handler weiterzugeben, muss das neue Request-Objekt durch die gesamte Aufrufkette weitergereicht werden, was NEE2s Dispatcher bereits tut. `withAttribute()` zu verwenden ist gut, wenn der nachgelagerte Code `$request` direkt erhält.

`RequestScopedHolder` ist das richtige Werkzeug, wenn der nachgelagerte Konsument `$request` **nicht** erhält — zum Beispiel ein Repository, das nur über eigene Domain-Typen Bescheid weiß und keine PSR-7-Request-Abhängigkeit akzeptieren kann.

## Mehrere Middlewares stapeln

Eine Liste an `RuntimeApplicationFactory::$authMiddleware` übergeben, um mehrere Middlewares nacheinander auszuführen:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // läuft zuerst
        new BearerTokenMiddleware($probs, $verifier), // läuft zweiteilen
    ],
))->create();
```

Beide Middlewares teilen dieselbe Pipeline-Position (nach dem Request-Size-Limit, vor dem Rate-Limiting). Das erste Element in der Liste verarbeitet die Anfrage vor dem zweiten.

## Sicherheit: PHP Shared-Nothing-Modell

In PHP-FPM und CLI läuft jede Anfrage in einem frischen Prozess. Der während Anfrage A gesetzte `RequestScopedHolder`-Wert ist niemals für Anfrage B sichtbar, weil jeder Prozess nach der Verarbeitung einer Anfrage beendet wird. Der Holder ist unter diesem Modell sicher verwendbar.

### Async-Runtimes (Swoole, ReactPHP, FrankenPHP Worker-Modus)

Wenn mehrere Anfragen denselben PHP-Prozess teilen, behält ein während Anfrage A geschriebener Holder seinen Wert bis in Anfrage B, sofern nicht explizit zurückgesetzt. `reset()` am Anfang (oder Ende) jedes Anfrage-Zyklus aufrufen:

```php
// Beispiel Swoole-Request-Handler
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // Wert der vorherigen Anfrage löschen
    $psrRequest = /* konvertieren */;
    $psrResponse = $app->handle($psrRequest);
    // $psrResponse ausgeben ...
});
```

NENE2 zielt derzeit auf PHP-FPM / CLI und liefert keine eingebaute Async-Unterstützung. Bei Verwendung einer Async-Runtime ist man selbst für das Zurücksetzen gemeinsamer Holder zwischen Anfragen verantwortlich.
