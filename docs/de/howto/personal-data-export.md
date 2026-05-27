# Persönlicher Daten-Export

Ein DSGVO-ähnlicher Daten-Export ermöglicht Benutzern, alle ihre persönlichen Daten herunterzuladen. Die primären Anliegen sind: Ausschluss sensibler Felder aus der Export-Nutzlast, sichere Download-Token und Ablauf-Durchsetzung.

## Kernkomponenten

- **Export-Job**: ein Datensatz, der einen Benutzer mit einem opaken Download-Token verknüpft, mit Status (pending → ready) und einem Ablauf-Zeitstempel.
- **Verarbeitungsschritt**: eine Worker-seitige Operation, die die Nutzlast aufbaut und den Job als bereit markiert.
- **Download**: ruft die Nutzlast per Token ab und prüft den Ablauf vor der Ausgabe.

## Schema

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Token-Generierung

`bin2hex(random_bytes(32))` verwenden — 64 Hex-Zeichen, 256 Bit Entropie. Sequentielle IDs, Zeitstempel oder MD5-basierte Token sind erratbar und dürfen nicht für Download-Token verwendet werden.

```php
$token = bin2hex(random_bytes(32));
```

## Ausschluss sensibler Felder

Die Export-Nutzlast darf niemals Anmeldedaten oder Felder enthalten, in deren Export der Benutzer nicht explizit eingewilligt hat. Ausschluss auf Repository-Ebene, nicht auf HTTP-Schicht:

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash absichtlich ausgeschlossen
            // phone absichtlich ausgeschlossen (neue Einwilligung für PII erforderlich)
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

Denselben Ausschluss auf den öffentlichen Profilendpunkt anwenden — `phone`, `password_hash` und interne Felder sollten auch nicht in `GET /users/{id}`-Antworten erscheinen.

## Ablauf-Durchsetzung

Ablauf in **beiden** Endpunkten erzwingen — Download und Verarbeitung:

```php
// In downloadExport:
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// In processExport — KRITISCH: hier auch prüfen
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

Ohne die Prüfung in `processExport` würde ein Worker, der einen veralteten Job erhält, die Benutzerdaten in die DB schreiben, auch wenn das Download-Fenster bereits geschlossen ist, was verwaiste Datensätze mit sensibler Nutzlast erzeugt.

## Status-Ablauf

```
pending ──(process aufgerufen, nicht abgelaufen)──▶ ready ──(download aufgerufen)──▶ [Nutzlast serviert]
   │                                                       │
   └──(process aufgerufen, abgelaufen)──▶ 410             └──(abgelaufen)──▶ 410
```

## Download: 410 Gone vs. 404 Not Found

- **404**: Das Token existiert nicht in der Datenbank.
- **410 Gone**: Das Token existiert, ist aber abgelaufen. Das ist der korrekte Status — die Ressource existierte und wurde seitdem entfernt. Clients können dieses Signal nutzen, um den Benutzer aufzufordern, einen neuen Export anzufordern.

## Designentscheidungen

**Warum ein separater `process`-Schritt statt synchroner Generierung?**
Export-Nutzlasten können groß sein (jahrelange Aktivitätsdaten). Synchrone Generierung im HTTP-Handler riskiert Timeouts und bindet einen Worker. Das asynchrone Muster ermöglicht es dem Benutzer, anzufragen und später nachzuschauen. In diesem FT wird der Verarbeitungsschritt als API exponiert, um die Worker-Aufrufsimulation zu ermöglichen.

**Warum das Token als Download-URL statt die Export-ID verwenden?**
Eine sequentielle Integer-ID ist IDOR-anfällig — Benutzer 1 könnte den Export von Benutzer 2 herunterladen, indem er die ID inkrementiert. Ein opakes Zufalls-Token macht die Download-URL nicht erratbar.

**Soll `process` ein öffentlicher Endpunkt sein?**
In der Produktion nicht. Der Process-Endpunkt sollte nur von internen Workern aufgerufen werden (via API-Key, internes Netzwerk oder Queue). In diesem FT ist er für Testbarkeit exponiert. Die Token-Entropie bietet einigen Schutz, ersetzt aber keine ordnungsgemäße Worker-Authentifizierung.
