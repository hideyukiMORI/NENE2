# Signierte URLs

Signierte URLs bieten zeitlich begrenzten, ressourcengebundenen Zugriff auf geschützte Ressourcen, ohne dass der Aufrufer sich mit einem Konto authentifizieren muss. Das Muster wird für Datei-Downloads, vorsignierte Upload-Slots und alle Fälle verwendet, in denen temporärer Zugriff mit einem Dritten geteilt werden soll.

## Kernkonzept

Eine signierte URL enthält alles, was zur Autorisierung des Zugriffs benötigt wird: die Ressourcen-ID, die Ablaufzeit und eine HMAC-Signatur, die beweist, dass die URL von einem vertrauenswürdigen Server generiert wurde. Der Server benötigt nur seinen geheimen Schlüssel zur Verifizierung — kein Datenbankaufruf erforderlich.

## Token-Format

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

Das HMAC deckt `resource_id|expires_at` zusammen ab. Das Ändern eines der Teile macht die Signatur ungültig. Dies bindet den Token an genau eine Ressource und ein Ablaufzeitfenster.

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

        // hash_equals() ist obligatorisch — die Verwendung von === gibt Timing-Informationen preis
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

`hash_equals()` ist nicht verhandelbar. Ein String-Gleichheitsvergleich bricht beim ersten Unterschied ab und gibt preis, wie viele Zeichen des HMAC übereinstimmen. Ein Angreifer kann dies ausnutzen, um Signaturen Byte für Byte zu fälschen. `hash_equals()` vergleicht immer alle Zeichen.

## 410 Gone vs. 401 Unauthorized für abgelaufene Tokens

Benutzer profitieren davon zu wissen, ob ihr Link abgelaufen ist (und sie einen neuen anfordern sollten) gegenüber dem Fall, dass der Link nie gültig war. Der Signer verifiziert zunächst den HMAC, dann den Ablauf. Um diese in der HTTP-Antwort zu unterscheiden:

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

`extractExpiresAt()` decodiert nur Base64 und teilt bei `|` — es verifiziert NICHT den HMAC. Dies ist sicher, weil:
1. Der Ablauf ist kein Geheimnis (er ist in der signierten URL sowieso sichtbar).
2. Ein Angreifer kann keinen gültigen Token mit einem manipulierten Ablauf fälschen, weil `verify()` ihn ablehnt.
3. Die 410-Antwort liefert keine Informationen, die beim Fälschen von Tokens helfen.

NICHT verschiedene Fehlermeldungen für „HMAC-Mismatch" vs. „Ablauf vergangen" ausgeben — das würde einem Angreifer ermöglichen, zunächst gültige Signaturen für beliebige Ablaufwerte zu konstruieren und diese dann zur Timing-Sondierung zu verwenden.

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

Den Token immer mit `urlencode()` kodieren, bevor er in URLs eingebettet wird — Base64url-Zeichen sind URL-sicher, aber das `=`-Padding (falls vorhanden) ist es nicht, und das `|`-Trennzeichen im decodierten Payload darf nicht in der codierten Form erscheinen.

## Secret-Key-Verwaltung

- Das Secret aus einer Umgebungsvariablen injizieren — niemals hartcodieren.
- Mindestens 32 Byte Zufallsdaten verwenden (`random_bytes(32)` → Hex oder Base64).
- Für die Secret-Rotation mehrere Secrets gleichzeitig zur Verifizierung unterstützen (jedes ausprobieren, bis eines erfolgreich ist), dann das alte Secret auslaufen lassen.

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

## Zustandslose vs. zustandsbehaftete signierte URLs

Dieses Muster ist **zustandslos** — der Server verfolgt ausgestellte Tokens nicht. Dies ist der Hauptvorteil (kein DB-Lookup bei jedem Download), bedeutet aber:

- Signierte URLs können nicht vor ihrem Ablauf widerrufen werden.
- Wenn das Secret rotiert wird, werden alle zuvor ausgestellten Tokens sofort ungültig.

Für widerrufbare Tokens eine Blocklist-Tabelle (`revoked_tokens`) pflegen und während der Verifizierung prüfen. Dies tauscht den zustandslosen Vorteil gegen Widerrufbarkeit ein.

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| `===` oder `strcmp()` für HMAC-Vergleich verwenden | Timing-Angriff — ermöglicht das Fälschen von Signaturen |
| Nur `resource_id` ohne Ablauf signieren | Tokens sind dauerhaft — können nicht ablaufen |
| Nur `expires_at` ohne resource_id signieren | Ein Token gewährt Zugriff auf alle Ressourcen |
| Ablauf zur Unterscheidung von „manipuliert" vs. „abgelaufen" verwenden | Ermöglicht Oracle-Angriff auf HMAC |
| Rohen Schlüssel im Token einbetten | Macht den Zweck zunichte — Token muss opak sein |
| Lange TTLs (Tage/Wochen) | Erhöht das Expositionsfenster bei Token-Leak |
