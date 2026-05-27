# Einen Domain-Exception-Handler hinzufügen

Wenn ein Routen-Handler eine Domain-Exception wirft (z.B. `OrderNotFoundException`, `InsufficientStockException`),
delegiert NENE2s `ErrorHandlerMiddleware` an den ersten registrierten `DomainExceptionHandlerInterface`,
der sich für die Behandlung dieses Exception-Typs zuständig erklärt. Dadurch bleiben Routen-Handler
frei von try/catch-Blöcken und die Fehlerserialisierung ist an einem zentralen Ort.

## 1. Die Domain-Exception definieren

```php
// src/Order/OrderNotFoundException.php
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Order #{$id} not found.");
    }
}
```

## 2. DomainExceptionHandlerInterface implementieren

```php
// src/Order/OrderNotFoundExceptionHandler.php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class OrderNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $probs,
    ) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof OrderNotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,        // ← erforderlich: wird zur Befüllung von 'instance' in der Antwort verwendet
            type: 'not-found',        // ← nur Slug — die Factory stellt die Basis-URL voran
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### Häufige Fehler

**Fehlendes `$request`** — `ProblemDetailsResponseFactory::create()` erfordert die PSR-7-Anfrage
als erstes Argument. Das Weglassen verursacht einen Laufzeit-`ArgumentCountError`.

**Vollständige URL in `type`** — `type` nimmt einen Slug (z.B. `'not-found'`), nicht die vollständige URI.
Die Factory stellt `https://nene2.dev/problems/` (oder die konfigurierte Basis-URL) automatisch voran.
Das Übergeben der vollständigen URL erzeugt einen doppelten Pfad wie
`https://nene2.dev/problems/https://nene2.dev/problems/not-found`.

**Korrekte Signatur:**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // Slug
    title: 'Not Found',  // menschenlesbarer Titel
    status: 404,         // HTTP-Statuscode
    detail: '...',       // optionaler Detail-String
);
```

## 3. Den Handler in der RuntimeApplicationFactory registrieren

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // Handler werden der Reihe nach geprüft — der erste Treffer gewinnt
    ],
))->create();
```

## 4. Aus dem Routen-Handler werfen

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

`ErrorHandlerMiddleware` fängt die Exception ab, durchläuft die `$domainExceptionHandlers`-Liste,
ruft `supports()` für jeden Handler auf und delegiert an den ersten Treffer. Wenn kein Handler passt,
wird die Exception als unerwarteter Server-Fehler behandelt (500).

## Antwortstruktur

Eine 404-Antwort, die durch das obige Beispiel erzeugt wird:

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` wird automatisch aus `$request->getUri()->getPath()` befüllt.

## Fehlerbehebung: 500 statt erwartetem Fehlercode

Wenn eine Domain-Exception einen **500 Internal Server Error** statt des erwarteten
4xx-Antwortcodes erzeugt, ist die häufigste Ursache ein fehlender oder falsch registrierter Handler:

1. **Handler nicht zu `domainExceptionHandlers` hinzugefügt** — überprüfen Sie, ob die Handler-Klasse
   im Array enthalten ist, das an `RuntimeApplicationFactory` übergeben wird.
2. **`supports()`-Methode stimmt nicht überein** — stellen Sie sicher, dass `supports()` für die genaue
   Exception-Klasse prüft, die tatsächlich geworfen wird. Wenn die geworfene Exception eine Unterklasse
   ist und `supports()` `instanceof ExactClass` verwendet, passen auch Kind-Klassen-Exceptions. Aber wenn
   die Klassenhierarchie umgekehrt ist (Handler prüft einen Elternteil, Exception ist ein anderer Zweig),
   passt kein Handler.
3. **Handler registriert, aber falsche Reihenfolge** — Handler werden der Reihe nach geprüft. Wenn ein
   Catch-all-Handler zuerst erscheint und sein `supports()` zu breit ist, kann er Exceptions verschlucken,
   die ein späterer Handler behandeln sollte.

Zur schnellen Diagnose: vorübergehend `error_log(get_class($exception))` vor der `supports()`-Prüfung
hinzufügen, um den tatsächlichen Exception-Klassennamen auszugeben.
