# API raccourcisseur d'URL et prévention SSRF

**FT183** — field trial `shortlog` (diagnostic de vulnérabilités VULN-A à L).

Un raccourcisseur d'URL permet aux utilisateurs de soumettre des URLs arbitraires comme cibles de redirection. Si la redirection est suivie côté serveur (ex. pour l'aperçu de lien ou les analytiques) sans validation, les attaquants peuvent la pointer vers des services internes — c'est une attaque **Server-Side Request Forgery (SSRF)**.

Ce guide couvre la prévention SSRF ainsi que le diagnostic de sécurité VULN-A à L complet exécuté contre l'implémentation shortlog.

---

## SSRF : Le risque principal

Un raccourcisseur d'URL stocke et potentiellement récupère une URL contrôlée par l'attaquant. Le SSRF permet à un attaquant de :

- Atteindre des services internes : `http://10.0.0.1/admin`, `http://192.168.1.1/`
- Accéder aux métadonnées cloud : `http://169.254.169.254/latest/meta-data/` (AWS IMDS)
- Lire des fichiers locaux : `file:///etc/passwd`
- Exécuter des scripts navigateur : `javascript:alert(1)`
- Accéder aux services loopback : `http://127.0.0.1:8080/`

**La correction :** valider le schéma _et_ l'IP de destination de l'URL avant de la stocker.

---

## Stratégie de validation d'URL (VULN-K)

### Étape 1 — Liste blanche de schémas

`filter_var($url, FILTER_VALIDATE_URL)` seul est **insuffisant** — il accepte
`javascript:alert(1)` et `ftp://` comme URLs valides. Utiliser `parse_url()` et une
liste blanche de schémas explicite :

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // URL malformée — pas de schéma ou d'hôte
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // Rejette : javascript:, file://, ftp://, data:, etc.
}
```

`parse_url()` n'est pas une regex — elle ne peut pas être exploitée pour ReDoS (VULN-F).

### Étape 2 — Validation hôte / IP

```php
$host = strtolower($parts['host']);

// Supprimer les crochets IPv6 : [::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// Bloquer localhost et les alias *.localhost
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// Si l'hôte est un IP littéral, le vérifier directement
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// Sinon résoudre le nom d'hôte → vérifier l'IP résolue
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // faux si non résolvable
    return !isBlockedIp($resolved);
}
// Nom d'hôte non résolvable → autoriser (peut être un domaine valide non joignable depuis le serveur)
return true;
```

### Étape 3 — Vérification d'IP privée / réservée

```php
function isBlockedIp(string $ip): bool
{
    // Loopback IPv6
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE : bloque 10.x, 172.16-31.x, 192.168.x
    // FILTER_FLAG_NO_RES_RANGE :  bloque 127.x, 169.254.x, 0.x, 240.x+
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### Mise en garde sur le DNS rebinding

Les attaques de DNS rebinding changent l'IP d'un domaine _après_ que la validation passe. Pour les cas d'usage critiques, valider l'URL également au _moment de la récupération_ (pas seulement au moment du stockage), ou utiliser un pare-feu d'egress réseau qui bloque les plages privées.

---

## Injecter le résolveur pour les tests

Les appels DNS dans les tests unitaires sont lents et non-déterministes. Rendre le résolveur injectable :

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

Dans les tests :

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // privé → bloqué
        'public.example.com' => '93.184.216.34',  // public → autorisé
        default              => $host,             // non résolvable → autorisé
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## Résultats du diagnostic VULN-A à L

### VULN-A — Débordement d'entier (paramètre de requête `limit`)

`V::queryInt()` utilise `ctype_digit()` + garde `strlen() > 18`.
Les chaînes de 20 et 19 chiffres sont rejetées avant le cast `(int)`.

```
✅ PASS — la garde de débordement empêche le wrap silencieux de PHP_INT_MAX
```

### VULN-B — Confusion de type (URL / slug depuis le corps JSON)

`V::str()` impose `is_string()` — rejette `int 42`, `bool true`, `null`.

```php
V::str($body['original_url'] ?? null, 2048)  // → null pour les non-chaînes
V::str($body['slug'] ?? null, 20)            // → null pour les non-chaînes
```

```
✅ PASS — type chaîne imposé avant toute validation d'URL ou de slug
```

### VULN-C — Injection SQL

Toutes les requêtes utilisent des instructions préparées PDO paramétrées :

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` échoue à la validation de format de slug (SLUG_PATTERN)
avant d'atteindre la DB. Même s'il atteignait la DB, les requêtes paramétrées empêchent l'exécution.

```
✅ PASS — requêtes paramétrées + liste blanche de slug
```

### VULN-D — Pollution de paramètre

`getQueryParams()` de PSR-7 appelle `parse_str()` de PHP qui prend la _dernière_
valeur pour les clés dupliquées. Envoyer `?limit=10&limit=999999` → `limit=999999`
qui échoue la vérification de plage `V::queryInt()` (> MAX_LIMIT).

```
✅ PASS — la vérification de plage capture toute valeur unique ; pas de crash
```

### VULN-E — IDOR (accès de lien cross-utilisateur)

DELETE utilise `deleteForUser($slug, $userId)` :

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

`DELETE /links/user-a-slug` de l'utilisateur B avec son propre `X-User-Id` retourne 404
(la ligne n'est pas supprimée ; elle ne correspond tout simplement pas à la clause WHERE).

```
✅ PASS — propriété imposée au niveau DB ; 404 évite l'énumération
```

### VULN-F — Immunité ReDoS

La validation d'URL utilise `parse_url()` (extension C, pas de backtracking).
La validation de slug utilise une regex simple ancrée sans groupes d'alternance.
`V::queryInt()` utilise `ctype_digit()` (O(n), immunisé contre le backtracking).

```
✅ PASS — pas de regex à backtracking exponentiel sur les entrées non fiables
```

### VULN-G — Traversée de chemin

Pas d'accès au système de fichiers dans cette API. Non applicable.

```
N/A
```

### VULN-H — Attaques de timing sur la comparaison de secret

`V::secret()` délègue à `hash_equals()` — temps constant quelle que soit la position
où les chaînes diffèrent. Évite la comparaison de chaîne à sortie anticipée qui fuit
des informations de longueur/préfixe via le timing.

```
✅ PASS — hash_equals() prévient l'oracle de timing
```

### VULN-I — Contournement par secret attendu vide

`V::secret('', '')` → `false`. Une clé API non configurée n'accorde jamais l'accès :

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ PASS — l'attendu vide retourne toujours faux
```

### VULN-J — Débordement de date ISO 8601 dans `expires_at`

`V::isoDatetime()` utilise `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` +
comparaison aller-retour. `2024-02-30T00:00:00+00:00` bascule au 1er mars en PHP ;
la chaîne reformatée ne correspond pas à l'entrée → null.

Décalage `+25:00` : capturé par la vérification de plage explicite `$tzHours > 14` (PHP l'accepte
silencieusement sans la vérification, et l'aller-retour passe également — rendant la vérification
explicite obligatoire).

```
✅ PASS — l'aller-retour capture les dates de débordement ; la vérification de plage de décalage explicite capture +25:00
```

### VULN-K — SSRF

Sans validation d'URL : `http://127.0.0.1/admin`, `http://169.254.169.254/`,
`http://10.0.0.1/`, `javascript:alert(1)`, `file:///etc/passwd` seraient tous
stockés et potentiellement récupérés.

Avec `UrlValidator` :

| Entrée | Raison du blocage |
|--------|-------------------|
| `http://127.0.0.1/` | IP loopback (`NO_RES_RANGE`) |
| `http://localhost/` | correspondance exacte `'localhost'` |
| `http://internal.localhost/` | suffixe `.localhost` |
| `http://10.0.0.1/` | IP privée (`NO_PRIV_RANGE`) |
| `http://192.168.1.1/` | IP privée |
| `http://169.254.169.254/` | IP réservée (`NO_RES_RANGE`) |
| `http://private.internal/` | se résout en 10.0.0.1 → bloqué |
| `javascript:alert(1)` | schéma absent de `['http','https']` |
| `file:///etc/passwd` | schéma absent de la liste blanche |
| `ftp://example.com/` | schéma absent de la liste blanche |

```
✅ PASS — liste blanche de schémas + filtre de plage d'IP bloque tous les vecteurs SSRF
```

### VULN-L — Assignation de masse

`click_count` et `created_at` sont définis côté serveur dans `LinkRepository::create()`.
Les clés du corps de la requête `click_count: 999999` et `created_at: "2000-01-01..."` sont
simplement ignorées — le contrôleur ne les lit jamais.

```
✅ PASS — champs côté serveur définis dans le repository, jamais depuis le corps de la requête
```

---

## Résumé du diagnostic VULN

| ID | Vulnérabilité | Statut |
|---|---|---|
| VULN-A | Débordement d'entier | ✅ PASS |
| VULN-B | Confusion de type | ✅ PASS |
| VULN-C | Injection SQL | ✅ PASS |
| VULN-D | Pollution de paramètre | ✅ PASS |
| VULN-E | IDOR | ✅ PASS |
| VULN-F | ReDoS | ✅ PASS |
| VULN-G | Traversée de chemin | N/A |
| VULN-H | Attaques de timing | ✅ PASS |
| VULN-I | Contournement par secret vide | ✅ PASS |
| VULN-J | Débordement DateTime | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | Assignation de masse | ✅ PASS |

**Toutes les vulnérabilités applicables : PASS (11/11)**

---

## Sécurité des slugs (VULN-A, C)

Les slugs doivent être restreints à un jeu de caractères sûr pour prévenir à la fois l'injection
et le routage inattendu :

```php
// Pattern : alphanumérique minuscule + tirets/tirets bas, 3–20 caractères
// Doit commencer et finir par alphanumérique
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

Cette regex unique est ancrée et n'a pas de groupes d'alternance avec des chemins de correspondance qui se chevauchent — elle ne peut pas être exploitée pour ReDoS.

**Slugs rejetés** : `'; DROP TABLE links; --'` · `../../etc` · `MySlug` · `sl@g!` · `a` (trop court) · chaîne de 21 caractères (trop long)

---

## Points clés

| Pattern | Implémentation |
|---------|----------------|
| Prévention SSRF | Liste blanche de schémas `parse_url()` + `filter_var NO_PRIV_RANGE` |
| Résolution DNS dans les tests | Callback `ipResolver` injectable |
| Sécurité des slugs | Regex de liste blanche de caractères (ancrée, pas de backtracking) |
| Application de type URL | `V::str()` → `is_string()` avant le parsing d'URL |
| Validation de l'expiration | `V::isoDatetime()` avec aller-retour + vérification de plage de décalage |
| Prévention IDOR | `WHERE slug = ? AND user_id = ?` dans chaque requête d'écriture |
| Assignation de masse | Champs côté serveur définis dans le repository, ignorés dans le contrôleur |
