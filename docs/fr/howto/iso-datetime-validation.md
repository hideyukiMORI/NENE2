# Comment valider les datetimes ISO 8601 avec fuseau horaire

Accepter des chaînes datetime contrôlées par l'utilisateur nécessite une validation soigneuse. Ce guide couvre les deux pièges les plus importants : **PHP acceptant silencieusement des offsets de fuseau horaire invalides**, et **la comparaison de chaînes échouant entre différents offsets de fuseau horaire**.

---

## V::isoDatetime — Validation de format

```php
V::isoDatetime(mixed $raw): ?string
```

Valide une chaîne datetime au format offset `±HH:MM` :

```
✅ 2024-01-15T12:30:00+09:00   (JST)
✅ 2024-06-01T00:00:00+00:00   (UTC)
✅ 2024-12-31T23:59:59-05:00   (EST)
✅ 2026-06-15T09:00:00-14:00   (UTC−14, île Howland)
✅ 2026-06-15T09:00:00+14:00   (UTC+14, Kiribati)

❌ 2024-01-15                   (date seule, pas d'heure)
❌ 2024-01-15T12:00:00Z         (suffixe 'Z', pas ±HH:MM)
❌ 2024-01-15T12:00:00          (pas d'offset du tout)
❌ 2024-02-30T00:00:00+00:00   (30 février n'existe pas)
❌ 2024-13-01T00:00:00+00:00   (mois 13 n'existe pas)
❌ 2026-06-15T09:00:00+25:00   (offset invalide — dépasse +14:00)
```

### Implémentation

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // Regex strict : ±HH:MM requis, pas de Z, pas d'heure seule
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Valider la plage de l'offset : les offsets UTC valides sont −14:00 … +14:00.
    // DateTimeImmutable de PHP accepte silencieusement +25:00 et autres offsets invalides.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable préserve le fuseau horaire d'entrée — évite strtotime + date()
    // qui reformate silencieusement dans le fuseau horaire local du serveur.
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // La comparaison aller-retour capture les dates de débordement (30 fév → 1er mars, etc.)
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### Pourquoi pas `strtotime` + `date()` ?

```php
// ❌ INCORRECT — date() utilise le fuseau horaire local du serveur
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// Si le serveur est UTC : '2024-01-15T03:30:00+00:00' — fuseau horaire perdu !
```

```php
// ✅ CORRECT — DateTimeImmutable préserve l'offset original
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — Vérification futur entre fuseaux horaires

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

Retourne la chaîne validée uniquement si la datetime est **strictement après** `$now`.

### Le bug critique : La comparaison de chaînes échoue entre fuseaux horaires

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 1 heure dans le PASSÉ
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ INCORRECT : la comparaison de chaînes dit futur ("T18" > "T10")
$pastJst > $now  // → TRUE   ← INCORRECT ! C'est dans le passé !

// ✅ CORRECT : La comparaison DateTimeImmutable normalise en UTC d'abord
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓ (correctement dans le passé)
```

L'erreur inverse se produit aussi avec les offsets négatifs :

```php
// EST 08:00 = UTC 13:00 → 3 heures dans le FUTUR
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ INCORRECT : la comparaison de chaînes dit passé ("T08" < "T10")
$futureEst > $now  // → FALSE  ← INCORRECT ! C'est dans le futur !

// ✅ CORRECT : comparaison d'objets
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓ (correctement dans le futur)
```

### Implémentation

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // La comparaison d'objets normalise les deux en UTC avant de comparer.
    return $dtObj > $nowObj ? $dt : null;
}
```

### Utilisation dans un gestionnaire de route

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // Utiliser DateTimeImmutable pour un "now" préservant le fuseau horaire
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt est maintenant sûr à stocker — la chaîne exacte soumise, fuseau horaire préservé.
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## Préservation du fuseau horaire

Stocker `remind_at` (ou tout datetime soumis par l'utilisateur) exactement tel que validé — ne pas convertir en UTC.

```php
// ✅ Stocker la chaîne validée telle quelle
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// avec :remind_at = '2026-06-15T09:00:00+09:00'

// Le retourner inchangé dans la réponse API
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

Cela respecte l'intention de l'utilisateur et évite la conversion implicite de fuseau horaire. Si votre application nécessite la normalisation UTC pour le tri/la comparaison en SQL, ajouter une colonne `remind_at_utc` séparée calculée au moment de l'écriture.

---

## Entrées validées → SQL sécurisé

Après `V::isoDatetime()` / `V::futureDatetime()`, la chaîne est sûre à insérer via une requête paramétrée. Ne jamais interpoler des chaînes datetime brutes dans du SQL.

```php
// ✅ Sûr — pré-validé, paramétré
$stmt->execute(['remind_at' => $remindAt]);

// ❌ Dangereux — entrée utilisateur brute interpolée
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## Références

- FT181 — reminderlog : Validation Datetime ISO 8601 & API avec conscience du fuseau horaire
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — Date et Heure sur Internet
- [Base de données de fuseaux horaires IANA](https://www.iana.org/time-zones) — Référence d'offset UTC
- `docs/howto/json-merge-patch.md` — utilise aussi isoDatetime pour created_at
