# How-to : Suppression douce, restauration et suppression permanente

> **Référence FT** : `NENE2-FT/softdelete` — Suppression douce via timestamp `deleted_at`, restauration (seules les notes soft-supprimées peuvent être restaurées), suppression permanente (seules les notes soft-supprimées peuvent être définitivement supprimées), 14 tests PASS.

Ce guide montre comment implémenter trois états de suppression : actif, soft-supprimé (récupérable) et définitivement supprimé (disparu). Comparer avec `docs/howto/soft-delete-trash-restore.md` (FT340 softdeletelog) qui ajoute une vue corbeille dédiée et une purge en masse.

## Schéma

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = actif ; timestamp = soft-supprimé
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → actif. `deleted_at IS NOT NULL` → soft-supprimé.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/notes` | Créer une note |
| `GET` | `/notes` | Lister les notes actives uniquement |
| `GET` | `/notes/{id}` | Obtenir une note (404 si supprimée) |
| `DELETE` | `/notes/{id}` | Suppression douce (définit deleted_at) |
| `POST` | `/notes/{id}/restore` | Restaurer une note soft-supprimée |
| `DELETE` | `/notes/{id}/permanent` | Supprimer définitivement une note soft-supprimée |

## Créer une note

```php
POST /notes  {"title": "My Note", "body": "Some content"}

→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Some content",
  "deleted_at": null,    // ← null = actif
  "created_at": "..."
}
```

## Lister les notes actives

```php
GET /notes
→ 200  {"items": [{...notes actives...}], "total": 2}
```

Retourne uniquement les notes avec `deleted_at IS NULL`. Les notes soft-supprimées sont invisibles ici.

## Suppression douce

```php
DELETE /notes/1
→ 200  // définit deleted_at = maintenant

// La note soft-supprimée disparaît de la liste active
GET /notes
→ 200  {"items": [], "total": 0}

// Et du GET direct
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## Restaurer

```php
// Restaurer une note soft-supprimée
POST /notes/1/restore
→ 200  {"id": 1, "title": "My Note", "deleted_at": null, ...}  // retour à l'état actif

// La note restaurée réapparaît dans la liste active
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### Restauration d'une note active → 404

```php
// Tenter de restaurer une note active (jamais supprimée) → 404
POST /notes/2/restore   // la note 2 n'a jamais été supprimée
→ 404
```

Seules les notes soft-supprimées peuvent être restaurées. Les notes actives retournent 404 lors de la restauration.

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- Si 0 lignes affectées → note active ou n'existe pas → 404
```

## Suppression permanente

```php
// Doit être soft-supprimée en premier
DELETE /notes/1   // suppression douce
POST /notes/1/restore  // restauration (optionnel)

// Supprimer définitivement une note soft-supprimée
DELETE /notes/1          // la soft-supprimer d'abord
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // disparu pour toujours
```

### Suppression permanente d'une note active → 404

```php
// Supprimer définitivement une note active → 404
// Doit soft-supprimer d'abord, puis supprimer définitivement
DELETE /notes/2/permanent   // la note 2 est active
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- Si 0 lignes affectées → note active ou n'existe pas → 404
```

## Diagramme d'états

```
Actif
  │
  │ DELETE /notes/{id}     (suppression douce)
  ▼
Soft-supprimé
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
Actif      Disparu (hard deleted)
```

**L'invariant clé** : la suppression permanente nécessite une suppression douce préalable. Cela empêche les suppressions physiques accidentelles depuis l'état actif.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Autoriser la suppression permanente d'une note active | Court-circuite le filet de sécurité de la suppression douce ; les données disparaissent sans fenêtre de récupération |
| Retourner 200 lors de la restauration d'une note active | Les appelants ne peuvent pas savoir si la restauration était nécessaire ; utiliser 404 pour signaler "pas dans la corbeille" |
| Pas d'index sur `deleted_at` | Scan complet de table pour chaque requête de liste ; `WHERE deleted_at IS NULL` est lent sans index |
| Hard delete immédiat sur `DELETE /notes/{id}` | Aucune récupération possible ; utiliser d'abord la suppression douce |
| Exposer `deleted_at` dans la liste active | Les clients voient le champ ; encombre visuellement les réponses ; le filtrer ou utiliser `null` |
