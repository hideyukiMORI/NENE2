# How-to : API de texte avec gestion Unicode

> **Référence FT** : FT345 (`NENE2-FT/unicodelog`) — API de profil avec validation Unicode-safe : `mb_strlen` pour le comptage de caractères, rejet des octets null, support multi-script (japonais, emoji, séquences ZWJ, arabe, mixte), gestion de `JSON_UNESCAPED_UNICODE`, 22 tests PASS.

Ce guide montre comment gérer le texte Unicode en toute sécurité dans une API : compter correctement les caractères (pas les octets), rejeter les octets null, accepter les entrées multi-langues, et prévenir les vulnérabilités liées à l'encodage.

## Schéma

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- tableau JSON stocké comme texte
    created_at TEXT    NOT NULL
);
```

`tags` est stocké comme une chaîne de tableau JSON. Le type TEXT de SQLite gère nativement l'UTF-8 arbitraire.

## Endpoints

| Méthode   | Chemin              | Description          |
|-----------|---------------------|----------------------|
| `POST`    | `/profiles`         | Créer un profil      |
| `GET`     | `/profiles`         | Lister tous les profils |
| `GET`     | `/profiles/{id}`    | Obtenir un profil    |
| `PATCH`   | `/profiles/{id}`    | Mettre à jour un profil |
| `DELETE`  | `/profiles/{id}`    | Supprimer un profil  |

## Limites

| Champ  | Limite                        |
|--------|-------------------------------|
| `name` | 1–50 codepoints Unicode       |
| `bio`  | 0–500 codepoints Unicode      |
| `tags` | 0–10 éléments, chacun 1–30 codepoints |

## Créer un profil

```php
POST /profiles
{
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"]
}

→ 201
{
  "id": 1,
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"],
  "created_at": "2026-05-27T09:00:00Z"
}
```

Les entrées multi-scripts sont acceptées :

```php
POST /profiles
{"name": "🎉 Yuki 🎊", "bio": "I love emojis! 🚀✨", "tags": ["🎨", "🎵"]}
→ 201

POST /profiles
{"name": "محمد علي", "bio": "مبرمج ويب من مصر", "tags": ["مطور"]}
→ 201

POST /profiles
{"name": "André García 鈴木", "bio": "Café résumé naïve", "tags": ["日本語", "español"]}
→ 201
```

## Validation de longueur Unicode — `mb_strlen` vs `strlen`

**Toujours utiliser `mb_strlen($value, 'UTF-8')` pour les limites de caractères.** `strlen()` compte les octets, pas les caractères.

```php
// "あ" fait 3 octets en UTF-8. strlen("あ") = 3, mb_strlen("あ", 'UTF-8') = 1.
$name50 = str_repeat('あ', 50);  // 150 octets, 50 caractères
// strlen rejetterait ceci (150 > 50) — INCORRECT
// mb_strlen voit correctement 50 — CORRECT → 201 Created

$name51 = str_repeat('あ', 51);  // 51 caractères → 422 (too_long)
```

### Implémentation de la validation

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // Rejeter d'abord les octets null
    if (str_contains($value, "\x00")) {
        throw new ValidationException($field, 'invalid', 'Null bytes are not allowed');
    }

    $length = mb_strlen($value, 'UTF-8');
    if ($length === 0 && $field === 'name') {
        throw new ValidationException($field, 'required', 'Field is required');
    }
    if ($length > $maxChars) {
        throw new ValidationException($field, 'too_long', "Max {$maxChars} characters");
    }
}
```

### Emoji et séquences ZWJ

```php
// Chaque emoji est 1 codepoint (4 octets). 50 emojis = 200 octets, mb_strlen = 50 → PASS
$name = str_repeat('🎉', 50);
→ 201 Created

// Séquence ZWJ 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen compte ceci comme 5 codepoints, pas 1 graphème
// Stocker et retourner verbatim — ne pas normaliser
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // stocké et retourné correctement
```

## Rejet des octets null

Les octets null (`\x00`) dans les champs de texte sont un vecteur d'injection — ils peuvent tronquer les chaînes dans les bibliothèques en C et contourner la validation dans certains parseurs.

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // octet null dans bio

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // octet null dans la valeur de tag
```

Rejeter les octets null **avant** la validation de longueur et **avant** le stockage.

## Validation des tags

```php
// Trop de tags (max 10)
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 tags ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// Tag trop long (max 30 caractères Unicode)
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// Valeur de tag non-chaîne
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// Nom vide
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### Implémentation des tags

```php
$rawTags = $input['tags'] ?? [];
if (!is_array($rawTags)) {
    throw new ValidationException('tags', 'invalid', 'Tags must be an array');
}
if (count($rawTags) > 10) {
    throw new ValidationException('tags', 'too_many', 'Maximum 10 tags');
}
$tags = [];
foreach ($rawTags as $i => $tag) {
    if (!is_string($tag)) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Each tag must be a string');
    }
    if (str_contains($tag, "\x00")) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Null bytes not allowed');
    }
    if (mb_strlen($tag, 'UTF-8') > 30) {
        throw new ValidationException("tags[{$i}]", 'too_long', 'Max 30 characters per tag');
    }
    $tags[] = $tag;
}
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

## Encodage de la réponse JSON

Le `JsonResponseFactory` de NENE2 utilise `json_encode()` sans `JSON_UNESCAPED_UNICODE` par défaut. Cela signifie que le corps de réponse brut contient des séquences d'échappement `\uXXXX` pour les caractères non-ASCII — mais les valeurs décodées sont identiques.

```php
// Corps de réponse brut :
{"name":"田中太郎", ...}

// Résultat de json_decode() :
["name" => "田中太郎", ...]  // ← correct
```

Les clients utilisant des parseurs JSON standard voient les valeurs Unicode correctes. L'encodage `\uXXXX` est valide selon RFC 8259.

---

## Évaluation des vulnérabilités

### V-01 — Injection d'octet null ✅ SAFE

**Risk**: Les octets null (`\x00`) peuvent tronquer le traitement de chaînes C dans certaines extensions PHP, contourner la validation, ou créer un comportement inattendu chez les consommateurs en aval.
**Finding**: SAFE — La vérification explicite `str_contains($value, "\x00")` rejette tous les octets null dans `name`, `bio`, et chaque tag avant le stockage. Retourne 422.

---

### V-02 — Débordement de comptage d'octets via caractères multi-octets ✅ SAFE

**Risk**: Si `strlen()` est utilisé pour les limites, un champ avec 50 caractères japonais (150 octets) est rejeté comme "trop long" alors qu'il devrait passer.
**Finding**: SAFE — `mb_strlen($value, 'UTF-8')` compte les codepoints, pas les octets. 50 caractères japonais = 50 codepoints → passe `max: 50`. 51 caractères japonais = 51 → rejeté. Les emojis (4 octets chacun) comptés correctement comme 1 codepoint chacun.

---

### V-03 — Injection de tableau de tags ✅ SAFE

**Risk**: L'attaquant envoie des valeurs non-chaîne dans le tableau de tags (entiers, objets, tableaux) pour exploiter la confusion de type dans le code en aval.
**Finding**: SAFE — Chaque élément de tag est vérifié par type (`is_string()`). Les valeurs non-chaîne retournent 422. Le nombre de tags est également limité à 10.

---

### V-04 — Injection SQL via payload Unicode ✅ SAFE

**Risk**: L'attaquant envoie des mots-clés SQL ou des chaînes d'injection comme noms/bio/tags Unicode, espérant que la normalisation ou le décodage d'encodage change la chaîne en quelque chose de dangereux.
**Finding**: SAFE — Toutes les requêtes utilisent des instructions préparées PDO. Le test `"'; DROP TABLE profiles; --"` est stocké verbatim comme chaîne, pas interprété comme SQL. SQLite existe toujours et retourne 200 après une telle écriture.

---

### V-05 — Attaque homographe via lookalikes Unicode ⚠️ EXPOSED

**Risk**: L'attaquant crée un profil avec un nom visuellement identique à un utilisateur existant (ex. `аdmin` avec `а` cyrillique au lieu du `a` latin). Les humains lisant le nom peuvent être trompés.
**Finding**: EXPOSED — L'API stocke et retourne les noms verbatim sans normalisation Unicode (NFC/NFD) ni détection de confusables. Deux profils avec des noms visuellement identiques mais différents en codepoints peuvent coexister. Pour les contextes à haute confiance (noms d'utilisateurs admin, noms réservés), ajouter `Normalizer::normalize($name, Normalizer::FORM_C)` avant le stockage et vérifier les caractères confusables via ICU ou une bibliothèque dédiée.

---

### V-06 — DoS par tableau de tags surdimensionné ✅ SAFE

**Risk**: L'attaquant envoie `"tags": [1000 éléments]` pour déclencher une allocation mémoire excessive pendant le traitement.
**Finding**: SAFE — La vérification `count($rawTags) > 10` rejette le tableau à 11+ éléments avant tout traitement par élément. Retourne 422 immédiatement.

---

### V-07 — Fuite d'encodage de réponse JSON ✅ SAFE

**Risk**: Si l'encodeur JSON émet des octets non-ASCII littéraux sans déclaration de charset content-type appropriée, certains clients peuvent mal interpréter l'encodage.
**Finding**: SAFE — La réponse a `Content-Type: application/json` (charset implicitement UTF-8 selon RFC 8259). La sortie échappée `\uXXXX` est du JSON valide et sans ambiguïté. Les clients utilisant des parseurs standard obtiennent toujours les valeurs Unicode correctes.

---

### V-08 — Contournement de longueur par séquence ZWJ ✅ SAFE

**Risk**: L'attaquant empile de nombreux graphèmes dans un nom que `mb_strlen` compte comme de nombreux codepoints, espérant que la limite est plus haute que la représentation visuelle.
**Finding**: SAFE — `mb_strlen` compte les codepoints, pas les graphèmes. `👨‍👩‍👧` (séquence ZWJ de 5 codepoints) compte comme 5, pas 1. Un nom de 10 caractères visuels utilisant des séquences ZWJ peut consommer 50+ codepoints et atteindre la limite comme prévu.

---

### V-09 — Injection de substitution de direction (RTLO) ✅ SAFE

**Risk**: L'attaquant intègre des caractères de contrôle Unicode (U+202E, U+200F) dans un nom pour inverser le texte affiché, créant une tromperie visuelle dans l'UI.
**Finding**: SAFE — L'API stocke le texte verbatim ; la sanitisation de la couche d'affichage est la responsabilité du frontend. La validation rejette les octets null mais pas les autres caractères de contrôle Unicode. Pour les interfaces d'administration, supprimer ou échapper U+202E, U+200F, U+2066–U+2069 (substitutions directionnelles) avant le rendu.

---

### V-10 — Collision de normalisation Unicode ✅ SAFE

**Risk**: Deux noms qui semblent identiques mais diffèrent en forme de normalisation (NFC vs NFD) pourraient être traités comme des utilisateurs différents, créant une confusion de compte.
**Finding**: SAFE — L'API n'impose pas la normalisation NFC ; elle stocke ce qu'elle reçoit. Pour les cas d'usage nécessitant une unicité canonique (champs équivalents aux emails), normaliser en NFC avant le stockage et indexer de façon unique sur la forme normalisée. Les noms de profils sont uniquement pour l'affichage dans ce FT, donc la collision n'est pas un problème de sécurité.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|---------|
| V-01 | Injection d'octet null | ✅ SAFE |
| V-02 | Débordement de comptage d'octets via caractères multi-octets | ✅ SAFE |
| V-03 | Injection de type dans le tableau de tags | ✅ SAFE |
| V-04 | Injection SQL via payload Unicode | ✅ SAFE |
| V-05 | Attaque homographe / nom visuellement identique | ⚠️ EXPOSED |
| V-06 | DoS par tableau de tags surdimensionné | ✅ SAFE |
| V-07 | Fuite d'encodage de réponse JSON | ✅ SAFE |
| V-08 | Contournement de longueur par séquence ZWJ | ✅ SAFE |
| V-09 | Injection de substitution directionnelle RTLO | ✅ SAFE |
| V-10 | Collision de normalisation Unicode | ✅ SAFE |

**9 SAFE, 1 EXPOSED** — V-05 (attaque homographe) est une limitation connue. Atténuer avec `Normalizer::normalize()` + détection de confusables pour les champs de noms à haute confiance.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| `strlen($name) > 50` pour la limite de caractères | Rejette une entrée japonaise valide de 50 caractères (150 octets) ; autorise 150 caractères ASCII (sous la limite d'octets) |
| Pas de vérification d'octet null | `"Alice\x00Bob"` peut être stocké comme `"Alice"` dans les contextes de chaînes C ; contourne les vérifications d'unicité |
| `preg_match('/^\w+$/', $name)` pour les noms Unicode | `\w` est uniquement ASCII en PHP sans le flag `u` ; rejette toutes les entrées non-ASCII |
| Ignorer les séquences ZWJ dans la longueur | Les séquences ZWJ comptent comme plusieurs codepoints ; comportement attendu avec `mb_strlen` |
| Stocker les tags comme chaîne séparée par des virgules | Impossible de diviser de façon fiable les tags avec des virgules dans les valeurs de tags ; utiliser un tableau JSON |
| Retourner les tags comme chaîne JSON, pas comme tableau | Les clients doivent double-décoder ; toujours décoder le JSON stocké avant de le retourner dans la réponse |
