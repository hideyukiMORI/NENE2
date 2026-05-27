# How-to : Pagination par offset et curseur

> **Référence FT** : FT325 (`NENE2-FT/pagelog`) — Stratégie de pagination double (basée sur offset et curseur) avec `next_offset`/`next_cursor`, `has_more`, filtre de catégorie, 15 tests / 47 assertions PASS.

Ce guide montre comment implémenter des endpoints de pagination à la fois par offset et par curseur pour la même ressource, permettant aux clients de choisir la stratégie qui convient à leur cas d'utilisation.

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer un article |
| `GET` | `/articles/offset` | Pagination par offset |
| `GET` | `/articles/cursor` | Pagination par curseur |
| `GET` | `/articles/by-category` | Filtre de catégorie |

## Pagination par offset

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 articles
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // null sur la dernière page
}

// Page 2
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// Dernière page
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// Au-delà de la fin
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

`next_offset = offset + limit` quand `has_more`, sinon `null`.

## Pagination par curseur

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // les plus récents en premier
  "has_more": true,
  "next_cursor": 15      // id du dernier article retourné
}

// Page suivante en utilisant le curseur
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// Dernière page
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

Le curseur est l'`id` du dernier article retourné : `WHERE id < $after ORDER BY id DESC LIMIT $limit + 1` (lire un de plus pour déterminer `has_more`).

## Filtre de catégorie

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## Offset vs Curseur — Quand utiliser

| Critère | Offset | Curseur |
|---------|--------|---------|
| Saut aléatoire de page | ✅ `?offset=50` | ❌ Doit traverser |
| Total nécessaire | ✅ Toujours inclus | ❌ Coûteux |
| Résultats cohérents lors d'insertions | ❌ Nouvelle ligne décale la page | ✅ Stable |
| Performance sur grands datasets | ❌ `OFFSET N` scanne N lignes | ✅ `WHERE id < X` utilise l'index |
| Défilement infini / flux | ❌ | ✅ |

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner `next_offset` même sur la dernière page | Le client fait une requête vide supplémentaire |
| Utiliser `OFFSET N` sur des tables avec des millions de lignes | La DB scanne N lignes avant de retourner les résultats ; utiliser le curseur pour les grandes données |
| Omettre `has_more` de la réponse curseur | Le client ne peut pas savoir s'il faut récupérer la page suivante |
| Utiliser un horodatage comme curseur | Les horodatages dupliqués causent des lignes ignorées ou répétées ; utiliser un ID entier unique |
