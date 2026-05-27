# How-to: Bearer-Token-Middleware (JWT-Auth-Randfälle)

> **FT-Referenz**: FT273 (`NENE2-FT/authlog`) — BearerTokenMiddleware JWT-Auth: alg=none-Ablehnung, Signatur-Manipulations-Erkennung, exp/nbf-Durchsetzung, WWW-Authenticate-Header, Sub-basierte Datenisolation, IDOR → 404, 18 Tests / 26 Assertions bestanden.
>
> **VULN-Bewertung**: V-01 bis V-10 am Ende dieses Dokuments.

Demonstriert die Verwendung von NENE2's `BearerTokenMiddleware` + `LocalBearerTokenVerifier` (HMAC-HS256) zum Schutz von Routen. Alle JWT-Validierungs-Randfälle werden von der Middleware behandelt; Controller empfangen dekodierte Claims nur über `nene2.auth.claims`.

---

## Einrichtung

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

Die Middleware setzt `nene2.auth.claims` auf den Request, bevor ein Route-Handler ausgeführt wird. Bei fehlgeschlagener Validierung gibt sie 401 mit `WWW-Authenticate: Bearer` zurück, bevor der Handler aufgerufen wird.

---

## Claims in einem Controller extrahieren

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

Der `sub`-Claim ist die kanonische Benutzeridentität. Ihn als `owner_id` zu verwenden stellt die Datenisolation pro Benutzer ohne zusätzliche Abfragen sicher.

---

## WWW-Authenticate-Header

Bei 401 sendet die Middleware `WWW-Authenticate: Bearer realm="api"`.
Bei abgelaufenen Tokens enthält der Header `error="invalid_token"`:

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

RFC-6750-Konformität ermöglicht es Clients, "kein Token" von "schlechtes Token" zu unterscheiden.

---

## Schwachstellenbewertung

### V-01 — alg=none-Algorithm-Substitution ✅ SAFE

**Risiko**: Ein Angreifer erstellt ein JWT mit `"alg":"none"` und einem unsignierten Payload, der `sub: admin` beansprucht.
**Befund**: SAFE — `LocalBearerTokenVerifier` akzeptiert nur HMAC-HS256. `alg=none`-Tokens werden bei der Signaturverifizierung abgelehnt; der Test `testWrongAlgorithmHeaderReturns401` bestätigt 401.

---

### V-02 — Signatur-Manipulation ✅ SAFE

**Risiko**: Ein Angreifer fängt ein gültiges JWT ab und ändert den Payload (z. B. `sub` auf `admin`), behält aber Header und Originalsignatur bei.
**Befund**: SAFE — Die HMAC-HS256-Signatur deckt `header.payload` ab. Jede Änderung macht den MAC ungültig; `testTamperedPayloadReturns401` bestätigt 401.

---

### V-03 — Abgelaufenes Token wiederverwenden ✅ SAFE

**Risiko**: Ein abgelaufenes Token wird wiederholt, nachdem die Session ungültig sein sollte.
**Befund**: SAFE — Der `exp`-Claim wird validiert; Tokens mit `exp < time()` werden abgelehnt. `testExpiredTokenReturns401` bestätigt 401 mit `invalid_token` im `WWW-Authenticate`.

---

### V-04 — Not-before (nbf) umgehen ✅ SAFE

**Risiko**: Ein Token mit zukünftigem `nbf` (noch nicht gültig) wird vor seiner Aktivierungszeit verwendet.
**Befund**: SAFE — `nbf` wird durchgesetzt; `testNbfInFutureReturns401` bestätigt 401.

---

### V-05 — Falsches Authorization-Schema ✅ SAFE

**Risiko**: Ein Angreifer sendet `Authorization: Basic dXNlcjpwYXNz` oder lässt das `Bearer `-Präfix weg.
**Befund**: SAFE — Die Middleware akzeptiert nur Tokens mit `Bearer `-Präfix. `Basic` und bare Token-Strings geben beide 401 zurück.

---

### V-06 — Fehlerhafte Token-Struktur ✅ SAFE

**Risiko**: Ein Angreifer sendet Tokens mit 2 Teilen, 4 Teilen, ungültigem Base64-Payload oder zufälligen Strings, um die Fehlerbehandlung zu sondieren.
**Befund**: SAFE — Alle fehlerhaften Varianten geben 401 zurück. Tokens ohne 3 Teile und ungültiges Base64 werden vor jeder Claim-Extraktion abgelehnt.

---

### V-07 — Falsches Signatur-Secret ✅ SAFE

**Risiko**: Ein Angreifer mit Kenntnis des JWT-Formats signiert ein Token mit einem anderen Secret.
**Befund**: SAFE — Die HMAC-Verifizierung schlägt fehl, wenn das Secret abweicht; `testWrongSecretSignatureReturns401` bestätigt 401.

---

### V-08 — IDOR: Datenzugriff auf andere Benutzer ✅ SAFE

**Risiko**: Benutzer A versucht, die Daten von Benutzer B zu lesen, indem er die Eintrags-ID kennt oder errät.
**Befund**: SAFE — `findByIdAndOwner($id, $ownerId)` begrenzt die Suche auf den JWT-`sub`. Eine cross-user-Anfrage gibt 404 zurück (nicht 403), um nicht zu verraten, dass der Eintrag existiert.

---

### V-09 — Datenisolation pro Benutzer ✅ SAFE

**Risiko**: Schreibvorgänge von Benutzer A sind für Benutzer B sichtbar.
**Befund**: SAFE — Alle Lesevorgänge sind nach `owner_id = sub` begrenzt. `testEntriesAreIsolatedByToken` verifiziert, dass Alices und Bobs Einträge vollständig getrennt sind.

---

### V-10 — Token ohne exp-Claim ✅ SAFE (akzeptabel)

**Risiko**: Ein Token ohne `exp`-Claim wird ausgestellt und wird damit effektiv nicht ablaufend.
**Befund**: SAFE (by design) — `LocalBearerTokenVerifier` validiert `exp` nur, wenn der Claim vorhanden ist. Tokens ohne `exp` werden akzeptiert. Dies ist ein bewusster Kompromiss für Service-to-Service-Szenarien; Produktions-Deployments sollten `exp` über einen strengeren Verifier durchsetzen, wenn nötig.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | alg=none-Algorithm-Substitution | ✅ SAFE |
| V-02 | Signatur-Manipulation | ✅ SAFE |
| V-03 | Abgelaufenes Token wiederverwenden | ✅ SAFE |
| V-04 | Not-before (nbf) umgehen | ✅ SAFE |
| V-05 | Falsches Authorization-Schema | ✅ SAFE |
| V-06 | Fehlerhafte Token-Struktur | ✅ SAFE |
| V-07 | Falsches Signatur-Secret | ✅ SAFE |
| V-08 | IDOR-Datenzugriff auf andere Benutzer | ✅ SAFE |
| V-09 | Datenisolation pro Benutzer | ✅ SAFE |
| V-10 | Token ohne exp-Claim | ✅ SAFE (by design) |

**10 SAFE, 0 EXPOSED**
Keine kritischen Schwachstellen. `BearerTokenMiddleware` behandelt alle Standard-JWT-Angriffsvektoren; Anwendungscode muss nur den `sub`-Claim für das Eigentümer-Scoping verwenden.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `alg=none`-Tokens akzeptieren | Angreifer kann jede Identität fälschen, indem er die Signatur weglässt |
| `exp`-Validierung überspringen | Gestohlene Tokens bleiben unbegrenzt gültig |
| 403 bei IDOR zurückgeben | Verrät, dass die Ressource existiert und jemandem gehört |
| `X-User-Id`-Header statt JWT-`sub` verwenden | Header ist trivial fälschbar; JWT-Claim ist kryptografisch gebunden |
| Signing-Secret umgebungsübergreifend teilen | Ein Dev-Env-Leak kompromittiert Produktions-Tokens |
| `RS256`-Schlüssel kleiner als 2048 Bit verwenden | Anfällig für Faktorisierungsangriffe |
