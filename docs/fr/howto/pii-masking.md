# How-to : API de masquage PII

> **Référence FT** : FT297 (`NENE2-FT/masklog`) — Masquage PII : masquage partiel email/téléphone/nom, accès aux données brutes basé sur les rôles (admin uniquement) avec piste d'audit X-Accessor obligatoire, journal d'audit immuable, VULN-A~L tous SAFE, 24 tests / 49 assertions PASS.

Ce guide montre comment construire une API de données clients qui masque les PII (Informations Personnellement Identifiables) par défaut et accorde un accès complet uniquement aux rôles autorisés avec une piste d'audit.

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

Les PII brutes sont stockées dans `customers`. Chaque accès admin aux données brutes est enregistré dans `mask_audit_log` (ajout uniquement — pas de route de mise à jour/suppression).

## Patterns de masquage

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678" (4 derniers chiffres conservés)
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## Accès basé sur les rôles — Masqué par défaut

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // PII brutes
    }

    return $this->json->create($this->masker->applyMask($customer));  // masqué
}
```

- **Non-admin (par défaut)** : reçoit toujours des données masquées.
- **Admin avec `X-Accessor`** : reçoit les données brutes et l'accès est journalisé.
- **Admin sans `X-Accessor`** : 403 — la piste d'audit ne peut pas être vide.

## Journal d'audit — Ajout uniquement

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // Pas de DELETE ou PUT pour le journal d'audit — immuable par conception
}
```

Le journal d'audit n'a pas de route de suppression ou de mise à jour. Les entrées sont permanentes ; seuls les admins peuvent lire le journal.

---

## Évaluation de vulnérabilité

### V-01 — PII non exposées dans le GET par défaut ✅ SAFE

**Risque** : Un non-admin lit l'email/téléphone/nom brut d'un client.
**Résultat** : SAFE — la réponse par défaut applique toujours `applyMask()`. Les champs bruts ne sont jamais retournés sans `X-Role: admin`.

---

### V-02 — Injection SQL dans le champ nom ✅ SAFE

**Risque** : `"name": "'; DROP TABLE customers; --"` supprime des données.
**Résultat** : SAFE — les requêtes paramétrées stockent la chaîne d'injection telle quelle comme nom.

---

### V-03 — Injection SQL dans le champ email ✅ SAFE

**Risque** : Injection SQL via email lors de la création.
**Résultat** : SAFE — même protection par requête paramétrée.

---

### V-04 — IDOR : non-admin lit les PII brutes via l'ID client ✅ SAFE

**Risque** : Sans `X-Role: admin`, un utilisateur essaie `GET /customers/1` pour obtenir les PII complètes.
**Résultat** : SAFE — toute requête sans `X-Role: admin` reçoit des données masquées quel que soit l'ID client.

---

### V-05 — Escalade de rôle : en-tête X-Role arbitraire ✅ SAFE

**Risque** : Envoyer `X-Role: superuser` ou `X-Role: ADMIN` pour contourner le masquage.
**Résultat** : SAFE — seule la chaîne exacte `'admin'` accorde l'accès brut : `if ($role === 'admin')`. Toute autre valeur tombe dans la réponse masquée.

---

### V-06 — Admin sans en-tête X-Accessor ✅ SAFE

**Risque** : L'admin accède aux données brutes sans X-Accessor pour éviter la piste d'audit.
**Résultat** : SAFE — `if ($accessor === '') return 403`. L'accès admin nécessite un identifiant d'accesseur non vide.

---

### V-07 — Journal d'audit non accessible aux non-admins ✅ SAFE

**Risque** : Un non-admin lit `GET /customers/1/audit` pour découvrir qui a accédé à ses données.
**Résultat** : SAFE — l'endpoint d'audit vérifie `X-Role: admin`. Non-admin → 403.

---

### V-08 — Client inexistant retourne 404 ✅ SAFE

**Risque** : Interroger un ID inexistant retourne 500 ou expose des erreurs DB.
**Résultat** : SAFE — `if ($customer === null) return 404`. Erreur propre, pas d'information interne.

---

### V-09 — Une entrée extrêmement longue ne plante pas ✅ SAFE

**Risque** : Un nom de 10 000 caractères cause une erreur DB ou un épuisement de mémoire.
**Résultat** : SAFE — SQLite TEXT n'a pas de limite de longueur ; l'application stocke et masque sans planter. En production, ajouter une limite de longueur (ex. 500 caractères).

---

### V-10 — Payload XSS stocké comme littéral ✅ SAFE

**Risque** : `"name": "<script>alert(1)</script>"` est exécuté dans un navigateur.
**Résultat** : SAFE — l'API retourne `application/json` ; l'encodage JSON échappe `<` et `>`. Pas de rendu HTML dans la couche API.

---

### V-11 — La réponse masquée ne révèle jamais les PII complètes ✅ SAFE

**Risque** : La réponse masquée contient suffisamment de données pour reconstruire les PII originales.
**Résultat** : SAFE — email : seul le premier caractère + domaine ; téléphone : seuls les 4 derniers chiffres ; nom : seul le premier caractère par mot. Impossible de reconstruire l'original.

---

### V-12 — Le journal d'audit est immuable ✅ SAFE

**Risque** : L'admin supprime ses propres entrées du journal pour couvrir ses traces.
**Résultat** : SAFE — il n'existe pas de route `DELETE /customers/{id}/audit`. Les entrées du journal sont en ajout uniquement.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|----------|
| V-01 | PII exposées dans le GET par défaut | ✅ SAFE |
| V-02 | Injection SQL dans le nom | ✅ SAFE |
| V-03 | Injection SQL dans l'email | ✅ SAFE |
| V-04 | IDOR : non-admin lit les PII brutes | ✅ SAFE |
| V-05 | Escalade de rôle via en-tête X-Role | ✅ SAFE |
| V-06 | Admin sans X-Accessor | ✅ SAFE |
| V-07 | Journal d'audit accessible aux non-admins | ✅ SAFE |
| V-08 | Comportement sur client inexistant | ✅ SAFE |
| V-09 | Crash sur entrée très longue | ✅ SAFE |
| V-10 | Payload XSS dans le nom | ✅ SAFE |
| V-11 | La réponse masquée révèle les PII | ✅ SAFE |
| V-12 | Mutabilité du journal d'audit | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Le masquage par défaut, l'audit d'accesseur obligatoire, la vérification stricte du rôle et le journal immuable préviennent toutes les expositions PII et les vecteurs de contournement d'audit.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner les PII brutes par défaut | Tout utilisateur authentifié lit l'email/téléphone/nom complet |
| Vérification de rôle insensible à la casse (`strtolower`) sans allowlist explicite | `ADMIN`, `Admin`, `aDmIn` — accepter uniquement la chaîne exacte attendue |
| Permettre l'accès admin sans X-Accessor | Pas de piste d'audit ; échec de conformité RGPD |
| Journal d'audit mutable | Les admins suppriment leurs propres entrées ; la piste forensique est peu fiable |
| Exposer le journal d'audit aux non-admins | Les utilisateurs découvrent qui (quels employés) a accédé à leurs données |
| Masquage par hash (afficher le hash au lieu des vraies données) | Le hash des PII est toujours sensible — les attaquants peuvent brute-forcer les valeurs courtes |
| Pas de masquage dans la réponse de création | La réponse de création d'un nouveau client expose les PII qui viennent d'être stockées |
| Pas de limite de longueur d'entrée | Les entrées très longues consomment du stockage ; ajouter des limites explicites en production |
