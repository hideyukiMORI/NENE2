# CSRF und JSON-APIs

## CORS ≠ CSRF-Schutz

Dies ist eines der häufigsten Sicherheitsmissverständnisse bei der Web-API-Entwicklung.

**CORS** (Cross-Origin Resource Sharing) steuert, ob ein Browser JavaScript auf einem Origin erlaubt, die *Antwort* von einem anderen Origin zu *lesen*. Der Server fügt `Access-Control-Allow-Origin`-Header hinzu; der Browser erzwingt die Policy.

**CSRF** (Cross-Site Request Forgery) ist ein Angriff, bei dem eine bösartige Seite den Browser des Opfers dazu verleitet, eine zustandsändernde Anfrage an eine vertrauenswürdige Site zu senden — mit den Session-Cookies des Opfers.

NENE2s `CorsMiddleware` behandelt CORS. Es blockiert **nicht** Anfragen von unbekannten Origins. Eine Anfrage mit `Origin: https://evil.example.com` geht durch und erreicht Ihren Handler unverändert — dies ist erwartetes Verhalten. CORS ist ein Browser-Schutz, der begrenzt, was *JavaScript lesen kann*, nicht was der *Server akzeptiert*.

```
# Diese alle erreichen Ihren Handler — CorsMiddleware blockiert sie NICHT
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# (kein Origin-Header — z.B. Server-zu-Server-Aufrufe)
```

## Warum JSON-APIs widerstandsfähiger gegen CSRF sind als formularbasierte APIs

Klassische CSRF-Exploits nutzen HTML-Formulare (`<form method="POST">`). Browser senden Formularübermittlungen mit `Content-Type: application/x-www-form-urlencoded` oder `multipart/form-data` — der Browser fügt Session-Cookies automatisch hinzu.

Eine Anfrage mit `Content-Type: application/json` ist **keine "einfache Anfrage"** gemäß der CORS-Spezifikation. Der Browser sendet zuerst ein Preflight-`OPTIONS`. Wenn Ihre CORS-Konfiguration den Origin des Angreifers nicht auflistet, blockiert der Browser das Preflight — die eigentliche Anfrage kommt nie an.

**Dies schützt jedoch nur browserbasierte Angriffe**. Ein Server oder ein `fetch()`-Aufruf mit expliziten Headern kann `Content-Type: application/json` ohne Einschränkung an Ihre API senden. CORS-Preflights werden von Browsern erzwungen, nicht von Servern.

## Der echte Schutz: Bearer JWT

NEE2s Standard-Authentifizierung verwendet Bearer JWTs im `Authorization`-Header:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

CSRF-Angriffe funktionieren durch den Missbrauch von Cookies — der Browser hängt sie automatisch an Cross-Site-Anfragen an. Der `Authorization`-Header wird **niemals** automatisch gesendet. Eine bösartige Seite kann das JWT eines Opfers nicht einschließen, weil JavaScript auf `https://evil.example.com` das Token von `https://app.example.com` nicht lesen kann.

Wenn Sie Bearer-JWT-Authentifizierung verwenden und niemals Tokens in Cookies ablegen, sind Sie durch Design nicht für CSRF anfällig. Kein zusätzliches CSRF-Token oder `SameSite`-Attribut ist erforderlich.

## Wenn Sie Cookie-basierte Sessions verwenden

Wenn Ihre Anwendung `Set-Cookie` für das Session-Management verwendet (statt Bearer JWT), benötigen Sie expliziten CSRF-Schutz:

### Option 1: SameSite-Cookies (einfachste Methode)

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` verhindert, dass der Browser das Cookie bei Cross-Site-Anfragen einschließt. `SameSite=Lax` ist auch ein vernünftiger Standard, der Cross-Site-`POST` trotzdem blockiert.

### Option 2: Origin-Header-Validierungs-Middleware

Anfragen ablehnen, deren `Origin` nicht zur Allowlist passt:

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Nicht-Browser-Aufrufe (curl, Server-zu-Server) haben keinen Origin — erlauben
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // Eine 403 Problem-Details-Antwort zurückgeben
            // ...
        }

        return $handler->handle($request);
    }
}
```

Nach CORS im Middleware-Stack registrieren (siehe CLAUDE.md Abschnitt 5 für Reihenfolge).

### Option 3: CSRF-Token

Generieren Sie ein Pro-Session-Token, speichern Sie es serverseitig, fügen Sie es in Formularen als verstecktes Feld ein und verifizieren Sie es bei jeder zustandsändernden Anfrage. Dies ist der traditionelle Ansatz, fügt aber Komplexität hinzu.

## Zusammenfassung

| Szenario | CSRF-Risiko | Empfohlene Maßnahme |
|----------|-------------|---------------------|
| Bearer JWT im `Authorization`-Header | Keines — Header wird nicht automatisch gesendet | Keine Aktion erforderlich |
| Cookie-Session, SameSite=Strict | Sehr gering | `SameSite=Strict` beibehalten |
| Cookie-Session, kein SameSite | Hoch | `SameSite` oder Origin-Erzwingung hinzufügen |
| API-Schlüssel in benutzerdefiniertem Header | Keines — benutzerdefinierte Header werden nicht automatisch gesendet | Keine Aktion erforderlich |

Der einfachste Weg: NENE2s eingebaute Bearer-JWT-Authentifizierung verwenden und Cookie-basierte Sessions für API-Endpunkte vermeiden.
