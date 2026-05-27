# How-to : API de drapeaux de fonctionnalités

> **Référence FT** : FT270 (`NENE2-FT/featureflaglog`) — API de drapeaux de fonctionnalités : évaluation en chaîne de priorité (cible utilisateur → cible tenant → globally_enabled → hash rollout_pct), attribution déterministe de bucket par crc32, kill switches utilisateur/tenant, contrainte UNIQUE sur le nom du drapeau, 21 tests / 31 assertions PASS.

Les drapeaux de fonctionnalités permettent de basculer des fonctionnalités en production sans déployer du code. Les décisions clés sont : où stocker l'état (DB vs config), comment évaluer la priorité quand plusieurs règles s'appliquent, et comment gérer les pourcentages de déploiement sans suivi par utilisateur.

---

## Routes

| Méthode    | Chemin                                  | Description                                         |
|------------|-----------------------------------------|-----------------------------------------------------|
| `POST`     | `/flags`                                | Créer un nouveau drapeau de fonctionnalité          |
| `GET`      | `/flags/{name}`                         | Obtenir les détails du drapeau avec les cibles      |
| `POST`     | `/flags/{name}/toggle`                  | Activer/désactiver globally_enabled                 |
| `PUT`      | `/flags/{name}/rollout`                 | Définir le pourcentage de déploiement (0–100)       |
| `PUT`      | `/flags/{name}/targets`                 | Upsert un remplacement de cible utilisateur ou tenant |
| `DELETE`   | `/flags/{name}/targets/{type}/{id}`     | Supprimer un remplacement de cible spécifique       |
| `POST`     | `/flags/{name}/evaluate`                | Évaluer le drapeau pour un utilisateur/tenant       |

---

## Composants principaux

- **Registre de drapeaux** : une ligne par drapeau avec un nom, un interrupteur global on/off, et un pourcentage de déploiement.
- **Cibles de drapeaux** : remplacements par utilisateur ou tenant qui gagnent sur l'état global.
- **Évaluateur** : applique la chaîne de priorité et retourne un booléen pour un utilisateur donné.

## Schéma

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## Priorité d'évaluation

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. La cible explicite au niveau utilisateur gagne en premier
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. Cible au niveau tenant
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. Interrupteur global
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. Pourcentage de déploiement : bucket déterministe par hash crc32
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. Désactivé par défaut
        return false;
    }
}
```

Ordre de priorité (le plus élevé gagne) :
1. Cible au niveau utilisateur (`target_type = 'user'`)
2. Cible au niveau tenant (`target_type = 'tenant'`)
3. `globally_enabled = 1`
4. `rollout_pct > 0` avec bucket par hash
5. `false`

## Pourcentage de déploiement — bucket déterministe

`crc32($userId . '.' . $flagName) % 100` produit un bucket stable par paire (utilisateur, drapeau). Le même utilisateur atterrit toujours dans le même bucket, donc son expérience est cohérente entre les requêtes. Ajouter le nom du drapeau évite que tous les drapeaux se déploient vers les mêmes utilisateurs à `pct = 10`.

Important : `crc32()` peut retourner des valeurs négatives sur les systèmes 64 bits — utiliser `abs()`.

## Cibles comme remplacements

Une cible avec `enabled = false` est un kill switch : elle désactive le drapeau pour cet utilisateur ou tenant même quand `globally_enabled = 1`. C'est la façon canonique d'exclure un utilisateur spécifique d'un déploiement.

```php
// Kill switch au niveau utilisateur (remplace l'activation globale)
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// Accès anticipé au niveau tenant (remplace la désactivation globale)
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## Pattern upsert pour les cibles

Les cibles utilisent la sémantique `INSERT OR REPLACE` / upsert — appeler le même endpoint deux fois avec des valeurs `enabled` différentes met à jour la ligne existante plutôt que de créer un doublon :

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

La contrainte UNIQUE sur `(flag_id, target_type, target_id)` garantit qu'il y a au plus un remplacement par paire (drapeau, cible).

## Réponse de conflit pour les noms de drapeaux dupliqués

`feature_flags.name` a une contrainte UNIQUE. En cas de création dupliquée, la DB lève une `RuntimeException`. La capturer et retourner 409 Conflict plutôt que 500 :

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // l'appelant mappe null → 409
}
```

## Décisions de conception

**Pourquoi DB plutôt que fichier de config ?**
Les fichiers de config nécessitent un déploiement pour changer un drapeau. Les drapeaux DB peuvent être basculés en direct sans toucher au code ni redémarrer les processus.

**Pourquoi un hash déterministe pour le déploiement plutôt qu'aléatoire ?**
La sélection aléatoire fait que le même utilisateur bascule entre activé/désactivé entre les requêtes. Un hash stable donne à chaque utilisateur une expérience cohérente pour la durée de vie du drapeau.

**Pourquoi autoriser les cibles `enabled = false` ?**
Un système de drapeaux sans kill switches est incomplet. `enabled = false` est la façon la plus sûre d'exclure un utilisateur d'un déploiement déjà globalement activé — sans changement de code, sans déploiement.

**Pourquoi séparer `globally_enabled` et `rollout_pct` ?**
`globally_enabled = 1` est un interrupteur explicite tout-ou-rien. `rollout_pct` est pour l'exposition progressive. Les garder séparés évite de surcharger un seul champ avec deux significations différentes.

---

## Exemples de réponses

**POST /flags** (201 Created) :
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}** (200 OK) :
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate** (200 OK) :
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Utiliser un nombre aléatoire pour le déploiement par requête | Le même utilisateur bascule entre activé/désactivé entre requêtes — UX incohérente |
| Oublier `abs()` sur `crc32()` | crc32 peut retourner des valeurs négatives en PHP 64 bits — le modulo donne un mauvais bucket |
| Autoriser des valeurs `target_type` arbitraires | Un enum non contrôlé rend la logique d'évaluation non bornée ; restreindre à `'user'` et `'tenant'` |
| Pas de `UNIQUE (flag_id, target_type, target_id)` | Les cibles dupliquées rendent l'évaluation ambiguë — la première ligne gagne arbitrairement |
| Utiliser le nom du drapeau comme `target_id` | Le nom du drapeau peut changer ; utiliser des IDs stables pour le ciblage utilisateur/tenant |
| Retourner 500 sur un nom de drapeau dupliqué | La violation d'unicité du nom est une erreur de domaine, pas une erreur serveur ; mapper vers 409 Conflict |
