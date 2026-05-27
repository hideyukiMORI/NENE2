# How-to : Publication programmée d'articles

> **Référence FT** : FT330 (`NENE2-FT/pubschedulelog`) — Cycle de vie brouillon/programmé/publié/archivé d'articles, accès aux brouillons réservé au propriétaire, articles publiés publics, déclencheur de publication programmée, 34 tests / 95 assertions PASS.

Ce guide montre comment construire un système de gestion d'articles avec publication différée : les auteurs rédigent des brouillons, les programment pour une heure future, et un job en arrière-plan (ou un appel API) les fait passer à l'état publié.

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601, NULL sauf si programmé
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Transitions de statut

```
draft ──publier──► published ──archiver──► archived
  │
  └──programmer──► scheduled ──(temps passe)──► published
  │                   │
  │               déprogrammer
  │                   │
  └───────────────────┘
```

Uniquement les transitions autorisées — les transitions invalides retournent 409.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer un brouillon (`X-User-Id` requis) |
| `GET` | `/articles/{id}` | Obtenir (brouillon : propriétaire seulement ; publié : public) |
| `PUT` | `/articles/{id}` | Mettre à jour le brouillon (`X-User-Id` requis) |
| `POST` | `/articles/{id}/publish` | Publier immédiatement |
| `POST` | `/articles/{id}/schedule` | Programmer pour une heure future |
| `POST` | `/articles/{id}/unschedule` | Retourner à l'état brouillon |
| `POST` | `/articles/{id}/archive` | Archiver un article publié |
| `GET` | `/articles` | Lister (avec filtre `?status=`) |
| `POST` | `/publish-due` | Déclencher les articles programmés dont publish_at est dépassé |

## Créer un brouillon

```php
POST /articles  X-User-Id: 1
{"title": "Bonjour", "body": "Monde"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// Pas d'auth → 401
```

## Règles de visibilité

```php
// Brouillon : propriétaire seulement
GET /articles/1  X-User-Id: 1  → 200   // l'auteur voit son propre brouillon
GET /articles/1  X-User-Id: 2  → 404   // autre utilisateur ne peut pas voir le brouillon
GET /articles/1               → 404   // pas d'auth, brouillon caché

// Publié : tout le monde
GET /articles/1               → 200   // public
```

## Publier et archiver

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// Impossible d'archiver un brouillon
POST /articles/1/archive  X-User-Id: 1  → 409
```

## Programmer

```php
// Programmer pour dans 1 heure
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// Heure passée → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// Déprogrammer → retour à brouillon
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## Déclencher les articles programmés

Un cron job ou un endpoint admin fait passer tous les articles programmés avec `publish_at <= now` :

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## Lister les articles

```php
GET /articles?status=published      → 200  // public, pas d'auth nécessaire
GET /articles?status=draft  X-User-Id: 1  → 200  // seulement ses propres brouillons
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Montrer le brouillon à un utilisateur non authentifié | Fuite de contenu non publié |
| Permettre la programmation dans le passé | L'article se publierait "immédiatement" via le job déclencheur, contournant la révision |
| Utiliser now() de l'horloge murale dans les tests pour le déclencheur de programmation | Les tests deviennent dépendants du temps ; utiliser un force-insert avec un `publish_at` passé dans les tests |
| Suppression physique à l'archivage | Perdre la piste d'audit ; utiliser le champ status |
| Permettre la transition archived → published | Ramène du contenu supprimé ; exiger une re-publication explicite |
