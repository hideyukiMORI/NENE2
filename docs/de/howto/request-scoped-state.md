# Anfrage-gebundenen Zustand zwischen Middleware und Handlern weitergeben

Einige Middlewares extrahieren einen Wert aus der eingehenden Anfrage — eine Tenant-ID, einen dekodierter JWT-Claim, einen Trace-Kontext — und Route-Handler benötigen diesen Wert nachgelagert. Diese Anleitung zeigt das empfohlene Muster mit `RequestScopedHolder`.

## Das Holder-Muster

`RequestScopedHolder<T>` ist ein kleiner, veränderbarer Container. Injizieren Sie **eine gemeinsame Instanz** sowohl in die Middleware, die ihn schreibt, als auch in den Handler (oder das Repository), der ihn liest:

```php
use Nene2\Http\RequestScopedHolder;

// Gemeinsame Instanz — einmalig an der Composition-Root verdrahtet.
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// Middleware schreibt ihn.
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// Route-Handler liest ihn.
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
$id = $this->teamId->get();  // lesen — wirft LogicException, wenn Middleware nicht ausgeführt wurde
```

## Warum nicht PSR-7-Request-Attribute?

PSR-7-Request-Attribute sind unveränderlich — jeder `withAttribute()`-Aufruf gibt eine neue Instanz zurück. Um ein Attribut von der Middleware an einen Handler weiterzugeben, müssen Sie das neue Request-Objekt durch die gesamte Aufrufkette fädeln, was der Dispatcher von NENE2 bereits tut. Die Verwendung von `withAttribute()` ist in Ordnung, wenn der nachgelagerte Code den `$request` direkt erhält.

`RequestScopedHolder` ist das richtige Werkzeug, wenn der nachgelagerte Konsument den `$request` **nicht** erhält — zum Beispiel ein Repository, das nur Ihre Domain-Typen kennt und keinen PSR-7-Request als Abhängigkeit akzeptieren kann.

## Mehrere Middlewares stapeln

Übergeben Sie eine Liste an `RuntimeApplicationFactory::$authMiddleware`, um mehrere Middlewares nacheinander auszuführen:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // läuft zuerst
        new BearerTokenMiddleware($probs, $verifier), // läuft zweites
    ],
))->create();
```

Beide Middlewares teilen dieselbe Pipeline-Position (nach dem Anfragengrößenlimit, vor der Ratenbegrenzung). Das erste Element der Liste verarbeitet die Anfrage vor dem zweiten.

## Sicherheit: PHPs Shared-Nothing-Modell

In PHP-FPM und CLI läuft jede Anfrage in einem frischen Prozess. Der `RequestScopedHolder`-Wert, der während Anfrage A gesetzt wird, ist für Anfrage B nie sichtbar, da jeder Prozess nach der Behandlung einer Anfrage beendet wird. Der Holder ist unter diesem Modell sicher zu verwenden.

### Async-Runtimes (Swoole, ReactPHP, FrankenPHP Worker-Modus)

Wenn mehrere Anfragen denselben PHP-Prozess teilen, behält ein während Anfrage A geschriebener Holder seinen Wert bis in Anfrage B bei, sofern er nicht explizit geleert wird. `reset()` zu Beginn (oder Ende) jedes Anfragezyklus aufrufen:

```php
// Beispiel Swoole-Request-Handler
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // Wert der vorherigen Anfrage löschen
    $psrRequest = /* konvertieren */;
    $psrResponse = $app->handle($psrRequest);
    // $psrResponse ausgeben ...
});
```

NENE2 zielt derzeit auf PHP-FPM / CLI und wird nicht mit eingebautem Async-Support ausgeliefert. Wenn Sie eine Async-Runtime verwenden, sind Sie für das Zurücksetzen gemeinsamer Holder zwischen Anfragen verantwortlich.
