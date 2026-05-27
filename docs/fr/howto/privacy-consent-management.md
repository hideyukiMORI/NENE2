# Comment implémenter la gestion du consentement vie privée

> **Pattern prouvé par FT189 consentlog** — Suivi du consentement style GDPR avec historique immuable, prévention IDOR et résistance à l'énumération d'utilisateurs. VULN-A〜L tout en succès.

---

## Ce que couvre ce guide

Un flux de gestion du consentement vie privée :

1. **Accorder le consentement** — l'utilisateur accorde son consentement pour une finalité nommée
2. **Retirer le consentement** — l'utilisateur retire son consentement
3. **Lister les consentements** — état actuel des consentements pour toutes les finalités
4. **Historique** — journal d'audit immuable en ajout seul par finalité

Garanties de sécurité :

| Préoccupation | Technique |
|---|---|
| IDOR — consentements d'un autre utilisateur | Toutes les requêtes scopent `WHERE user_id = :user_id` |
| Mass assignment (champ granted) | `granted` est contrôlé par le serveur ; le body ne peut pas le remplacer |
| Injection SQL dans purpose | `ctype_alnum()` — alphanumérique uniquement |
| ReDoS dans purpose | `ctype_alnum()` O(n) — pas de regex |
| Confusion de type | `is_string()` avant `ctype_alnum()` |
| Énumération d'utilisateurs | Utilisateur inconnu retourne un tableau vide, pas 404 |
| Condition de course sur grant/withdraw | Atomicité UPSERT sur `UNIQUE(user_id, purpose)` |
| Rejeu de consentement | L'historique est en ajout seul ; chaque changement est une nouvelle entrée |

---

## Schéma

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- slug alphanumérique : 'marketing', 'analytics', etc.
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=accordé, 0=retiré
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=accordé, 0=retiré
    created_at TEXT    NOT NULL    -- quand ce changement s'est produit
);
```

`UNIQUE(user_id, purpose)` permet un upsert atomique. `consent_history` est en ajout seul — jamais mis à jour.

---

## API

| Méthode | Chemin | En-tête | Description |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Accorder le consentement (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Retirer le consentement (200) |
| `GET` | `/consents` | `X-User-Id` | Lister les consentements actuels |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Historique d'audit (ajout seul) |

---

## Pattern de base : UPSERT idempotent

```php
// Grant — idempotent : re-accorder une finalité déjà accordée est sûr
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// Withdraw — même pattern
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

L'UPSERT sur `UNIQUE(user_id, purpose)` est atomique — prévient les conditions de course où un grant+withdraw simultané pourrait créer une ligne dupliquée.

---

## Pattern de base : Historique immuable

```php
// Toujours ajouter à l'historique — même un re-grant est enregistré
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

L'historique n'est **jamais mis à jour** — c'est un journal d'audit de chaque changement de consentement. Cela permet aux régulateurs de vérifier quand le consentement a été donné et quand il a été retiré.

---

## Pattern de base : Validation de la finalité

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G : confusion de type — doit être une chaîne
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I : ctype_alnum est O(n) — pas de regex, pas de ReDoS
    // VULN-D : alphanumérique uniquement — pas de HTML, pas de caractères spéciaux SQL
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` accepte uniquement `[a-zA-Z0-9]` — rejetant les espaces, les tirets, les métacaractères SQL et les balises HTML en un seul passage O(n).

---

## Pattern de base : Prévention de l'énumération d'utilisateurs

```php
// VULN-F : retourner un tableau vide pour un utilisateur inconnu — pas 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

Retourner 404 pour un utilisateur inconnu révèle "cet user_id n'existe pas." Toujours retourner 200 avec des données vides.

---

## Pattern de base : Prévention IDOR

```php
// VULN-B : toutes les lectures et écritures scopent à l'utilisateur authentifié
// Même si un attaquant envoie X-User-Id: 999, il ne voit que les données de l'utilisateur 999
WHERE user_id = :user_id AND purpose = :purpose
```

Aucune requête inter-utilisateurs ne touche jamais l'enregistrement d'un autre utilisateur.

---

## Pattern de base : Champ granted contrôlé par le serveur

```php
// VULN-C/E : granted est contrôlé par l'endpoint — jamais depuis le body
// POST /consents → accorde toujours (granted = 1)
// DELETE /consents/{purpose} → retire toujours (granted = 0)
// Body { "granted": false } sur POST est silencieusement ignoré
```

L'endpoint lui-même détermine la valeur de `granted`. Un champ dans le body ne peut jamais la remplacer.

---

## Conception des réponses

| Scénario | Statut | Body |
|---|---|---|
| Grant réussi | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| Withdraw réussi | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| Lister les consentements | 200 | `{data: [...], total: N}` |
| Historique | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| Utilisateur inconnu | 200 | `{data: [], total: 0}` — pas 404 |

`user_id` n'est **jamais** inclus dans aucune réponse — il est implicite via `X-User-Id`.

---

## VULN-A〜L tout en succès

| VULN | Attaque | Défense |
|---|---|---|
| A | Injection SQL dans X-User-Id | `ctype_digit()` + garde strlen > 18 |
| B | IDOR — manipulation du consentement d'un autre utilisateur | Toutes les requêtes avec `WHERE user_id = :user_id` |
| C | Mass assignment (falsification du champ granted) | granted est déterminé par l'endpoint — body non utilisé |
| D | XSS dans purpose | `ctype_alnum()` — alphanumérique uniquement |
| E | Réécriture directe de l'état de consentement | grant/withdraw sont des endpoints indépendants |
| F | Énumération d'utilisateurs | user_id inconnu retourne un tableau vide 200 |
| G | Confusion de type (purpose comme int/array/null) | `is_string()` + `ctype_alnum()` |
| H | Rejeu de consentement | history est append-only, le re-grant crée une nouvelle entrée |
| I | ReDoS dans purpose | `ctype_alnum()` O(n) |
| J | Dépassement d'entier dans X-User-Id | garde strlen > 18 |
| K | Condition de course grant+withdraw simultané | Atomicité UPSERT `UNIQUE(user_id, purpose)` |
| L | Injection CRLF dans les en-têtes | PSR-7 refuse au niveau HTTP |

---

## Résultats des tests (FT189)

```
51 tests / 142 assertions — tout PASS
PHPStan level 8 — pas d'erreurs
PHP CS Fixer — propre
VULN-A〜L tout en succès
```

Source : [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
