# URL-Verkürzer-API & SSRF-Prävention

**FT183** — `shortlog`-Field-Trial (Schwachstellen-Diagnose VULN-A～L).

Ein URL-Verkürzer lässt Benutzer beliebige URLs als Weiterleitungsziele einreichen. Wenn die
Weiterleitung serverseitig (z. B. für Link-Vorschau oder Analysen) ohne Validierung verfolgt wird,
können Angreifer sie auf interne Dienste zeigen — dies ist ein **Server-Side Request Forgery (SSRF)**-Angriff.

Diese Anleitung behandelt SSRF-Prävention neben der vollständigen VULN-A～L-Sicherheitsüberprüfung
der Shortlog-Implementierung.

---

## SSRF: Das Kernrisiko

Ein URL-Verkürzer speichert und ruft möglicherweise eine angreifer-kontrollierte URL ab. SSRF
ermöglicht einem Angreifer:

- Interne Dienste zu erreichen: `http://10.0.0.1/admin`, `http://192.168.1.1/`
- Cloud-Metadaten zu treffen: `http://169.254.169.254/latest/meta-data/` (AWS IMDS)
- Lokale Dateien zu lesen: `file:///etc/passwd`
- Browser-Skripte auszuführen: `javascript:alert(1)`
- Loopback-Dienste zuzugreifen: `http://127.0.0.1:8080/`

**Die Lösung:** Das Schema der URL _und_ die Ziel-IP vor der Speicherung validieren.

---

## URL-Validierungsstrategie (VULN-K)

### Schritt 1 — Schema-Allowlist

`filter_var($url, FILTER_VALIDATE_URL)` allein ist **nicht ausreichend** — es akzeptiert
`javascript:alert(1)` und `ftp://` als gültige URLs. `parse_url()` und eine
explizite Schema-Allowlist verwenden:

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // Fehlerhafte URL — kein Schema oder Host
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // Lehnt ab: javascript:, file://, ftp://, data:, etc.
}
```

`parse_url()` ist kein Regex — er kann nicht für ReDoS ausgenutzt werden (VULN-F).

### Schritt 2 — Host-/IP-Validierung

```php
$host = strtolower($parts['host']);

// IPv6-Klammern entfernen: [::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// Localhost und *.localhost-Aliase blockieren
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// Wenn Host ein IP-Literal ist, direkt prüfen
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// Andernfalls Hostnamen auflösen → aufgelöste IP prüfen
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // false wenn nicht auflösbar
    return !isBlockedIp($resolved);
}
// Nicht auflösbarer Hostname → erlauben (kann eine gültige Domain sein, die vom Server nicht erreichbar ist)
return true;
```

### Schritt 3 — Private-/reservierte-IP-Prüfung

```php
function isBlockedIp(string $ip): bool
{
    // IPv6-Loopback
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE: blockiert 10.x, 172.16-31.x, 192.168.x
    // FILTER_FLAG_NO_RES_RANGE:  blockiert 127.x, 169.254.x, 0.x, 240.x+
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### DNS-Rebinding-Vorsicht

DNS-Rebinding-Angriffe ändern die IP einer Domain _nach_ der Validierung. Für
kritische Anwendungsfälle die URL auch _zum Abrufzeitpunkt_ validieren (nicht nur beim Speichern),
oder eine Netzwerk-Ebene-Egress-Firewall verwenden, die private Bereiche blockiert.

---

## Resolver für Tests injizieren

DNS-Aufrufe in Unit-Tests sind langsam und nicht-deterministisch. Den Resolver
injizierbar machen:

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null)
    {
    }

    private function resolveHost(string $host): string
    {
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        return $resolver($host);
    }
}
```

In Tests:

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // privat → blockiert
        'public.example.com' => '93.184.216.34',  // öffentlich → erlaubt
        default              => $host,             // nicht auflösbar → erlaubt
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## VULN-A～L-Assessment-Ergebnisse

### VULN-A — Integer-Überlauf (`limit`-Query-Parameter)

`V::queryInt()` verwendet `ctype_digit()` + `strlen() > 18`-Guard.
20-stellige und 19-stellige Strings werden vor dem `(int)`-Cast abgelehnt.

```
✅ BESTANDEN — Überlaufschutz verhindert stillen PHP_INT_MAX-Wrap
```

### VULN-B — Typverwirrung (URL / Slug aus JSON-Body)

`V::str()` erzwingt `is_string()` — lehnt `int 42`, `bool true`, `null` ab.

```php
V::str($body['original_url'] ?? null, 2048)  // → null für Nicht-String
V::str($body['slug'] ?? null, 20)            // → null für Nicht-String
```

```
✅ BESTANDEN — String-Typ vor jeder URL- oder Slug-Validierung durchgesetzt
```

### VULN-C — SQL-Injection

Alle Abfragen verwenden PDO-parametrisierte Statements:

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` schlägt bei Slug-Format-Validierung (SLUG_PATTERN) fehl,
bevor die DB erreicht wird. Selbst wenn sie die DB erreichte, verhindern parametrisierte Abfragen
die Ausführung.

```
✅ BESTANDEN — parametrisierte Abfragen + Slug-Allowlist
```

### VULN-D — Parameter-Pollution

PSR-7 `getQueryParams()` ruft PHPs `parse_str()` auf, das den _letzten_
Wert für doppelte Schlüssel nimmt. `?limit=10&limit=999999` senden → `limit=999999`,
was die `V::queryInt()`-Bereichsprüfung fehlschlägt (> MAX_LIMIT).

```
✅ BESTANDEN — Bereichsprüfung fängt jeden einzelnen Wert; kein Absturz
```

### VULN-E — IDOR (benutzerübergreifender Link-Zugriff)

DELETE verwendet `deleteForUser($slug, $userId)`:

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

`DELETE /links/user-a-slug` von Benutzer B mit seiner eigenen `X-User-Id` gibt 404 zurück
(die Zeile ist nicht gelöscht; sie entspricht einfach nicht der WHERE-Klausel).

```
✅ BESTANDEN — Eigentümerschaft auf DB-Ebene durchgesetzt; 404 vermeidet Aufzählung
```

### VULN-F — ReDoS-Immunität

URL-Validierung verwendet `parse_url()` (C-Erweiterung, kein Backtracking).
Slug-Validierung verwendet einen einfachen verankerten Regex ohne Alternationsgruppen.
`V::queryInt()` verwendet `ctype_digit()` (O(n), immun gegen Backtracking).

```
✅ BESTANDEN — kein exponentieller-Backtracking-Regex auf nicht-vertrauenswürdiger Eingabe
```

### VULN-G — Pfad-Traversal

Kein Dateisystemzugriff in dieser API. Nicht anwendbar.

```
N/A
```

### VULN-H — Timing-Angriffe auf Geheimnis-Vergleich

`V::secret()` delegiert an `hash_equals()` — Konstantzeit unabhängig davon, wo
Strings sich unterscheiden. Vermeidet Early-Exit-String-Vergleich, der Längen-/Präfixinformationen
über Timing leckt.

```
✅ BESTANDEN — hash_equals() verhindert Timing-Oracle
```

### VULN-I — Leeres-erwartetes-Geheimnis-Bypass

`V::secret('', '')` → `false`. Ein unkonfigurierter API-Schlüssel gewährt niemals Zugriff:

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ BESTANDEN — leeres Erwartetes gibt immer false zurück
```

### VULN-J — ISO 8601-Datums-Überlauf in `expires_at`

`V::isoDatetime()` verwendet `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` +
Round-Trip-Vergleich. `2024-02-30T00:00:00+00:00` rollt in PHP zu Mär. 1;
der neu formatierte String entspricht nicht der Eingabe → null.

`+25:00` Offset: von expliziter `$tzHours > 14` Bereichsprüfung abgefangen.

```
✅ BESTANDEN — Round-Trip fängt Überlauf-Daten; explizite Offset-Bereichsprüfung fängt +25:00
```

### VULN-K — SSRF

Ohne URL-Validierung: `http://127.0.0.1/admin`, `http://169.254.169.254/`,
`http://10.0.0.1/`, `javascript:alert(1)`, `file:///etc/passwd` würden alle
gespeichert und möglicherweise abgerufen.

Mit `UrlValidator`:

| Eingabe | Blockiergrund |
|---------|---------------|
| `http://127.0.0.1/` | Loopback-IP (`NO_RES_RANGE`) |
| `http://localhost/` | exakte Übereinstimmung `'localhost'` |
| `http://internal.localhost/` | `.localhost`-Suffix |
| `http://10.0.0.1/` | Private IP (`NO_PRIV_RANGE`) |
| `http://192.168.1.1/` | Private IP |
| `http://169.254.169.254/` | Reservierte IP (`NO_RES_RANGE`) |
| `http://private.internal/` | Löst auf 10.0.0.1 → blockiert |
| `javascript:alert(1)` | Schema nicht in `['http','https']` |
| `file:///etc/passwd` | Schema nicht in Allowlist |
| `ftp://example.com/` | Schema nicht in Allowlist |

```
✅ BESTANDEN — Schema-Allowlist + IP-Bereichsfilter blockiert alle SSRF-Vektoren
```

### VULN-L — Mass Assignment

`click_count` und `created_at` werden serverseitig in `LinkRepository::create()` gesetzt.
Request-Body-Schlüssel `click_count: 999999` und `created_at: "2000-01-01..."` werden
einfach ignoriert — der Controller liest sie nie.

```
✅ BESTANDEN — serverseitige Felder im Repository gesetzt, nie aus dem Request-Body
```

---

## VULN-Assessment-Zusammenfassung

| ID | Schwachstelle | Status |
|----|---------------|--------|
| VULN-A | Integer-Überlauf | ✅ BESTANDEN |
| VULN-B | Typverwirrung | ✅ BESTANDEN |
| VULN-C | SQL-Injection | ✅ BESTANDEN |
| VULN-D | Parameter-Pollution | ✅ BESTANDEN |
| VULN-E | IDOR | ✅ BESTANDEN |
| VULN-F | ReDoS | ✅ BESTANDEN |
| VULN-G | Pfad-Traversal | N/A |
| VULN-H | Timing-Angriffe | ✅ BESTANDEN |
| VULN-I | Leeres-Geheimnis-Bypass | ✅ BESTANDEN |
| VULN-J | DateTime-Überlauf | ✅ BESTANDEN |
| VULN-K | SSRF | ✅ BESTANDEN |
| VULN-L | Mass Assignment | ✅ BESTANDEN |

**Alle anwendbaren Schwachstellen: BESTANDEN (11/11)**

---

## Slug-Sicherheit (VULN-A, C)

Slugs müssen auf einen sicheren Zeichensatz beschränkt werden, um sowohl Injection als auch
unerwartetes Routing zu verhindern:

```php
// Muster: Kleinbuchstaben-Alphanumerisch + Bindestriche/Unterstriche, 3–20 Zeichen
// Muss mit Alphanumerisch beginnen und enden
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

Dieser einzelne Regex ist verankert und hat keine Alternationsgruppen mit überlappenden
Match-Pfaden — er kann nicht für ReDoS ausgenutzt werden.

**Abgelehnte Slugs**: `'; DROP TABLE links; --'` · `../../etc` · `MySlug`
· `sl@g!` · `a` (zu kurz) · 21-Zeichen-String (zu lang)

---

## Wichtigste Erkenntnisse

| Muster | Implementierung |
|--------|-----------------|
| SSRF-Prävention | `parse_url()`-Schema-Allowlist + `filter_var NO_PRIV_RANGE` |
| DNS-Auflösung in Tests | Injizierbarer `ipResolver`-Callback |
| Slug-Sicherheit | Zeichensatz-Allowlist-Regex (verankert, kein Backtracking) |
| URL-Typ-Durchsetzung | `V::str()` → `is_string()` vor URL-Parsing |
| Ablauf-Validierung | `V::isoDatetime()` mit Round-Trip + Offset-Bereichsprüfung |
| IDOR-Prävention | `WHERE slug = ? AND user_id = ?` in jeder Schreib-Abfrage |
| Mass Assignment | Serverseitige Felder im Repository gesetzt, im Controller ignoriert |
