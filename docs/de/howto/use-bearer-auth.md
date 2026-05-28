# Bearer-Token-Authentifizierung verwenden

NENE2 enthält `BearerTokenMiddleware` und `LocalBearerTokenVerifier` für JWT-basierte Authentifizierung. Diese Anleitung behandelt Einrichtung, Konfiguration, Token-Ausstellung und häufige Fallstricke.

## Einrichtung

Das Middleware in `RuntimeApplicationFactory` mit dem benannten Parameter `authMiddleware` einbinden:

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: 'change-me';
$verifier = new LocalBearerTokenVerifier($secret);
$bearer   = new BearerTokenMiddleware($problemDetails, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearer, // ← benannter Parameter ist authMiddleware, nicht middlewares
))->create();
```

> **Hinweis:** Der Parametername lautet `authMiddleware`, nicht `middlewares`. Die Verwendung von `middlewares:` verursacht einen Laufzeit-`Error: Unknown named parameter`.

## Alle Routen vs. selektiver Schutz

`BearerTokenMiddleware` unterstützt vier Pfad-Matching-Modi (erste Übereinstimmung gewinnt):

```php
// 1. Nur bestimmte Pfade schützen (Allowlist)
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. Pfade mit einem Präfix schützen (Präfix-Allowlist)
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. Alles außer aufgeführten Pfaden schützen (Blocklist — gängiges Muster)
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. Alle Pfade schützen (Standard — keine Arrays angegeben)
new BearerTokenMiddleware($problems, $verifier);
```

## Claims in einem Handler lesen

Nach erfolgreicher Verifikation werden Claims als Request-Attribut gespeichert:

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

Der Credential-Typ wird separat gespeichert:

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## Tokens ausstellen (lokal / Test)

`LocalBearerTokenVerifier` implementiert auch `TokenIssuerInterface`:

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← exp immer einschließen
]);
```

> **`exp` immer einschließen.** Tokens ohne `exp` werden als nicht ablaufend behandelt. Das ist für Tests sicher, aber gefährlich, wenn solche Tokens die Produktion erreichen. Fehlt `exp`, überspringt der Verifier die Ablaufprüfung.

## Fehlerantworten

Bei einem Fehler gibt `BearerTokenMiddleware` eine `401 Unauthorized` Problem Details-Antwort mit einem `WWW-Authenticate`-Header zurück:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

Fehlercodes in `WWW-Authenticate`:
- `missing_token` — kein `Authorization`-Header
- `invalid_token` — falsches Schema, abgelaufen, ungültige Signatur, fehlerhaft, falscher Algorithmus, `nbf` in der Zukunft

## Sicherheitseigenschaften von `LocalBearerTokenVerifier`

| Bedrohung | Schutz |
|-----------|--------|
| Signaturfälschung | HMAC-HS256, konstantzeit `hash_equals` |
| Algorithmus-Substitution (`alg:none`) | Nur `HS256` akzeptiert |
| Abgelaufenes Token | `exp`-Claim wird geprüft |
| Noch-nicht-gültiges Token | `nbf`-Claim wird geprüft |
| Manipuliertes Payload | Signatur deckt Header + Payload ab; Manipulation bricht Signatur |

> `LocalBearerTokenVerifier` ist für lokale Entwicklung und Tests gedacht. Für die Produktion eine bibliotheksgestützte Implementierung von `TokenVerifierInterface` (z. B. firebase/php-jwt) injizieren, die Key-Rotation und asymmetrische Algorithmen unterstützt.

## Testmuster

```php
// In setUp(): Verifier mit Test-Secret erstellen
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// Gültiges Token für einen Benutzer ausstellen
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// Abgelaufenes Token ausstellen (für Negativtest)
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// Noch-nicht-gültiges Token ausstellen
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
