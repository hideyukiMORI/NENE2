# How-to : API de suivi budgétaire

> **Référence FT** : FT244 (`NENE2-FT/budgetlog`) — API de suivi budgétaire
> **ATK** : FT244 — test d'attaque cracker-mindset (ATK-01 à ATK-12)

Montre une API de suivi budgétaire multi-comptes avec les types de transaction `income`/`expense`/`transfer`, `TransferFundsUseCase` avec vérification du solde dans une transaction DB, listing de transactions multi-filtres avec `QueryStringParser` et agrégation par catégorie.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/accounts` | Lister tous les comptes |
| `POST` | `/accounts` | Créer un compte (solde initial optionnel) |
| `GET` | `/accounts/{id}` | Obtenir un compte spécifique |
| `POST` | `/accounts/{id}/transactions` | Enregistrer une transaction income ou expense |
| `GET` | `/accounts/{id}/transactions` | Lister les transactions (filtrable, paginé) |
| `GET` | `/accounts/{id}/summary` | Solde + income/expense par catégorie |
| `POST` | `/transfers` | Transférer des fonds entre deux comptes |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` et `amount` sont stockés comme entiers (plus petite unité monétaire, ex. centimes). `type` est contraint par `CHECK(type IN ('income','expense','transfer'))` au niveau DB. `recurring` est stocké comme `INTEGER` (`0`/`1`), mappé sur un `bool` PHP.

---

## Liste d'autorisation des types de transaction

Le contrôleur valide `type` contre une liste d'autorisation explicite :

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

Seuls `income` et `expense` sont acceptés via API. Le type `transfer` est défini en interne par `TransferFundsUseCase` — les appelants ne peuvent pas l'injecter directement via `POST /accounts/{id}/transactions`.

---

## Mise à jour du solde : pattern lecture-puis-mise-à-jour

`POST /accounts/{id}/transactions` met à jour le solde du compte après avoir enregistré la transaction :

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

Le solde est d'abord lu (`findById`), le delta appliqué en PHP, puis réécrit (`updateBalance`). Ceci **n'est pas atomique** — les requêtes concurrentes peuvent créer une race condition (voir ATK-09).

---

## TransferFundsUseCase : vérification du solde + transaction DB

Les transferts sont enveloppés dans une transaction DB pour assurer la cohérence :

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // Instancier les repos dans le callback avec l'exécuteur de transaction
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

Les repositories sont instanciés **à l'intérieur** du closure de transaction avec l'exécuteur `$tx` — cela assure que toutes les lectures et écritures partagent la même connexion et frontière de transaction. Si une étape lance une exception, toute la transaction est annulée.

La garde de même compte est dans le contrôleur :
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## Listing de transactions multi-filtres

`GET /accounts/{id}/transactions` supporte plusieurs filtres simultanés :

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` retourne `null` quand le paramètre est absent — pas de filtre. `QueryStringParser::bool()` retourne `null` quand absent, `true` pour `"true"/"1"`, `false` pour `"false"/"0"`.

Le repository construit la clause `WHERE` dynamiquement :

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## Agrégation du résumé par catégorie

`GET /accounts/{id}/summary` retourne le solde et les totaux groupés par catégorie :

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

Le repository utilise `GROUP BY category` avec `SUM(amount)` :

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK — Test d'attaque cracker-mindset (FT244)

### ATK-01 — Pas d'authentification : comptes et transactions sont publics

**Attaque** : Lister tous les comptes sans accréditations.

```bash
curl -s http://localhost:8080/accounts
curl -s http://localhost:8080/accounts/1/transactions
```

**Observé** : Les deux endpoints retournent des données sans authentification. N'importe quel appelant peut énumérer tous les comptes et leurs soldes.

**Verdict** : **EXPOSÉ** — ajouter une authentification (clé API, JWT, ou session) à tous les endpoints. Les comptes devraient être scopés par utilisateur.

---

### ATK-02 — Créer un compte avec un solde initial négatif

**Attaque** : Contourner la vérification de solde négatif.

```json
{"name": "Attack", "initial_balance": -99999}
```

**Observé** : La vérification `$initialBalance < 0` se déclenche → `422 Unprocessable Entity` avec erreur `out_of_range`.

**Verdict** : **BLOQUÉ** — la garde explicite rejette les soldes initiaux négatifs.

---

### ATK-03 — Une dépense rend le solde du compte négatif

**Attaque** : Enregistrer une dépense supérieure au solde du compte via une transaction directe.

```bash
# Le compte a un solde de 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**Observé** : Le gestionnaire `createTransaction` lit le solde puis soustrait sans vérifier la suffisance. `100 - 99999 = -99899` — le solde est écrit comme entier négatif.

**Verdict** : **EXPOSÉ** — `POST /accounts/{id}/transactions` n'applique pas de contrainte de solde non négatif. Seul `POST /transfers` (via `TransferFundsUseCase`) vérifie `if ($from->balance < $amount)`. Ajouter une vérification de suffisance du solde dans `createTransaction` pour les transactions de dépense.

---

### ATK-04 — Injection SQL via category ou description

**Attaque** : Incorporer des métacaractères SQL dans `category` ou `description`.

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**Observé** : Toutes les valeurs sont liées comme valeurs `?` paramétrées. Aucune concaténation de chaînes avec SQL ne se produit. Le payload d'injection est stocké comme texte littéral.

**Verdict** : **BLOQUÉ** — les requêtes paramétrées empêchent l'injection SQL.

---

### ATK-05 — Montant float : troncation par cast `(int)`

**Attaque** : Envoyer un montant en virgule flottante.

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**Observé** : `(int) $body['amount']` tronque `1.9` en `1`. Le montant `1.9` est silencieusement accepté et stocké comme `1`. Un appelant s'attendant à ce que `1.9` soit rejeté serait surpris.

**Verdict** : **PARTIELLEMENT BLOQUÉ** — les floats non entiers sont acceptés et silencieusement tronqués. Utiliser `is_int($body['amount'])` pour rejeter explicitement les types non entiers, retournant `422` pour `1.9`.

---

### ATK-06 — Montant zéro ou négatif

**Attaque** : Soumettre `amount: 0` ou `amount: -100`.

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**Observé** : La vérification `$amount <= 0` se déclenche pour les deux → `422 Unprocessable Entity`.

**Verdict** : **BLOQUÉ** — la garde explicite rejette les montants zéro et négatifs.

---

### ATK-07 — Transfert vers le même compte

**Attaque** : Transférer des fonds d'un compte vers lui-même.

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**Observé** : `$fromId === $toId && $fromId > 0` se déclenche → `422 Unprocessable Entity` avec erreur `invalid_value` sur `to_account_id`.

**Verdict** : **BLOQUÉ** — le transfert vers le même compte est explicitement rejeté.

---

### ATK-08 — Transfert avec des fonds insuffisants

**Attaque** : Transférer plus que le solde du compte source.

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**Observé** : Dans la transaction, `$from->balance < $amount` se déclenche → `ValidationException` avec `insufficient_balance` → la transaction est annulée → `422`. Aucun solde ne change.

**Verdict** : **BLOQUÉ** — `TransferFundsUseCase` vérifie le solde dans la transaction DB. L'annulation assure l'atomicité.

---

### ATK-09 — Race condition sur une transaction de dépense directe

**Attaque** : Soumettre deux requêtes de dépense concurrentes qui passent toutes les deux la vérification du solde (il n'y en a pas) mais dépassent ensemble le solde.

**Observé** : `createTransaction` utilise un pattern lecture-puis-mise-à-jour sans transaction :
1. Thread A lit `balance = 100`
2. Thread B lit `balance = 100`
3. Thread A enregistre une dépense de 80 → écrit `balance = 20`
4. Thread B enregistre une dépense de 80 → écrit `balance = 20` (devrait être -60)

La colonne `balance` se retrouve à `20` au lieu du correct `-60` — mais plus critiquement, la contrainte métier (solde non négatif) n'est jamais du tout appliquée pour les transactions directes, permettant à ce chemin de contourner même la lecture-puis-mise-à-jour.

**Verdict** : **EXPOSÉ** — le chemin `createTransaction` n'a aucune garde de solde ni enveloppe de transaction. Corriger en : (1) ajoutant `if ($type === 'expense' && $account->balance < $amount) → 422`, et (2) enveloppant la lecture-puis-mise-à-jour dans une transaction DB.

---

### ATK-10 — Accéder aux transactions d'un autre compte (pas de propriété)

**Attaque** : Lire les transactions appartenant au compte d'un autre utilisateur.

```bash
curl -s http://localhost:8080/accounts/2/transactions
```

**Observé** : L'endpoint retourne toutes les transactions du compte 2 sans vérification de propriété. Comme il n'y a pas d'authentification, n'importe quel appelant peut lire n'importe quel compte.

**Verdict** : **EXPOSÉ** (même cause racine que ATK-01). Les comptes doivent être scopés à un utilisateur authentifié — `WHERE account_id = ? AND owner_id = ?`.

---

### ATK-11 — Champ `recurring` : coercition truthy

**Attaque** : Envoyer des valeurs non booléennes pour `recurring`.

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**Observé** : `(bool) $body['recurring']` coerce `"yes"` → `true`, `1` → `true`, `0` → `false`. Toute valeur de chaîne truthy définit `recurring = true`. Il n'y a pas de vérification stricte `is_bool()`.

**Verdict** : **PARTIELLEMENT BLOQUÉ** — les types non booléens sont silencieusement coercés. Utiliser `is_bool($body['recurring'])` pour l'application stricte des types, retournant `422` pour une entrée non booléenne.

---

### ATK-12 — ID de compte non numérique dans le chemin

**Attaque** : Passer un ID de chaîne dans le paramètre de chemin.

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**Observé** : `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → retourne `null` → `404 Not Found`.
- `1.5` → `findById(1)` → si le compte 1 existe, le retourne silencieusement.

**Verdict** : **PARTIELLEMENT BLOQUÉ** — les chaînes non numériques mappent sur 404. Les chaînes de float sont silencieusement tronquées. Ajouter la validation `ctype_digit()` pour une vérification stricte des paramètres de chemin.

---

## Récapitulatif ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Pas d'authentification (tous les endpoints publics) | EXPOSÉ |
| ATK-02 | Solde initial négatif | BLOQUÉ |
| ATK-03 | Dépense rend le solde négatif | EXPOSÉ |
| ATK-04 | Injection SQL via category/description | BLOQUÉ |
| ATK-05 | Montant float silencieusement tronqué | PARTIELLEMENT BLOQUÉ |
| ATK-06 | Montant zéro ou négatif | BLOQUÉ |
| ATK-07 | Transfert vers le même compte | BLOQUÉ |
| ATK-08 | Transfert avec des fonds insuffisants | BLOQUÉ |
| ATK-09 | Race condition sur dépense directe | EXPOSÉ |
| ATK-10 | Accès cross-compte aux données (pas de propriété) | EXPOSÉ |
| ATK-11 | Coercition non booléenne de `recurring` | PARTIELLEMENT BLOQUÉ |
| ATK-12 | ID de compte non numérique | PARTIELLEMENT BLOQUÉ |

**Vraies vulnérabilités à corriger avant la production** :
1. **ATK-01 / ATK-10** — Ajouter authentification et propriété de compte par utilisateur
2. **ATK-03 / ATK-09** — Ajouter vérification de suffisance du solde + transaction DB dans `createTransaction`
3. **ATK-05** — Remplacer le cast `(int)` par la vérification `is_int()` pour l'application stricte des types
4. **ATK-11** — Remplacer le cast `(bool)` par la vérification `is_bool()`
5. **ATK-12** — Ajouter une garde `ctype_digit()` pour les paramètres de chemin ID

---

## Guides associés

- [`credit-ledger.md`](credit-ledger.md) — grand livre append-only avec direction ±1 et InsufficientCreditsException
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — gestion de solde multi-devises
- [`transactions.md`](transactions.md) — patterns DatabaseTransactionManagerInterface
- [`note-management-ownership.md`](note-management-ownership.md) — propriété de ressource par utilisateur avec prévention IDOR
