# Warum PSR-Standards?

NENE2 ist auf PSR-7, PSR-15 und PSR-17 aufgebaut, anstatt auf eigene HTTP-Abstraktionen. Diese Seite erklärt die Begründung.

## Was diese Standards abdecken

| Standard | Was er definiert |
|----------|-----------------|
| PSR-7 | `RequestInterface`, `ResponseInterface`, `StreamInterface` — die Form von HTTP-Nachrichten |
| PSR-15 | `MiddlewareInterface`, `RequestHandlerInterface` — wie Middleware und Handler zusammengesetzt werden |
| PSR-17 | Factory-Interfaces zum Erstellen von PSR-7-Objekten |

## Warum keine eigene Abstraktion?

Eine eigene `Request`-Klasse ist schnell geschrieben und einfach zu kontrollieren. Die Kosten zeigen sich später:

- Jede neue HTTP-Bibliothek benötigt einen eigenen Adapter.
- Middleware, die für ein Projekt geschrieben wurde, kann nicht in ein anderes verschoben werden.
- Tests erfordern entweder einen laufenden HTTP-Server oder die eigene Klasse selbst.

PSR-7-Objekte sind unveränderliche Value Objects. Ein Handler, der `ServerRequestInterface` akzeptiert und `ResponseInterface` zurückgibt, macht keine Annahmen über das Framework, das ihn aufruft.

## Warum unveränderliche Nachrichten?

PSR-7-Nachrichten sind unveränderlich: `withHeader()`, `withBody()` und ähnliche Methoden geben eine neue Instanz zurück, anstatt die vorhandene zu mutieren. Dies eliminiert eine Klasse von Bugs, bei denen Middleware stillschweigend eine Anfrage modifiziert, die ein späterer Handler inspiziert.

```php
// Jede Middleware erhält eine saubere Kopie — das Original bleibt unverändert
$request = $request->withAttribute('request_id', $id);
```

## Warum PSR-15-Middleware?

PSR-15 definiert den Middleware-Vertrag mit einer einzigen Methode:

```php
public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $next
): ResponseInterface
```

Das bedeutet:

- Jede PSR-15-Middleware kann in jede PSR-15-Pipeline eingefügt werden.
- Die Pipeline-Reihenfolge ist expliziter Code, kein versteckter Framework-Lebenszyklus.
- Das Unit-Testen einer Middleware erfordert nur einen Mock `RequestHandlerInterface`, keinen laufenden Server.

## Konkrete Paketauswahl

NENE2 verwendet **Nyholm PSR-7** für Nachrichtenobjekte und **Relay** für den Middleware-Dispatcher (siehe ADR 0001).

## Kompromisse

| Vorteil | Kosten |
|---------|--------|
| Interoperable Middleware | Ausführlicher als eine fließende eigene API |
| Unveränderliche Nachrichten reduzieren Bugs | Objekterstellung bei jedem `with*`-Aufruf |
| Testbar ohne Server | Erfordert das Verstehen von PSR-Interfaces |
