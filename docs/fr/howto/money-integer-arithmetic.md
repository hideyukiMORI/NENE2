# How-to : Argent et arithmétique en entiers

> **Scénarios associés** : DX Scénario 10, 23, 32, 36, 40, 43, 44, 50 — la source la plus fréquemment citée d'erreurs de précision silencieuses dans les scénarios financiers.

Les montants monétaires stockés comme virgule flottante (`REAL` / `float`) accumulent des erreurs d'arrondi. `1001 * 0.05` en IEEE 754 produit `50.049999999999997`, pas `50.05`. L'approche correcte est de stocker et calculer les montants comme des **entiers dans la plus petite unité monétaire** (yen pour JPY, centimes pour USD/EUR).

---

## La règle : toujours stocker comme entier

```php
// ❌ Faux — REAL/float accumule des erreurs
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ Correct — arithmétique en entiers
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50 (tronqué)
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

Schéma :

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ INTEGER, pas REAL
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

Utiliser des contraintes `CHECK` pour appliquer des valeurs non-négatives au niveau DB.

---

## Choisir la fonction d'arrondi

Lors de la division d'entiers, il faut décider comment gérer le reste. **Décider et documenter cette politique avant d'écrire le code** — la changer plus tard affecte chaque enregistrement historique.

| Fonction | Comportement | Exemple : `intdiv(1, 3)` | Quand l'utiliser |
|----------|--------------|--------------------------|------------------|
| `intdiv($a, $b)` | Tronquer vers zéro | `0` | Frais de plateforme (le payeur garde le reste) |
| `(int) round($a / $b)` | Arrondir à la moitié supérieure | `0` (arrondit à 0) | Partage de factures, arrondi générique |
| `(int) ceil($a / $b)` | Arrondir vers le haut (plafond) | `1` | Calcul de taxe (toujours arrondir vers le haut pour l'État) |
| `(int) floor($a / $b)` | Arrondir vers le bas (plancher) | `0` | Identique à intdiv pour les valeurs positives |

### Frais de plateforme (5%) — qui garde le reste ?

```php
// Option A : la plateforme prend le plancher (favorable au payeur)
$fee = intdiv($amount * 5, 100);     // 1001 yen → frais = 50, vendeur reçoit 951

// Option B : la plateforme prend le plafond (favorable à la plateforme)
$fee = (int) ceil($amount * 5 / 100); // 1001 yen → frais = 51, vendeur reçoit 950

// Option C : arrondir à la moitié supérieure (neutre)
$fee = (int) round($amount * 5 / 100); // 1001 yen → frais = 50, vendeur reçoit 951
```

Il n'y a pas de réponse universellement correcte. **Documenter le choix dans la spécification API.**

---

## Calcul de taxe (taxe de consommation japonaise : 10%)

La taxe de consommation japonaise nécessite un **arrondi vers le bas** par transaction (pas par poste) :

```php
// ✅ Tronquer au niveau de la transaction
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ Ne PAS arrondir par poste puis sommer — les erreurs d'arrondi s'accumulent
$items = [100, 100, 100]; // 3 articles × 100 yen
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // peut différer de intdiv(300 * 110, 100)
```

Si on stocke un `tax_rate`, le stocker en **points de base** (entier, 1/10000) :
`10% = 1000 bps`. Évite la virgule flottante dans le stockage du taux lui-même.

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10.00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## Répartition : distribution du reste

Lors de la répartition d'un total entre N participants :

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // part de chaque personne (tronquée)
    $remainder = $totalYen % $n;              // yen restant (0 à n-1)

    $shares = array_fill(0, $n, $base);

    // Distribuer le reste 1 yen à la fois aux premiers participants
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // Vérifier : la somme doit être égale au total original
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (somme = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (somme = 100)  ✅
```

Ne jamais utiliser `round($total / $n)` pour chaque participant et considérer que c'est terminé — la somme sera souvent décalée de 1 yen.

---

## Piège de la division entière SQLite

Dans SQLite, diviser deux entiers effectue une division entière :

```sql
SELECT 5 / 100;     -- → 0  (division entière : tronque)
SELECT 5.0 / 100;   -- → 0.05 (division réelle)
SELECT 5 * 100 / 100;  -- → 5 (multiplier d'abord, puis diviser — OK)
```

**En PHP** avec PDO, toutes les valeurs liées sont envoyées comme chaînes. SQLite les contraint, mais :

```php
// Sûr : multiplier d'abord pour éviter la troncation
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 d'abord (entier * entier = entier), puis / 100

// Risqué : si PDO envoie '5' et '100' comme chaînes, SQLite peut choisir la division réelle
// Tester si la version SQLite ou le comportement PDO est incertain.
```

L'approche la plus sûre : **effectuer l'arithmétique en PHP avec `intdiv()`**, stocker le résultat, et utiliser l'arithmétique SQL uniquement pour la sommation (`SUM`, `COUNT`), pas pour le calcul par ligne.

---

## Dépréciation (méthode linéaire)

```php
// Dépréciation annuelle (méthode linéaire)
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// Valeur comptable courante
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` tronque la dépréciation annuelle, ce qui signifie que l'actif se déprécie légèrement moins par an et que le reste apparaît comme dépréciation supplémentaire de la dernière année. C'est le comportement standard pour la dépréciation linéaire japonaise.

---

## Affichage à l'utilisateur

Convertir en format lisible uniquement à la couche de réponse, jamais dans le domaine :

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1,234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

Stocker `amountYen` (entier) pour les calculs ultérieurs ; `displayAmount` (chaîne) pour l'UI. Ne jamais stocker de chaînes formatées — elles ne peuvent pas être sommées.

---

## Résumé : checklist de décision

Avant d'écrire tout calcul monétaire, répondre à ces questions :

1. **Unité** : yen (pas de décimale), centimes (1/100), ou micro-pennies (1/1000) ?
   → Stocker comme entier dans cette unité ; documenter l'unité dans le nom de colonne (`amount_yen`, `price_cents`).

2. **Direction d'arrondi** : `intdiv` (tronquer), `ceil`, `floor` ou `round` ?
   → Choisir une ; ajouter un commentaire dans le code expliquant pourquoi.

3. **Qui prend le reste** : lors d'un partage, qui absorbe la différence d'arrondi ?
   → Distribuer le reste explicitement (voir `splitEvenly` ci-dessus).

4. **Stockage du taux de taxe** : points de base (`INTEGER`) pas pourcentage (`REAL`) ?
   → `1000` pour 10%, `800` pour 8%, jamais `0.10` ou `0.08`.

5. **Cumulatif ou par transaction** : accumuler la taxe par poste ou par total de facture ?
   → Par transaction (simple `intdiv`) est le standard pour les factures JPY.

---

## Howtos associés

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — grand livre en double entrée avec objet de valeur `Money`
- [`point-ledger-api.md`](point-ledger-api.md) — système de points/crédits avec montants entiers
- [`expense-tracking-api.md`](expense-tracking-api.md) — enregistrement de dépenses avec yen entier
