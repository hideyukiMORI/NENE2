# How-to : Ajouter le masquage de données

Masquer les champs PII (email, téléphone, nom) dans les réponses API par défaut, avec un chemin de démasquage admin audité.

## Schéma

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/customers` | Créer un client (réponse masquée) |
| `GET` | `/customers/{id}` | Obtenir un client (masqué par défaut, démasqué pour admin) |
| `GET` | `/customers/{id}/audit` | Voir le journal d'audit (admin uniquement) |

## Patterns de masquage

```php
class MaskService
{
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // Conserver les 4 derniers chiffres ; masquer tout le reste caractère par caractère
        $digits  = preg_replace('/\D/', '', $phone) ?? '';
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? '*' : $ch;
                if (ctype_digit($ch)) { $replaced++; }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        return implode(' ', array_map(
            fn($w) => mb_substr($w, 0, 1) . '***',
            array_filter(explode(' ', $name))
        ));
    }
}
```

Exemples :
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## Démasquage basé sur le rôle

Le gestionnaire vérifie l'en-tête `X-Role`. L'accès admin nécessite `X-Accessor` pour imposer la piste d'audit :

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // PII brut
}

return $this->json->create($this->masker->applyMask($customer));  // masqué
```

## Journal d'audit

Chaque démasquage admin écrit dans `mask_audit_log`. Le journal d'audit n'a pas de route DELETE ou UPDATE — il est en ajout uniquement par conception.

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## Propriétés de sécurité

- **Masqué par défaut** : toutes les réponses GET masquent les PII sauf si `X-Role: admin` est présent.
- **Accesseur forcé** : le démasquage admin nécessite `X-Accessor` ; 403 si absent — pas d'accès admin anonyme.
- **Audit immuable** : aucune route ne supprime ou met à jour les entrées d'audit.
- **Stockage paramétrisé** : les PII sont stockées via des instructions préparées — les tentatives d'injection SQL sont stockées comme des littéraux.
- **Précision de rôle** : seule la valeur exacte `admin` accorde le démasquage ; `ADMIN`, `superuser`, etc. sont traités comme des utilisateurs normaux.
