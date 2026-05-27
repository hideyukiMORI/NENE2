# Verteilte Sperrung

Eine verteilte Sperre verhindert, dass gleichzeitige Prozesse einen kritischen Abschnitt gleichzeitig ausführen. DB-gestützte Sperren tauschen Durchsatz gegen Einfachheit — kein Redis erforderlich, und dieselbe DB, die Ihre Daten enthält, enthält auch Ihre Sperren.

## Kernkonzepte

- **Ressource**: der Name der zu sperrenden Sache (z. B. `job:42`, `report:monthly-2026-05`)
- **Eigentümer**: ein Token, das den Sperr-Halter identifiziert — nur der Eigentümer kann freigeben oder erneuern
- **Ablauf (TTL)**: Sperren laufen automatisch ab, damit ein abgestürzter Eigentümer eine Sperre nicht für immer halten kann
- **Veraltete Sperr-Übernahme**: eine abgelaufene Sperre kann von einem neuen Eigentümer übernommen werden

## Schema

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

Die `UNIQUE`-Constraint auf `resource` stellt sicher, dass nur eine Zeile pro Ressource existiert. Gleichzeitige INSERTs werden auf DB-Ebene serialisiert.

## Aneignungslogik

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Keine Sperre — INSERT (kann bei Race scheitern; Aufrufer bekommt null und versucht erneut)
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Abgelaufen (veraltet) oder gleicher Eigentümer der wieder aneignet — UPDATE zum Übernehmen
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // Wird von einem anderen Eigentümer gehalten und noch gültig — kann nicht angeeignet werden
    return null;
}
```

Rückgabewert-Konventionen:
- Gibt einen `LockRecord` bei Erfolg zurück (`acquired: true` in der API-Antwort)
- Gibt `null` zurück, wenn die Sperre von einem anderen Eigentümer gehalten wird (`acquired: false`)

## Eigentümer-erzwungene Freigabe

Nur der Eigentümer darf freigeben. 403 zurückzugeben (nicht 404), wenn der Eigentümer nicht übereinstimmt, teilt dem Aufrufer mit, dass die Sperre existiert, er sie aber nicht hält:

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## TTL-Erneuerung

Lang laufende Tasks müssen ihre Sperre verlängern, bevor sie abläuft. Nur der aktuelle Eigentümer darf erneuern — eine Erneuerung durch einen falschen Eigentümer gibt 409 zurück (nicht 403), weil es einen Zustandskonflikt signalisiert, keine Berechtigungsverweigerung:

```php
if ($existing->isExpired($now)) {
    return null; // → 409: abgelaufene Sperre kann nicht erneuert werden (jemand anderes hält sie möglicherweise jetzt)
}
if ($existing->owner !== $owner) {
    return null; // → 409: falscher Eigentümer
}
// expires_at verlängern
```

## Erkennung veralteter Sperren

`LockRecord::isExpired()` vergleicht die aktuelle Zeit mit `expires_at`:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

Das bedeutet, `GET /locks/{resource}` gibt 404 für abgelaufene Sperren zurück (abgelaufene als nicht vorhanden behandelnd), und `POST /locks/{resource}` lässt einen neuen Eigentümer eine abgelaufene Sperre beanspruchen.

## Designentscheidungen

**Warum nicht Redis SETNX?**
Redis bietet atomares SETNX mit TTL in einem einzigen Befehl und ist der Produktionsstandard für Hochdurchsatz-Sperrung. DB-gestützte Sperrung ist einfacher zu deployen (kein zusätzlicher Dienst), konsistent mit Ihren übrigen Transaktionsdaten und ausreichend für geringe bis mittlere Konkurrenzsituationen (Hintergrundjobs, Berichtserstellung, Batch-Verarbeitung).

**Warum nicht DELETE+INSERT bei der Wiederaneignung?**
UPDATE bewahrt die Zeilen-ID und ist atomar. DELETE+INSERT würde ein kurzes Zeitfenster erzeugen, in dem keine Sperrzeile existiert, was einem gleichzeitigen Prozess ermöglicht, INSERT auszuführen und die Sperre zu stehlen.

**Warum `acquired_at` von `expires_at` trennen?**
`acquired_at` ist der Zeitstempel, wann die Eigentumsrechte zuletzt eingerichtet wurden (nützlich für Audit). `expires_at` ändert sich bei der Erneuerung. Sie getrennt zu halten vermeidet Mehrdeutigkeit.

**Nicht-blockierend per Design**
Der Sperr-Endpunkt gibt sofort mit `acquired: false` zurück, anstatt zu blockieren, bis die Sperre verfügbar ist. Aufrufer implementieren ihre eigene Wiederholungsstrategie (exponentielles Backoff, Dead-Letter-Queue usw.) basierend auf ihren Timeout-Anforderungen.
