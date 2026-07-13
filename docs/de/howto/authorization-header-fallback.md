# Bearer-Auth hinter Proxys wiederherstellen, die `Authorization` entfernen

Manche Front-Proxys von Shared-Hosting-Anbietern entfernen den Standard-Header
`Authorization`, bevor die Anfrage PHP erreicht (in Produktion auf Hosting der
HETEML-Klasse beobachtet). Eigene Header kommen durch, `Authorization` nicht — daher
scheitern auch die üblichen Rettungstricks:

- `.htaccess` `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` — nutzlos,
  Apache sieht den Header nie;
- `CGIPassAuth on` — aus demselben Grund.

Die Folge: Jeder Bearer-geschützte Endpunkt antwortet mit 401 `missing_token`, obwohl der
Browser ein vollkommen gültiges Token gesendet hat.

NENE2 liefert eine standardisierte zweiteilige Lösung mit (siehe ADR 0019):

1. **Frontend**: `@hideyukimori/nene2-client` (≥ 1.1.0) spiegelt das Token bei jeder
   Anfrage zusätzlich zum Standard-Header in `X-Authorization: Bearer <token>`.
2. **Backend**: `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` übernimmt den
   Spiegel **nur, wenn `Authorization` fehlt oder leer ist**. Hosts, die den
   Standard-Header durchreichen, bleiben Byte für Byte unberührt.

---

## In der Standard-Pipeline aktivieren

Ein Opt-in-Flag an der `RuntimeApplicationFactory`:

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // standardmäßig aus
))->create();
```

Aktiviert läuft der Fallback am Anfang der Auth-Stufe — vor der Maschinen-API-Key-Prüfung
und vor jeder injizierten Auth-Middleware —, sodass jede Middleware, die Anmeldedaten
liest, den wiederhergestellten Header sieht. Er ist methoden- und pfadunabhängig.

## Oder manuell verdrahten

In einer selbst zusammengesetzten Pipeline an beliebiger Stelle vor der Auth-Middleware
platzieren:

```php
$stack = [
    // ... Request-ID, Logging, Security-Header, CORS, Fehlerbehandlung ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

Außerhalb einer PSR-15-Pipeline steht die Transformation als statischer Helfer bereit:

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## Wann Sie es NICHT aktivieren dürfen

Mit aktiviertem Fallback ist `X-Authorization` als Anmeldedaten gleichwertig zu
`Authorization`. Das ist genau richtig auf Hosts, die den Header *versehentlich*
entfernen — und genau falsch, wo eine vorgelagerte Instanz ihn *absichtlich* entfernt:

- ein Gateway, das selbst authentifiziert und eine vertrauenswürdige Identität
  weiterreicht;
- eine WAF, die eingehende Anmeldedaten nicht vertrauenswürdiger Clients filtert.

In solchen Setups wäre der Spiegel ein vom Client kontrollierter Bypass. Lassen Sie das
Flag entweder aus, oder lassen Sie die vorgelagerte Instanz auch `X-Authorization`
entfernen.

Behandeln Sie `X-Authorization` außerdem in Access-Logs und Zwischen-Proxys mit derselben
Vertraulichkeit wie `Authorization`.

## Hinweise

- Der Header-Name ist **fest** (`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`,
  `X-Authorization`). Er ist ein flottenweiter Verdrahtungsvertrag mit dem
  Frontend-Client, kein Einstellknopf.
- Der Spiegelwert wird unverändert übernommen (inklusive `Bearer <token>`). Die
  Token-Validierung bleibt vollständig Aufgabe Ihrer Auth-Middleware — ein unbrauchbarer
  Spiegel scheitert genau wie ein unbrauchbarer Standard-Header.
- Die Rangfolge ist immer: Ein nicht leerer `Authorization`-Header gewinnt; der Spiegel
  wird nur herangezogen, wenn der Standard-Header fehlt oder leer ist.
