# How-to : Grand livre multi-devises avec centimes entiers

> **Référence FT** : FT262 (`NENE2-FT/moneylog`) — API de grand livre multi-devises utilisant des unités mineures entières (centimes) et un objet de valeur `Money`

Démontre une API de grand livre de type double entrée qui stocke les montants monétaires comme des unités mineures entières (centimes, yen, pence) pour éviter les erreurs de précision en virgule flottante. Un objet de valeur `Money` applique les invariants : montant positif et code de devise ISO 4217 à 3 caractères. Le solde par devise est calculé avec `SUM(CASE WHEN type = 'credit' ...)` dans une seule requête SQL.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/entries` | Créer une écriture comptable (crédit ou débit) |
| `GET` | `/entries` | Lister les écritures (paginées) |
| `GET` | `/entries/{id}` | Obtenir une seule écriture |
| `GET` | `/balance` | Solde par devise (crédit − débit) |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` applique les montants positifs au niveau DB — filet de sécurité pour les bugs ou l'accès direct à la DB. `CHECK(length(currency) = 3)` applique le format ISO 4217. `CHECK(type IN ('credit', 'debit'))` prévient les états invalides.

---

## Pourquoi des centimes entiers, pas des floats ?

```php
// ❌ L'arithmétique float perd de la précision
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ L'arithmétique entière est exacte
$total = 10 + 20;     // int(30) — toujours exact
```

Les montants monétaires stockés comme `FLOAT` accumulent des erreurs d'arrondi sur les sommes et ne peuvent pas être comparés de façon fiable avec `===`. Les unités mineures entières (centimes pour USD/EUR, yen pour JPY) sont toujours exactes. La conversion d'affichage (`$cents / 100.0`) n'a lieu qu'à la sérialisation, pas dans la logique métier.

**Mise en garde** : `JPY` et les devises similaires à zéro décimale stockent les unités entières comme "centimes" (c.-à-d., ¥1000 = 1000 centimes). `formatDecimal()` dans ce FT utilise 2 décimales par défaut ; une implémentation en production devrait rechercher les décimales de la devise.

---

## Objet de valeur `Money`

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

Le constructeur valide ses propres invariants. Un objet `Money` qui existe est toujours valide — les appelants n'ont jamais besoin de revérifier les valeurs. `readonly` empêche la mutation après construction.

`formatDecimal()` est uniquement pour l'affichage. Ne jamais stocker ou comparer la chaîne formatée ; toujours comparer les entiers `amountCents`.

---

## Enum backed `EntryType`

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

`EntryType::from('credit')` dans l'hydratation convertit la chaîne DB en enum. Si la DB contient une valeur inattendue, `from()` lève une exception — pas de corruption silencieuse.

`EntryType::tryFrom($value)` dans le contrôleur retourne `null` pour les valeurs inconnues, que la vérification d'erreur de validation capture ensuite :

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## Solde par devise : `SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

Une seule requête calcule trois agrégats par devise :
- `credit_cents` : total des crédits
- `debit_cents` : total des débits
- `balance_cents` : solde net (`crédit − débit`)

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` utilise un changement de signe pour calculer le solde net en un seul passage. Un `balance_cents` négatif signifie que les débits dépassent les crédits.

**Alternative** : deux requêtes (`SELECT SUM WHERE type = 'credit'` et `SELECT SUM WHERE type = 'debit'`), fusionnées en PHP. L'approche à requête unique est plus efficace et garde la soustraction dans SQL.

---

## Contrôleur : normalisation de devise

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← normaliser en majuscules
);
```

`strtoupper()` normalise le code de devise pour que `usd`, `USD` et `Usd` soient tous stockés comme `USD`. Sans normalisation, `USD` et `usd` apparaîtraient comme des devises séparées dans la requête de solde.

---

## Sérialisation : centimes et décimal

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // lisible par machine : entier exact
        'amount'       => $entry->money->formatDecimal(), // lisible par humain : "10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

`amount_cents` (entier) et `amount` (décimal formaté) sont tous deux retournés. Les clients effectuant des calculs devraient utiliser `amount_cents` ; les UIs d'affichage peuvent utiliser `amount`.

---

## Exemple : réponse de solde

**Requête** : `GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

Solde EUR : 500.00 − 200.00 = 300.00 EUR. Solde USD : 1500.00 − 750.00 = 750.00 USD.

---

## Comparaison de designs

| Approche de stockage | Précision | Compromis |
|---|---|---|
| Centimes `INTEGER` | Exact | Nécessite une conversion d'affichage ; la devise doit spécifier les décimales |
| `DECIMAL(19,4)` | Exact | Natif DB ; pas disponible dans SQLite ; formater pour l'affichage |
| `FLOAT`/`REAL` | Imprécis | Ne jamais utiliser pour l'argent — les erreurs d'arrondi s'accumulent |
| `TEXT` ("10.50") | N/A | Le tri et la somme nécessitent un cast ; pas d'arithmétique en SQL |

L'`INTEGER` SQLite avec centimes est l'approche sûre la plus simple pour les APIs basées sur SQLite. Pour MySQL/PostgreSQL, `DECIMAL(19,4)` est plus conventionnel.

---

## Howtos associés

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — écriture atomique multiple pour les transferts de fonds
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — importation d'écritures en masse avec succès partiel
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — requêtes d'agrégation avec fonctions de fenêtre SQL
