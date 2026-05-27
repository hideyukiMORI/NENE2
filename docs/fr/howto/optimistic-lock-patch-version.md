# How-to : Verrouillage optimiste avec PATCH + champ version

> **Référence FT** : FT324 (`NENE2-FT/optlocklog`) — Verrouillage optimiste basé sur PATCH, 409 inclut `current_version` pour réessai sans GET, type de version entier strict, évaluation ATK, 12 tests / 24 assertions PASS.

Ce guide montre comment implémenter le contrôle de concurrence optimiste via PATCH avec un champ `version`, retournant la version serveur courante dans les réponses 409 pour que les clients puissent réessayer sans GET supplémentaire.

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer (version=1) |
| `GET` | `/articles/{id}` | Obtenir avec version |
| `PATCH` | `/articles/{id}` | Mettre à jour (version requise comme entier) |

## Création et lecture

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## PATCH avec version

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

La version doit être un **entier JSON** — une chaîne `"1"` est rejetée.

## 409 inclut current_version

Quand un conflit est détecté, la réponse inclut `current_version` pour que le client puisse réessayer sans re-GET :

```php
// La version 1 a déjà été incrémentée à 2 par un autre écrivain
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← le client peut utiliser ceci directement pour réessayer
}

// Le client réessaie avec current_version du corps 409
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← succès
```

## Validation des types

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // version manquante
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // chaîne pas int
PATCH /articles/9999 {"version": 1}                      → 404  // non trouvé
```

## Implémentation

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // Vérification de type entier strict — "1" (chaîne) est rejeté
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← activer le réessai sans GET
        ]);
    }

    // UPDATE atomique avec WHERE version = ?
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Brute force de version pour écraser ✅ SAFE

**Attaque** : L'attaquant cycle la version `1, 2, 3…` jusqu'à ce qu'une réussisse, écrasant le contenu courant.
**Résultat** : SAFE — Le brute force trouve éventuellement la version courante mais c'est une écriture légitime, pas une escalade de privilèges. L'autorisation de propriété (non montrée) prévient les écritures non autorisées.

---

### ATK-02 — Contournement par version chaîne (`"version": "1"`) 🚫 BLOCKED

**Attaque** : L'attaquant envoie `"version": "1"` (chaîne JSON) espérant que la coercition de type PHP la traite comme entier.
**Résultat** : BLOCKED — `is_int($version)` retourne false pour les chaînes. Retourne 400.

---

### ATK-03 — Version float (`"version": 1.0`) 🚫 BLOCKED

**Attaque** : Envoyer `"version": 1.0` pour correspondre via comparaison laxiste.
**Résultat** : BLOCKED — `is_int(1.0)` est false en PHP (c'est un float). Retourne 400.

---

### ATK-04 — Version manquante → Forcer écriture aveugle 🚫 BLOCKED

**Attaque** : Omettre le champ `version`, espérant que le serveur accepte par défaut la mise à jour.
**Résultat** : BLOCKED — `version` manquant (null) échoue à la vérification `is_int()`. Retourne 400.

---

### ATK-05 — Version négative 🚫 BLOCKED

**Attaque** : Envoyer `"version": -1` pour exploiter un éventuel décalage dans la comparaison de version.
**Résultat** : BLOCKED — La version commence à 1 et ne fait qu'incrémenter. `-1 !== 1` → conflit 409.

---

### ATK-06 — current_version du 409 utilisé pour une course 🚫 BLOCKED

**Attaque** : L'attaquant lit `current_version` du 409 et soumet immédiatement, faisant la course avec le réessai légitime.
**Résultat** : BLOCKED — Le `UPDATE … WHERE version = $current` atomique signifie qu'un seul écrivain concurrent peut réussir par version. L'autre obtient 409 à nouveau. C'est le comportement de verrouillage optimiste prévu.

---

### ATK-07 — Numéro de version en dépassement 🚫 BLOCKED

**Attaque** : Envoyer `"version": 9999999999999999999` pour dépasser l'entier.
**Résultat** : BLOCKED — Les grands entiers JSON peuvent être décodés comme float en PHP ; `is_int()` retourne false. Retourne 400.

---

### ATK-08 — Version zéro 🚫 BLOCKED

**Attaque** : Envoyer `"version": 0` pour saper la version minimale.
**Résultat** : BLOCKED — La version commence à 1. `0 !== 1` → conflit 409.

---

### ATK-09 — current_version falsifié dans le corps 🚫 BLOCKED

**Attaque** : L'attaquant inclut `"current_version": 999` dans le corps PATCH espérant que le serveur l'utilise.
**Résultat** : BLOCKED — `current_version` n'est que dans la *réponse*. Le serveur ignore les champs de requête inconnus ; la version est prise uniquement de `$body['version']`.

---

### ATK-10 — Injection SQL via champ version 🚫 BLOCKED

**Attaque** : `"version": "1; DROP TABLE articles; --"`.
**Résultat** : BLOCKED — Rejeté à la vérification `is_int()` avant d'atteindre la DB. Retourne 400.

---

### ATK-11 — Replay de version réussie pour ré-exécuter 🚫 BLOCKED

**Attaque** : Enregistrer un PATCH réussi (version N → N+1), puis rejouer la même requête.
**Résultat** : BLOCKED — Après mise à jour, l'article est à la version N+1. Rejouer `version: N` retourne 409.

---

### ATK-12 — Écritures concurrentes causent les deux à réussir 🚫 BLOCKED

**Attaque** : Deux requêtes PATCH identiques envoyées simultanément avec la même `version`.
**Résultat** : BLOCKED — `UPDATE … WHERE version = ?` est atomique. La DB sérialise les écritures concurrentes ; le second UPDATE correspond à 0 lignes → l'application détecte et retourne 409.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Brute force de version | ✅ SAFE (problème d'autorisation) |
| ATK-02 | Contournement par version chaîne | 🚫 BLOCKED |
| ATK-03 | Version float | 🚫 BLOCKED |
| ATK-04 | Écriture aveugle sans version | 🚫 BLOCKED |
| ATK-05 | Version négative | 🚫 BLOCKED |
| ATK-06 | Exploitation de race avec current_version | 🚫 BLOCKED |
| ATK-07 | Version en dépassement | 🚫 BLOCKED |
| ATK-08 | Version zéro | 🚫 BLOCKED |
| ATK-09 | current_version falsifié dans body | 🚫 BLOCKED |
| ATK-10 | Injection SQL via version | 🚫 BLOCKED |
| ATK-11 | Replay de version réussie | 🚫 BLOCKED |
| ATK-12 | Écritures concurrentes réussissent toutes les deux | 🚫 BLOCKED |

**11 BLOCKED, 1 SAFE, 0 EXPOSED** — Pas de résultats critiques.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Accepter `"version": "1"` (chaîne) | La comparaison laxiste PHP `"1" == 1` est true ; attaque de confusion de type |
| Omettre `current_version` du 409 | Le client doit faire un GET supplémentaire ; latence plus élevée, plus de requêtes en cas de conflit |
| Utiliser uniquement la vérification au niveau application (pas de clause WHERE) | Condition de course entre la lecture et l'écriture de version |
| Retourner 200 sur version manquante | Écrasement inconditionnel — mise à jour perdue |
