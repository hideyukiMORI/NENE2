# Comment construire la gestion des annonces système

> **Pattern prouvé par FT190 announcelog** — Annonces système basées sur le temps avec authentification par clé admin, refus par utilisateur, et ordonnancement par priorité. 38 tests / 93 assertions PASS.

---

## Ce que ce guide couvre

Une API d'annonces système pour diffuser des avis de maintenance, mises à jour de fonctionnalités, et alertes :

1. **Créer/Mettre à jour/Supprimer** — opérations réservées à l'admin via comparaison de clé en temps constant
2. **Lister les actives** — filtré par `starts_at` / `ends_at` en UTC
3. **Rejeter** — opt-out par utilisateur persisté comme UPSERT idempotent

---

## Schéma

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- plus élevé = affiché en premier
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` permet le rejet idempotent. `starts_at` / `ends_at` sont des chaînes ISO 8601 — la comparaison lexicographique fonctionne correctement pour les datetimes UTC.

---

## API

| Méthode | Chemin | Auth | Description |
|---|---|---|---|
| `POST` | `/announcements` | `X-Admin-Key` | Créer une annonce (201) |
| `PUT` | `/announcements/{id}` | `X-Admin-Key` | Mettre à jour une annonce (200) |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | Supprimer une annonce (200) |
| `GET` | `/announcements` | `X-User-Id` optionnel | Lister les annonces actuellement actives |
| `POST` | `/announcements/{id}/dismiss` | `X-User-Id` | Rejeter pour cet utilisateur (200) |

---

## Pattern principal : Vérification de clé admin en temps constant

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // Une adminKey vide signifie pas d'accès admin — fail closed
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals : temps constant — prévient les attaques par timing sur la comparaison de clé
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**Pourquoi pas `===` :** La comparaison de chaîne court-circuite au premier non-concordance. Un attaquant peut mesurer les différences de timing pour trouver des correspondances de préfixe partiel, puis faire du brute-force caractère par caractère. `hash_equals()` prend un temps constant quelle que soit la position de la non-concordance.

**Fail-closed :** Une configuration `adminKey` vide retourne toujours `false` — il n'y a pas de mode "admin ouvert" accidentel.

---

## Pattern principal : Filtrage basé sur l'heure UTC

```php
// Lister les annonces actives en ce moment
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

Les chaînes ISO 8601 en UTC se trient lexicographiquement de manière correcte — `'2025-06-01T...' > '2025-05-01T...'`. Toujours utiliser UTC dans la base de données.

`ends_at > :now` (strictement supérieur à) signifie qu'une annonce expire précisément à `ends_at`, pas une seconde après.

---

## Pattern principal : Rejet par utilisateur (idempotent)

```php
// UNIQUE(user_id, announcement_id) permet les appels de rejet répétés en sécurité
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

Un utilisateur appelant `POST /announcements/5/dismiss` deux fois est sûr — le second appel réussit silencieusement. Le client n'a jamais besoin de vérifier d'abord.

---

## Pattern principal : Contexte utilisateur optionnel sur la liste

```php
// Sans X-User-Id : afficher toutes les annonces actives
// Avec X-User-Id : exclure les rejetées pour cet utilisateur

// Sans utilisateur :
WHERE a.starts_at <= :now AND a.ends_at > :now

// Avec utilisateur (LEFT JOIN + filtre IS NULL) :
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

Ce seul endpoint `GET /announcements` gère à la fois les cas d'utilisation non authentifiés (surveillance, vue admin) et authentifiés (UI affichant les bannières pertinentes).

---

## Pattern principal : ends_at doit être après starts_at

```php
// Validation côté serveur — pas seulement la confiance client
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at must be after starts_at.';
}
```

Une annonce avec `ends_at <= starts_at` est invisible immédiatement à la création — valider et rejeter plutôt qu'accepter silencieusement des données brisées.

---

## Conception des réponses

| Scénario | Statut | Body |
|---|---|---|
| Création réussie | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| Mise à jour réussie | 200 | `{announcement: {...}}` |
| Suppression réussie | 200 | `{deleted: true}` |
| Lister les actives | 200 | `{data: [...], total: N}` |
| Rejeter | 200 | `{dismissed: true}` |
| Clé admin manquante/incorrecte | 401 | `{error: "Admin key required."}` |
| Non trouvé | 404 | `{error: "Announcement not found."}` |
| Validation échouée | 422 | `{error: "..."}` |

`created_at` / `updated_at` ne sont **pas** dans la réponse publique — ce sont des métadonnées internes.

---

## Résultats des tests (FT190)

```
38 tests / 93 assertions — tous PASS
PHPStan level 8 — aucune erreur
PHP CS Fixer — propre
```

Source : [`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
