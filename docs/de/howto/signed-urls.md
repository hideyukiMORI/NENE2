# Signierte URLs

Signierte URLs bieten zeitlich begrenzten, ressourcengebundenen Zugriff auf geschützte Ressourcen,
ohne dass sich der Aufrufer mit einem Konto authentifizieren muss. Das Muster wird für Datei-Downloads,
vorsignierte Upload-Slots und jeden Fall verwendet, in dem temporärer Zugriff mit einem Dritten geteilt
werden soll.

## Grundkonzept

Eine signierte URL enthält alles, was zur Zugriffsautorisierung benötigt wird: die Ressourcen-ID,
die Ablaufzeit und eine HMAC-Signatur, die beweist, dass die URL von einem vertrauenswürdigen Server
generiert wurde. Der Server benötigt nur seinen geheimen Schlüssel zur Verifizierung — kein Datenbankzugriff
erforderlich.

## Token-Format

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

Der HMAC deckt `resource_id|expires_at` zusammen ab. Das Ändern eines der beiden Teile macht die
Signatur ungültig. Dies bindet das Token an genau eine Ressource und ein Ablaufzeitfenster.

## Signer-Implementierung

```php
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {}

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $expectedMac = hash_hmac(self::ALGO, $resourceId . '|' . $expiresAt, $this->secret);

        // hash_equals() ist zwingend erforderlich — === gibt Timing-Informationen preis
        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }
}
```

`hash_equals()` ist nicht verhandelbar. Ein String-Gleichheitsvergleich bricht beim ersten Mismatch ab
und gibt preis, wie viele Zeichen des HMAC übereinstimmen. Ein Angreifer kann dies ausnutzen, um
Signaturen Byte für Byte zu fälschen. `hash_equals()` vergleicht stets alle Zeichen.

## 410 Gone vs. 401 Unauthorized für abgelaufene Tokens

Benutzer profitieren davon zu wissen, ob ihr Link abgelaufen ist (und sie einen neuen anfordern sollen)
oder ob der Link nie gültig war. Der Signer verifiziert zunächst den HMAC, dann den Ablauf. Um sie
in der HTTP-Antwort zu unterscheiden:

```php
$resourceId = $this->signer->verify($token, $now);

if ($resourceId === null) {
    // Ablauf ohne HMAC-Verifizierung extrahieren
    $expiresAt = $this->signer->extractExpiresAt($token);
    if ($expiresAt !== null && $expiresAt < $now) {
        return $problems->create($request, 'gone', 'This link has expired.', 410, '');
    }
    return $problems->create($request, 'unauthorized', 'Invalid or expired token.', 401, '');
}
```

`extractExpiresAt()` dekodiert nur Base64 und teilt bei `|` — es verifiziert den HMAC NICHT. Dies
ist sicher, weil:
1. Der Ablauf kein Geheimnis ist (er ist sowieso in der signierten URL sichtbar).
2. Ein Angreifer kein gültiges Token mit einem manipulierten Ablauf fälschen kann, weil `verify()` es ablehnt.
3. Die 410-Antwort keine Informationen liefert, die beim Fälschen von Tokens helfen.

KEINE unterschiedlichen Fehlermeldungen für „HMAC-Mismatch" vs. „Ablauf überschritten" ausgeben — das
würde es einem Angreifer ermöglichen, zuerst gültige Signaturen für beliebige Ablaufwerte zu konstruieren
und sie dann zum Erkunden des Timings zu verwenden.

## Signierte URLs generieren

```php
// POST /files/{id}/sign
$expiresAt = (new \DateTimeImmutable())
    ->add(new \DateInterval("PT{$ttlSeconds}S"))
    ->format('Y-m-d H:i:s');

$token = $this->signer->sign($file->id, $expiresAt);

return $json->create([
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttlSeconds,
    'url'         => '/download?token=' . urlencode($token),
]);
```

Das Token immer mit `urlencode()` kodieren, bevor es in URLs eingebettet wird — Base64url-Zeichen sind
URL-sicher, aber die `=`-Auffüllung (falls vorhanden) ist es nicht, und der `|`-Separator im dekodierten
Payload darf nicht in der kodierten Form erscheinen.

## Secret-Key-Management

- Das Secret aus einer Umgebungsvariable einlesen — niemals hartcodieren.
- Mindestens 32 Bytes zufällige Daten verwenden (`random_bytes(32)` → hex oder Base64).
- Für die Secret-Rotation die Verifizierung gegen mehrere Secrets gleichzeitig unterstützen (jedes
  ausprobieren, bis eines erfolgreich ist), dann das alte Secret auslaufen lassen.

```php
// Multi-Secret-Unterstützung während der Rotation
public function verifyWithRotation(string $token, string $now, array $secrets): ?int
{
    foreach ($secrets as $secret) {
        $signer = new HmacSigner($secret);
        $id = $signer->verify($token, $now);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}
```

## Zustandslos vs. zustandsbehaftet signierte URLs

Dieses Muster ist **zustandslos** — der Server verfolgt keine ausgestellten Tokens. Dies ist der
Hauptvorteil (kein DB-Zugriff bei jedem Download), bedeutet jedoch:

- Signierte URLs können nicht vor ihrem Ablauf widerrufen werden.
- Bei Rotation des Secrets werden alle zuvor ausgestellten Tokens sofort ungültig.

Für widerrufbare Tokens eine Sperrlisten-Tabelle (`revoked_tokens`) anlegen und diese während der
Verifizierung prüfen. Dies tauscht den zustandslosen Vorteil gegen Widerrufbarkeit ein.

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `===` oder `strcmp()` für HMAC-Vergleich verwenden | Timing-Angriff — ermöglicht das Fälschen von Signaturen |
| Nur `resource_id` ohne Ablauf signieren | Tokens sind permanent — können nicht ablaufen |
| Nur `expires_at` ohne `resource_id` signieren | Ein Token gewährt Zugriff auf alle Ressourcen |
| Den Ablauf zur Unterscheidung von „manipuliert" vs. „abgelaufen" verwenden | Ermöglicht Oracle-Angriff auf HMAC |
| Rohen Schlüssel im Token einbetten | Macht den Zweck zunichte — Token muss opak sein |
| Lange TTLs (Tage/Wochen) | Vergrößert das Exponierungsfenster bei Token-Leckage |
