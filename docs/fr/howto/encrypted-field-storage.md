# How-to : Stockage de champs chiffrés

> **Référence FT** : FT267 (`NENE2-FT/encryptlog`) — Chiffrement au niveau champ AES-256-GCM : chiffrement à l'écriture / déchiffrement à la lecture, index aveugle pour texte chiffré recherchable, séparation des clés entre clés de chiffrement et d'index
>
> **Évaluation VULN** : V-01 à V-10 inclus à la fin de ce document.
>
> **Pattern aussi prouvé par FT187 encryptlog** — Chiffrement par champ AES-256-GCM avec index aveugle HMAC-SHA256 pour le stockage de PII recherchable.

---

## Ce que couvre ce guide

Stocker des champs sensibles (nom, email, NSS, carte de crédit) chiffrés au repos tout en les rendant recherchables :

1. **AES-256-GCM** — chiffrement authentifié ; chaque enregistrement obtient son propre nonce
2. **Index aveugle** — HMAC-SHA256 de la valeur du champ active `WHERE email_idx = ?` sans déchiffrement
3. **Détection de falsification AEAD** — incompatibilité de tag cause `\RuntimeException`, pas 400
4. **Texte chiffré jamais dans les réponses API** — la couche VO / toArray() retourne toujours le texte clair
5. **Prévention IDOR** — toutes les lectures/écritures scopent `WHERE id AND user_id`

---

## Format du texte chiffré

```
base64( nonce ‖ texte_chiffré ‖ tag )
```

| Composant | Taille | Objectif |
|---|---|---|
| `nonce` | 12 octets | IV aléatoire par chiffrement (standard GCM) |
| `texte_chiffré` | variable | Texte clair chiffré AES-256-GCM |
| `tag` | 16 octets | Tag d'authentification — détecte la falsification |

Stocké comme une seule colonne `TEXT`. Même texte clair → texte chiffré différent à chaque fois (nonce différent).

---

## Schéma

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- index aveugle HMAC-SHA256 pour la recherche
    notes_enc  TEXT,               -- champ chiffré nullable
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` a un index — `WHERE email_idx = ?` est rapide. Le texte chiffré `email_enc` n'est jamais recherché.

---

## Helper FieldCrypto

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // doit être 32 octets
        private string $indexKey, // doit être 32 octets
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // IV frais par valeur
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * Déterministe — même entrée → toujours même sortie.
     * Permet WHERE email_idx = ? sans déchiffrer le texte chiffré stocké.
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## Pattern principal : L'écriture chiffre, la lecture déchiffre

```php
// CREATE — chiffrer tous les champs sensibles avant INSERT
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // déterministe pour la recherche
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// READ — déchiffrer de façon transparente dans l'hydration
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## Pattern principal : Recherche par index aveugle

```php
// RECHERCHE — calculer l'index aveugle depuis le paramètre de requête, ne jamais déchiffrer les lignes pendant la recherche
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // même clé → même index
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // les lignes sont ensuite déchiffrées dans hydrateRow()
}
```

**Quand l'email change lors d'une mise à jour, ré-indexer :**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← doit mettre à jour ensemble
]);
```

---

## Pattern principal : Texte chiffré jamais dans les réponses

```php
// VaultRecord::toArray() — retourne uniquement le texte clair déchiffré
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // texte clair
        'email'      => $this->email, // texte clair
        'notes'      => $this->notes, // texte clair ou null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc, email_enc, email_idx, notes_enc — jamais exposés
    ];
}
```

Un attaquant qui lit la réponse API ne peut pas récupérer le texte chiffré pour effectuer des attaques hors ligne.

---

## Pattern principal : La détection de falsification est un 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // Incompatibilité de tag = ligne DB falsifiée OU mauvaise clé
    // Lever — laisser le gestionnaire d'erreur global retourner 500
    // NE PAS retourner 400 — un 400 est une erreur client ; c'est une défaillance d'intégrité interne
    throw new \RuntimeException('Decryption failed.');
}
```

Retourner 400 impliquerait que le client a envoyé de mauvaises données. Un 500 signale correctement "problème d'intégrité côté serveur" et ne révèle pas quel champ a échoué ni pourquoi.

---

## Directives de gestion des clés

```php
// Production : dériver les clés d'un KMS ou gestionnaire de secrets
$encKey   = random_bytes(32); // 32 octets = AES-256
$indexKey = random_bytes(32); // clé séparée — domaine HMAC différent

// NE JAMAIS coder les clés en dur dans le source ; utiliser des env vars ou la dérivation de clé :
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 chars hex → 32 octets
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 chars hex → 32 octets
```

**Deux clés séparées :**
- `encKey` — AES-256-GCM. Rotatable : re-chiffrer les lignes avec la nouvelle clé, mettre à jour le préfixe de version.
- `indexKey` — HMAC-SHA256. Impossible de faire pivoter sans re-hacher tous les index.

---

## Résultats des tests (FT187)

```
51 tests / 110 assertions — tous PASS
PHPStan level 8 — pas d'erreurs
PHP CS Fixer — propre
```

| Zone de test | Couverture |
|---|---|
| Unité FieldCrypto | round-trip chiffrement/déchiffrement, unicité du nonce, déterminisme de l'index aveugle, détection de falsification, rejet de clé courte |
| Chemin heureux | create/get/list/update/delete/search |
| Isolation du texte chiffré | `name_enc`, `email_enc`, `email_idx`, `notes_enc` pas dans la réponse |
| Prévention IDOR | get/update/delete cross-user retournent tous 404 |
| Mass assignment | `name_enc`, `email_idx`, `user_id` du corps ignorés |
| Validation | name, email, notes, limite manquants/longs/type-erroné |
| Ré-indexation de l'index aveugle | la mise à jour d'email maintient l'index synchronisé |

---

## Évaluation VULN (FT267)

Évaluation de sécurité de `NENE2-FT/encryptlog` sous le modèle de menace de chiffrement au niveau champ.

### V-01 — Gestion des clés : chargement env ✅ BLOCKED

**Menace** : Clés de chiffrement committées dans VCS ou codées en dur dans le source.
**Mitigation** : Clés chargées via `getenv()` dans `ConfigLoader`, validées en longueur au démarrage. Le fichier `.env` est ignoré par git. Aucun matériel de clé n'apparaît dans le code source.
**Résiduel** : La rotation des clés (remplacer les deux clés, re-chiffrer toutes les lignes) n'est pas implémentée. Accepté pour le périmètre FT ; un système de production a besoin d'un plan de rotation.

---

### V-02 — Réutilisation de nonce (GCM) ✅ BLOCKED

**Menace** : Si le même nonce est jamais utilisé deux fois sous la même clé, GCM perd toutes ses garanties de confidentialité et d'authenticité.
**Mitigation** : `random_bytes(12)` est appelé dans `encrypt()` à chaque invocation. L'espace de nonce 96 bits et `random_bytes()` rendent la probabilité de collision négligeable pour tout volume d'utilisation réaliste (< 2^32 chiffrements par durée de vie de clé est la limite sûre).
**Résultat** : Sûr.

---

### V-03 — Vérification du tag d'authentification ✅ BLOCKED

**Menace** : La falsification de texte chiffré passe inaperçue ; l'attaquant retourne des bits pour manipuler le texte clair déchiffré.
**Mitigation** : `openssl_decrypt()` vérifie le tag d'authentification GCM de 16 octets avant de retourner le texte clair. Toute modification d'un seul bit retourne `false`, que `FieldCrypto::decrypt()` convertit en `\RuntimeException` lancée. L'application la capture et retourne `500` ; aucun texte clair partiel n'est exposé.
**Résultat** : Sûr.

---

### V-04 — La réponse API révèle le détail de l'erreur de déchiffrement ⚠️ EXPOSED

**Menace** : Le gestionnaire d'erreurs sérialise `\RuntimeException::getMessage()` ("Decryption failed — tag mismatch or corrupt ciphertext.") dans la réponse API, révélant un signal d'intégrité aux attaquants.
**Résultat** : En mode `APP_DEBUG=true`, le message complet et la trace de pile peuvent apparaître. En mode `APP_DEBUG=false`, le gestionnaire par défaut peut encore exposer le nom de la classe d'exception.
**Recommandation** : Ajouter un `DecryptionFailedExceptionHandler` dédié qui mappe à `500` avec un corps Problem Details générique `"internal-error"` quel que soit le mode debug. L'échec de vérification de tag doit être journalisé uniquement côté serveur.

---

### V-05 — Collision d'index aveugle / Dictionnaire hors ligne ✅ BLOCKED

**Menace** : L'attaquant construit un dictionnaire de valeurs `blindIndex(candidat)` hors ligne et compare contre la colonne `email_idx`.
**Mitigation** : HMAC-SHA256 avec une clé secrète de 256 bits. Sans `VAULT_INDEX_KEY`, précalculer toute valeur d'index est infaisable computationnellement. L'index aveugle supporte uniquement la correspondance exacte (`WHERE email_idx = ?`) ; la recherche par joker ou sous-chaîne n'est pas possible.
**Résiduel** : Si `VAULT_INDEX_KEY` est compromis, tous les index aveugles d'email deviennent force-brutables pour une liste d'emails connus finie. La confidentialité des clés est essentielle.

---

### V-06 — Pas d'authentification / Autorisation sur les endpoints ⚠️ EXPOSED

**Menace** : N'importe quel appelant non authentifié peut créer, lire, mettre à jour et supprimer des enregistrements vault pour des valeurs `user_id` arbitraires.
**Résultat** : Le FT expose `/vault/{userId}/records` sans clé API, JWT, ou vérification de session. Le paramètre de chemin `user_id` est fourni par l'appelant.
**Recommandation** : Nécessiter une authentification (clé API ou JWT) et dériver `$userId` du token vérifié — ne jamais faire confiance à un `user_id` fourni par l'appelant. Ajouter `requireScope()` ou un middleware d'auth équivalent.
**Note FT** : Contrainte de périmètre délibérée pour le FT. L'utilisation en production nécessite l'auth.

---

### V-07 — IDOR sur Update / Delete ✅ BLOCKED

**Menace** : Un utilisateur authentifié-mais-erroné modifie l'enregistrement chiffré d'un autre utilisateur.
**Mitigation** : Toutes les requêtes d'écriture incluent `AND user_id = :user_id`. Si l'enregistrement appartient à un utilisateur différent, `rowCount()` retourne 0 et le contrôleur retourne 404. L'attaquant apprend seulement que l'enregistrement n'existe pas (pour lui).
**Résultat** : Sûr, en supposant que l'authentification est présente (voir V-06).

---

### V-08 — Écart de rotation de clé / Re-chiffrement ⚠️ EXPOSED

**Menace** : Quand `VAULT_ENC_KEY` est pivoté, l'ancien texte chiffré sous la clé précédente ne peut pas être déchiffré. Il n'y a pas de stratégie de migration de re-chiffrement.
**Résultat** : Pas de versionnement de clé, pas d'utilitaire de re-chiffrement, et aucune migration documentée.
**Recommandation** : Préfixer chaque blob chiffré avec un octet de version de clé (ex. `v1:<base64>`). Au déchiffrement, lire la version, sélectionner la clé. Fournir un script de migration qui déchiffre sous l'ancienne clé et re-chiffre sous la nouvelle clé dans une transaction.

---

### V-09 — Comparaison temporelle d'index aveugle ✅ BLOCKED

**Menace** : Comparer `email_idx` depuis une source non fiable avec `===` révèle des informations temporelles caractère par caractère.
**Mitigation** : `findByEmail()` passe l'index aveugle calculé comme paramètre SQL. La comparaison se produit à l'intérieur de la recherche d'index B-tree de SQLite, qui n'est pas un oracle de timing du côté PHP. Aucune comparaison de chaîne PHP des valeurs d'index aveugle n'a lieu.
**Résultat** : Sûr.

---

### V-10 — Données déchiffrées en mémoire / Journaux ⚠️ EXPOSED

**Menace** : Le texte clair déchiffré (nom, email, notes) apparaît dans : les traces d'exception PHP, le middleware de journalisation de requêtes (si le corps est journalisé), la sortie d'erreur, les spans APM.
**Résultat** : Le middleware de journalisation de corps de requête journalise le corps POST avant que le chiffrement se produise — les champs en texte clair sont dans le journal. Si `VaultRecord` est inclus dans un contexte d'exception, les champs déchiffrés apparaissent dans la trace de pile.
**Recommandation** :
1. Exclure les payloads vault en texte clair de la journalisation du corps de requête (masquer ou ignorer les routes `/vault`).
2. Implémenter `__debugInfo()` sur `VaultRecord` pour expurger les champs sensibles de var_dump / sérialisation d'exception.
3. S'assurer que les intégrations de suivi d'erreurs (Sentry, etc.) expurgent les champs en texte clair avant transmission.

---

### Résumé VULN

| ID | Menace | Statut |
|----|--------|--------|
| V-01 | Clé committée dans VCS | ✅ BLOCKED |
| V-02 | Réutilisation de nonce (GCM) | ✅ BLOCKED |
| V-03 | Texte chiffré falsifié accepté | ✅ BLOCKED |
| V-04 | Détail d'erreur de déchiffrement dans la réponse | ⚠️ EXPOSED |
| V-05 | Dictionnaire hors ligne d'index aveugle | ✅ BLOCKED |
| V-06 | Pas d'authentification sur les endpoints | ⚠️ EXPOSED |
| V-07 | IDOR sur update/delete | ✅ BLOCKED |
| V-08 | Écart rotation de clé / re-chiffrement | ⚠️ EXPOSED |
| V-09 | Comparaison temporelle d'index aveugle | ✅ BLOCKED |
| V-10 | Données déchiffrées dans journaux/exceptions | ⚠️ EXPOSED |

**Score** : 6 BLOCKED, 4 EXPOSED.

Les quatre expositions concernent la stratégie de rotation des clés (V-08), l'authentification (V-06, périmètre FT délibéré), la fuite de détails d'erreur (V-04), et l'hygiène des journaux (V-10). Aucune ne représente un défaut dans la conception cryptographique AES-256-GCM ou d'index aveugle — ce sont des lacunes opérationnelles et d'intégration qui doivent être corrigées avant l'utilisation en production.
