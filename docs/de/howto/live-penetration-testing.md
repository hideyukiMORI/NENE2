# How-to: Live-Container-Penetrationstests

Diese Anleitung dokumentiert, wie ein adversariärer Live-Container-Penetrationstest gegen eine NENE2-Anwendung durchgeführt wird — vom Setup bis durch alle 30 Angriffsphasen — und hält die kanonischen Ergebnisse der Testsitzung v1.5.329 fest (2026-05-31, 150+ Fälle).

Der Test legt einen **Cracker-Mindset** zugrunde: Es wird angenommen, dass der Angreifer vollen Zugriff auf den Quellcode hat (White-Box), die gesamte öffentliche Dokumentation gelesen hat und jede bekannte Angriffsklasse ausprobieren wird, bevor er aufgibt.

---

## Voraussetzungen

- Docker Compose verfügbar (`docker compose version`)
- `curl`, `nc` (netcat), `openssl`, `python3` auf dem Host installiert
- Ein laufender NENE2-Container mit Test-Anmeldedaten

---

## 1. Container-Setup

Starten Sie ein isoliertes Testziel. Verwenden Sie einen dedizierten Port (niemals den Produktionsport) und injizieren Sie Test-Anmeldedaten:

```bash
# PHP built-in server target — fastest to spin up, tests raw NENE2 behaviour
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache target — tests full stack including Apache config hardening
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# Available on :8200 (see port registry in CLAUDE.md §8)
```

Baseline-Smoke-Check:

```bash
curl -si http://localhost:8299/
# Expected: 200 OK, security headers present, no Server/X-Powered-By
```

Angriffsfläche aus OpenAPI aufzählen:

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

Test-Anmeldedaten im Container generieren:

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. Angriffsphasen

### Phase 1 — JWT-Algorithmus-Verwechslung

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none` (leere Signatur) | 401 | ✅ BLOCKIERT |
| J-02 | `alg:NONE` (Großschreibung) | 401 | ✅ BLOCKIERT |
| J-03 | `alg:None` (gemischte Schreibung) | 401 | ✅ BLOCKIERT |
| J-04 | `alg:hs256` (Kleinschreibung) | 401 | ✅ BLOCKIERT |
| J-05 | `alg:RS256` (Schlüsselverwechslung) | 401 | ✅ BLOCKIERT |
| J-06 | Kein `alg`-Feld | 401 | ✅ BLOCKIERT |
| J-07 | `kid: ../../etc/passwd` | 200 (gültige Sig) | ✅ SICHER — zusätzliche Header-Felder ignoriert |
| J-08 | `jku: http://evil.com` | 200 (gültige Sig) | ✅ SICHER — kein JWK-Abruf |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### Phase 1b — JWT-Payload-Manipulation

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0` (Epoche 1970) | 401 abgelaufen | ✅ BLOCKIERT |
| J-10 | `exp: null` | 401 muss numerisch sein | ✅ BLOCKIERT |
| J-11 | `exp: "never"` | 401 muss numerisch sein | ✅ BLOCKIERT |
| J-12 | `exp: 9999999999.9` (Float) | 401 muss numerisch sein | ✅ BLOCKIERT |
| J-13 | Payload ist JSON-Array | 401 muss numerisch sein | ✅ BLOCKIERT |
| J-14 | Doppeltes Leerzeichen im Bearer-Wert | 401 | ✅ BLOCKIERT |
| J-15 | Kein Bearer-Schema | 401 | ✅ BLOCKIERT |
| J-16 | 4-Segment-Token (zusätzlicher Punkt) | 401 ungültiges Format | ✅ BLOCKIERT |
| J-17 | Nur Header + Payload (keine Sig) | 401 | ✅ BLOCKIERT |

> **Schlüssel-Invariante**: `exp` muss eine vorhandene Ganzzahl sein — Fehlen oder falscher Typ wird abgelehnt (behoben in v1.5.329).

### Phase 2 — SQL-Injection

Alle Repositories verwenden parametrisierte Abfragen mit `?`-Platzhaltern. Keine rohe Zeichenketteninterpolation.

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| S-01 | Klassisches `' OR 1=1--` im Titel | 201 (als Literal gespeichert) | ✅ SICHER |
| S-02 | `UNION SELECT 1,2,3--` | 201 (als Literal gespeichert) | ✅ SICHER |
| S-03 | Boolean-blind `AND 1=1--` | 201 (als Literal gespeichert) | ✅ SICHER |
| S-04 | Zeitbasiert `AND SLEEP(2)--` | 201 in <50ms | ✅ SICHER — SLEEP nicht ausgeführt |
| S-05 | Pfadparam-SQLi `/notes/1' OR '1'='1` | 200 (int-Cast → 1) | ✅ SICHER |
| S-06 | Null-Byte `\0' OR '1'='1` | 201 (Literal) | ✅ SICHER |
| S-07 | Second-Order: Payload speichern, dann lesen | 200 (Literal-Wiederlesen) | ✅ SICHER |
| S-08 | SLEEP(5) in Body-Feld | 201 in <50ms | ✅ SICHER |
| S-10 | `limit=UNION SELECT...` im Query-String | 422 (Validierung) | ✅ SICHER |

```bash
# Verify parameterized queries: SLEEP is not executed
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → 201 in < 100ms  (SLEEP never ran)
```

### Phase 3 — Path-Traversal / LFI / PHP-Wrapper

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKIERT |
| P-02 | URL-kodierte `%2e%2e%2f`-Varianten (5 Formen) | 404 | ✅ BLOCKIERT |
| P-03 | Doppelt kodiert `%252e%252e` | 404 | ✅ BLOCKIERT |
| P-04 | UTF-8-Overlong `%c0%ae` | 404 | ✅ BLOCKIERT |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKIERT |
| P-06 | LFI via `{id}`-Parameter | 404 | ✅ BLOCKIERT |
| P-07 | Null-Byte `1%00.html` | 200 (int-Cast → 1) | ✅ SICHER — DB-Eintrag für id=1 zurückgegeben |
| P-08 | `.htaccess` auf Apache | 403 | ✅ BLOCKIERT |
| P-08b | `.htaccess` auf PHP-built-in-Server | **200** | ⚠️ EXPONIERT (siehe VULN-01) |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKIERT |
| P-10 | Backup-Dateien (`.bak`, `.swp`, `~`, etc.) | 404 | ✅ BLOCKIERT |

### Phase 4 — HTTP-Protokoll-Angriffe

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| H-01 | CL.TE-Request-Smuggling | keine Antwort (PHP-built-in blockiert) | ✅ |
| H-02 | TE.CL-Smuggling | 405 (Root-Methoden-Mismatch) | ✅ |
| H-03 | TE.TE-verschleiertes Transfer-Encoding | keine Antwort | ✅ |
| H-04 | HTTP/1.0-Downgrade | 200 (korrekter Body) | ✅ |
| H-05 | Absolute-URI-Proxy-Missbrauch | 404 | ✅ |
| H-06 | HTTP-Header-Folding | 500 (PHP-built-in-Bug) | ⚠️ VULN-02 |
| H-07 | HTTP-Pipelining | Antworten verschachtelt | ✅ SICHER |
| H-08 | 100 gleichzeitige benutzerdefinierte Header | 200 | ✅ SICHER |
| H-10 | WebSocket-Upgrade | 200 (Upgrade ignoriert) | ✅ SICHER |
| H-12 | Ungültige HTTP-Version (`HTTP/9.9`) | 200 (PHP-built-in akzeptiert) | ✅ SICHER |

### Phase 5 — Mass-Assignment / IDOR / Geschäftslogik

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| B-01 | Mass-Assignment (`id`, `__proto__` im Body) | 201 (zusätzliche Felder ignoriert) | ✅ SICHER |
| B-02 | IDOR: DELETE der Notiz eines anderen Benutzers | 204 | ℹ️ Erwartet (Examples haben kein Eigentum) |
| B-04 | Negative / null ID | 404 | ✅ SICHER |
| B-05 | Integer-Überlauf-ID | 404 | ✅ SICHER |
| B-06 | DELETE, dann erneuter Zugriff auf dieselbe ID | 404 | ✅ SICHER |
| B-07 | Race Condition bei gleichzeitigem DELETE | alle 404 (idempotent) | ✅ SICHER |
| B-08 | Body am 1-MB-Limit | 413 | ✅ BLOCKIERT |

### Phase 6 — API-Key-Umgehung

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| A-01 | Kein Schlüssel | 401 | ✅ BLOCKIERT |
| A-02 | Schlüssel im Query-String (`?key=`, `?api_key=`) | 401 | ✅ BLOCKIERT |
| A-03 | Schlüssel im Request-Body | 401 | ✅ BLOCKIERT |
| A-04 | Variationen der Header-Namen-Schreibung | 200 (PSR-7 normalisiert) | ✅ SICHER |
| A-05 | Führende/nachgestellte Leerzeichen im Wert | 200 (PSR-7 trimmt) | ✅ SICHER |
| A-06 | `//machine/health` doppelter Schrägstrich | 401 ohne Schlüssel, 200 mit | ✅ SICHER |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200 (Header ignoriert) | ✅ SICHER |
| A-08 | OPTIONS-Preflight-Umgehung | 405 | ✅ BLOCKIERT |
| A-09 | HEAD-Methode | 401 | ✅ BLOCKIERT |
| A-10 | Brute-Force gängiger Passwörter | 401 alle | ✅ BLOCKIERT |
| A-11 | URL-kodierter Pfad (`%6Dachine`) | 404 | ✅ BLOCKIERT |

```bash
# Timing attack: hash_equals used → constant-time comparison
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → timing difference < 5ms over 10 requests: SAFE
```

### Phase 7 — Injection / XSS / SSTI / Codeausführung

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| I-01 | XSS `<script>alert(1)</script>` gespeichert | 201, als JSON-Zeichenkette zurückgegeben | ✅ SICHER — JSON-Kodierung neutralisiert |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201, wörtlich gespeichert | ✅ SICHER — keine Template-Engine |
| I-03 | PHP `<?php system("id"); ?>` | 201, als Literal gespeichert | ✅ SICHER |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200 (Header ignoriert) | ✅ SICHER — PHP, nicht Java |
| I-05 | 1000-stufig verschachteltes JSON | 400 (PHP-Parse-Limit) | ✅ BLOCKIERT |
| I-06 | Unicode-BiDi-Steuerzeichen | 201 (gespeichert) | ✅ SICHER — Nur-Anzeige-Risiko |
| I-07 | Doppelte JSON-Schlüssel | letzter Wert gewinnt (PHP-Verhalten) | ℹ️ INFO-01 |

> **Stored-XSS-Hinweis**: XSS-Payloads werden gespeichert und wörtlich in JSON-Antworten zurückgegeben. Da die API rein JSON ist (`Content-Type: application/json` + `X-Content-Type-Options: nosniff`), führen Browser das Skript nicht aus. Das Risiko materialisiert sich nur, wenn eine andere Anwendung diese Daten in einem HTML-Kontext ohne Escaping rendert.

### Phase 8 — Deserialisierung / PHP-Objekt-Injection

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| D-01 | `phar://`-Wrapper im Pfadparam | 404 | ✅ BLOCKIERT |
| D-02 | PHP `O:8:"stdClass":...`-Serialize-Payload | 400 (ungültiger Body) | ✅ BLOCKIERT |
| D-03 | URL-kodiertes Formular mit Serialize-Payload | 400 (falscher Content-Type) | ✅ BLOCKIERT |

### Phase 9 — Header-Injection / Response-Splitting

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| R-01 | Location-Header-Injection via erstellter Notiz-ID | `/examples/notes/<int>` | ✅ SICHER — nur int |
| R-02 | CRLF in WWW-Authenticate via JWT-Fehler | sanitisierte feste Nachricht | ✅ SICHER |
| R-03 | Content-Type-Sniffing | `X-Content-Type-Options: nosniff` | ✅ SICHER |
| R-04 | Clickjacking | `X-Frame-Options: SAMEORIGIN` | ✅ SICHER |

### Phase 10 — CORS / SOP-Umgehung

| ID | Angriff | Erwartet | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null` (sandboxed iframe) | Vary: Origin, kein ACAO-Header | ✅ SICHER |
| C-02 | CRLF im Origin-Header | von curl/http-Schicht sanitisiert | ✅ SICHER |
| C-03 | Vary-Header-Cache-Poisoning | `Vary: Origin` vorhanden | ✅ SICHER |
| C-04 | Preflight mit injizierter Methode | Methode von PHP ignoriert | ✅ SICHER |
| C-05 | `Access-Control-Allow-Origin: *` | Header fehlt (Allowlist leer) | ✅ SICHER |

### Phase 11-20 — Kodierung / Protokoll / Timing

| ID | Angriff | Ergebnis |
|----|--------|--------|
| E-01 | Emoji / hohes Unicode in JSON | ✅ 201 (korrekt gespeichert) |
| E-02 | BiDi-RTL-Override (Spoofing-Risiko) | ✅ 201 (nur Anzeige) |
| E-05 | Paginierungs-SQLi via Query-Parameter | ✅ 422 (als Ganzzahl validiert) |
| H-06b | Gefalteter Authorization-Header | ⚠️ 500 (PHP-built-in-Bug) |
| 20 | X-Request-Id mit 129 Zeichen abgelehnt | ✅ Server generiert neue zufällige ID |
| 21 | Log-Injection via X-Request-Id `%0a` | ✅ Abgelehnt (ungültige Zeichen) |
| 22 | Apache ServerTokens/ServerSignature | ✅ Nur `Server: Apache` |
| 23 | JWT sub=admin Privilegienerweiterung | ✅ Claims nicht für Autorisierung verwendet |
| 26 | JWT-Replay (vor 2s abgelaufen) | ✅ 401 `Token has expired.` |
| 27 | 500-Stacktrace-Offenlegung | ✅ Nur generische Nachricht |
| 28 | XSS in Problem-Details `instance` | ✅ URL-kodiert (sicher) |
| 29 | SSRF via Health-Check-Endpunkt | ✅ Keine URL akzeptiert |
| 15 | API-Key-Timing-Orakel | ✅ `hash_equals` — < 5ms Diff |

---

## 3. Befunde

### VULN-01 — `.htaccess` lesbar vom PHP-built-in-Server ⚠️ MITTEL

**Auslöser**: `curl http://localhost:8299/.htaccess`  
**Antwort**: 200 + voller Dateiinhalt (Apache-Rewrite-Regeln)  
**Ursache**: PHPs built-in-Server (`php -S`) erzwingt keine `.htaccess`-Zugriffsbeschränkungen — er behandelt `.htaccess` als statische Datei.  
**Auswirkung**: Gibt URL-Rewrite-Regeln preis. Der Inhalt ist nicht geheim (keine Passwörter/Tokens), bestätigt aber das Rewrite-to-index-php-Muster.  
**Gegenmaßnahme**: Den Apache-Container (`docker compose up -d app`) statt `php -S` für sicherheitssensible Tests verwenden. Apache gibt korrekt 403 zurück.

```bash
# Apache (correct): 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP built-in server (exposed): 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — HTTP-Header-Folding bringt PHP-built-in-Server zum Absturz ⚠️ NIEDRIG

**Auslöser**:
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**Antwort**: `HTTP/1.0 500 Internal Server Error` (leerer Body)  
**Ursache**: PHPs built-in-HTTP-Server unterstützt kein RFC-7230-Header-Folding (veraltet, aber in HTTP/1.1 noch gültig). NENE2-Framework-Code ist nicht beteiligt.  
**Auswirkung**: Nur Entwicklung (PHP-built-in-Server). Apache verarbeitet gefaltete Header korrekt.

### INFO-01 — Doppelte JSON-Schlüssel: letzter Wert gewinnt

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
Standard-PHP-`json_decode`-Verhalten. Die Validierung wird auf den finalen (letzten) Wert angewendet, sodass es keinen Validierungs-Umgehungspfad gibt. Zur Kenntnisnahme vermerkt.

---

## 4. Verifizierte Sicherheits-Invarianten

Diese Garantien galten über alle 150+ Testfälle hinweg:

| Invariante | Verifizierung |
|-----------|-------------|
| Alle SQL-Abfragen parametrisiert | SLEEP nicht ausgeführt; Injection-Payloads als Literale gespeichert |
| JWT muss HS256 + gültige Sig + Integer-exp sein | Alle 17 JWT-Angriffsvarianten blockiert |
| API-Key mit `hash_equals` geprüft | Timing-Differenz < 5ms über 10 Iterationen |
| `Content-Length`-Überlauf behandelt | 413 mit korrekten Headern, kein PHP-Warning-Leak |
| Security-Header bei jeder Antwort | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy bestätigt |
| `Server:` / `X-Powered-By:` entfernt | Keiner der Header in Apache-Antworten vorhanden |
| Stacktraces nie im 500-Body | Nur generisches `"The server encountered an unexpected condition."` |
| Path-Traversal blockiert | Alle 15 Kodierungsvarianten geben 404 zurück |
| `.env` / `.git` / Backup-Dateien | Alle 404 im Document-Root |
| CORS-Standard: keine erlaubten Origins | `Access-Control-Allow-Origin` fehlt bei beliebigen Origins |

---

## 5. Die Testsuite ausführen

Minimal lauffähige Wiederholung der Schlüsselprüfungen (< 5 Minuten):

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# expected: 401

# 2. SQL injection time-based
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# expected: < 500ms total

# 3. Path traversal
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# expected: 404

# 4. Content-Length overflow
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# expected: 413 Request Entity Too Large (not 200 + PHP warning)

# 5. API key timing
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# expected: similar timing to correct key (hash_equals)

# 6. .htaccess exposure (Apache only)
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# expected: 403

# 7. JWT exp required
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# expected: "Token must contain a numeric exp claim."
```

---

## Verwandte Anleitungen

- [Paginierungsgrenze & Limit-Injection](pagination-boundary-attack.md)
- [Webhook-Signatur-Verifizierung](webhook-signature-verification.md)
- [JWT-Authentifizierung hinzufügen](add-jwt-authentication.md)
- ADR 0011: Security-Review-Richtlinie
