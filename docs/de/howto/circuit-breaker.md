# How-to: Circuit Breaker

> **FT-Referenz**: FT298 (`NENE2-FT/circuitlog`) — Circuit-Breaker-Muster: geschlossen/offen/halb_offen Drei-Zustands-Maschine, konfigurierbarer Fehlerschwellenwert, timeout-basierter automatischer Übergang zu half_open, 503 Service Unavailable bei offenem Circuit, schreibgeschützte `isCallAllowed()`-Prüfung, 15 Tests / 28 Assertions PASS.

Das Circuit-Breaker-Muster verhindert Kaskadenausfälle beim Aufruf externer Dienste. Statt langsame oder fehlgeschlagene Aufrufe zu stapeln, öffnet der Circuit und lehnt Aufrufe sofort ab, bis sich die Abhängigkeit erholt hat.

## Drei Zustände

```
Closed ──(N aufeinanderfolgende Fehler)──▶ Open ──(Timeout abgelaufen)──▶ Half-Open
  ▲                                                                             │
  └────────────────────────────(Erfolg)───────────────────────────────────────┘
  Half-Open ──(Fehler)──▶ Open
```

| Zustand | Verhalten |
|---------|-----------|
| **Closed** | Normal — Aufrufe werden durchgelassen. Fehleranzahl erhöht sich bei jedem Fehler. |
| **Open** | Aufrufe werden sofort mit 503 abgelehnt. Öffnet für `timeout_seconds` nach `failure_threshold` aufeinanderfolgenden Fehlern. |
| **Half-Open** | Ein einzelner Probeaufruf wird erlaubt. Erfolg → Closed (Reset). Fehler → erneut Open. |

## Schema

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

Der Circuit-Name ist in der Regel der Bezeichner des externen Dienstes (z.B. `payment-gateway`, `email-svc`). Mehrere unabhängige Circuits können koexistieren.

## Ergebnisse aufzeichnen

```php
// Nach einem erfolgreichen Aufruf des externen Dienstes:
$this->repo->recordSuccess($circuitName, $now);

// Nach einem fehlgeschlagenen Aufruf:
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` entscheidet über den Übergang:
- Wenn `failure_count + 1 >= failure_threshold` → Status auf `open` setzen, `open_until = now + timeout` berechnen.
- Wenn noch unter dem Schwellenwert → `failure_count` erhöhen, `closed` bleiben.
- Wenn im `half_open`-Status → jeder Fehler öffnet sofort wieder.

## Prüfen, ob ein Aufruf erlaubt ist

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // Sofort 503 zurückgeben — den externen Dienst nicht aufrufen
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// Aufruf versuchen...
```

`maybeTransitionToHalfOpen()` vor der `isCallAllowed()`-Prüfung bei jeder Anfrage aufrufen. Dies löst den `Open → Half-Open`-Übergang aus, sobald `open_until` abgelaufen ist, und lässt den Probeaufruf durch.

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## Half-Open-Timing

Der `Open → Half-Open`-Übergang erfolgt lazy: Er findet beim nächsten Aufruf von `maybeTransitionToHalfOpen()` nach Ablauf von `open_until` statt. Dies ist beabsichtigt — es vermeidet Hintergrund-Timer und bindet Zustandsänderungen an eingehende Anfragen.

## Fehlerschwellenwert und Timeout-Abstimmung

| Abhängigkeitstyp | Empfohlener Schwellenwert | Empfohlener Timeout |
|------------------|--------------------------|---------------------|
| Datenbank (kritisch) | 3–5 | 10–30s |
| Externe API | 5–10 | 30–60s |
| Nicht-kritischer Dienst | 10–20 | 60–120s |

Höhere Schwellenwerte reduzieren Fehlalarme (vorübergehende Störungen). Längere Timeouts geben Abhängigkeiten mehr Erholungszeit, verlängern aber die für den Kunden sichtbare Beeinträchtigung.

## Mehrere Circuits pro Dienst

Distinct Circuit-Namen für distinct Fehlerdomänen verwenden:

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

Dies verhindert, dass ein Fehler im Refund-Endpunkt Charge-Versuche blockiert.

## Antwort bei offenem Circuit

`503 Service Unavailable` mit einem `Retry-After`-Header zurückgeben, der auf `open_until` zeigt:

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

Clients und Load Balancer, die `503` respektieren, können das Routing zu dieser Instanz während des offenen Circuits einstellen.

## Design-Entscheidungen

**Warum DB-backed State statt In-Memory?** In-Memory-State geht beim Neustart verloren und wird nicht über PHP-FPM-Worker hinweg geteilt. DB-State ist über alle Worker konsistent und übersteht Neustarts, auf Kosten einer zusätzlichen DB-Abfrage pro geschütztem Aufruf. Für hochfrequente Pfade Redis mit atomaren Increment-Operationen in Betracht ziehen.

**Warum lazy Half-Open-Übergang?** Proaktive Hintergrundübergänge erfordern einen Scheduler oder Daemon. Lazy Übergänge sind einfacher, aus Scheduler-Sicht zustandslos und für die meisten Web-APIs ausreichend, bei denen das Request-Volumen sicherstellt, dass die Prüfung zeitnah ausgeführt wird.

**Warum setzt `failure_count` bei jedem Erfolg zurück?** Dies ist "aufeinanderfolgende Fehler"-Semantik. Eine Alternative ist "Fehlerrate über ein gleitendes Fenster" (z.B. >50% Fehler in den letzten 60 Sekunden). Das gleitende Fenster ist genauer für Dienste mit niedrigem, aber stetigem Traffic; aufeinanderfolgende Fehler sind einfacher und ausreichend für Dienste, die entweder voll funktionsfähig oder ausgefallen sind.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Kein `UNIQUE(name)`-Constraint | Gleichzeitige Erstellungen produzieren mehrere Zeilen für denselben Circuit |
| Kein Timeout bei offenem Circuit | Circuit bleibt nach Schwellenwertüberschreitung für immer offen |
| Kein half_open-Status | Circuit wechselt direkt offen → geschlossen; kein Probe-dann-Verify |
| 200 zurückgeben, wenn Circuit offen ist | Aufrufer glauben, der Aufruf war erfolgreich; nachgelagerte Fehler versteckt |
| Kein `open_until` in 503-Antwort | Aufrufer wiederholen sofort (Thundering Herd); Retry-Timing einbeziehen |
| String `"true"` als Erfolg akzeptieren | JSON-Typverwirrung; strikt `is_bool()` verwenden |
| `isCallAllowed()` ohne vorheriges `maybeTransitionToHalfOpen()` prüfen | Offener Circuit wird nie half_open; dauerhaft blockiert |
| Nur In-Memory-State | State geht beim Worker-Neustart verloren; kein Sharing über PHP-FPM-Worker |
