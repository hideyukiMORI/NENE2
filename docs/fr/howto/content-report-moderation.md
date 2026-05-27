# Signalement et modération de contenu

Guide d'implémentation d'un système de signalement et de modération de contenu (articles).
Explique le RBAC (contrôle d'accès basé sur les rôles), la prévention IDOR, le signalement idempotent et les transitions de statut à sens unique.

## Vue d'ensemble

- Les utilisateurs signalent des articles (idempotent : re-signaler le même article retourne 200)
- Seuls les modérateurs peuvent consulter la liste des signalements, les résoudre ou les rejeter
- Les signalants ne peuvent consulter que leurs propres signalements (prévention IDOR)
- Le statut est à sens unique : `pending → resolved / dismissed` uniquement

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/reports` | Signaler un article (idempotent) |
| `GET` | `/reports` | Liste des signalements (modérateur uniquement) |
| `GET` | `/reports/{id}` | Détail du signalement (son propre ou modérateur) |
| `PUT` | `/reports/{id}/resolve` | Résoudre un signalement (modérateur uniquement) |
| `PUT` | `/reports/{id}/dismiss` | Rejeter un signalement (modérateur uniquement) |

## Conception de la base de données

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

`UNIQUE (reporter_id, article_id)` est la base de l'ajout idempotent.
Les contraintes `CHECK` garantissent les statuts et raisons de signalement valides au niveau DB.

## Signalement idempotent

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
$report = $this->repository->findReportById($id);

return $this->responseFactory->create($this->formatReport($report ?? []), 201);
```

Retour `201` = nouveau signalement, `200` = signalement existant (l'appelant distingue par le statut).

## RBAC — Vérification du rôle

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Les endpoints réservés aux modérateurs valident le rôle en début de gestionnaire.

## Prévention IDOR

```php
$isModerator = $actor !== null && $actor['role'] === 'moderator';
$isReporter  = (int) $report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

`GET /reports/{id}` n'est accessible qu'à "son propre signalement" ou aux modérateurs.
`reporter_id` n'est jamais récupéré depuis le corps de la requête — il est toujours défini depuis l'en-tête `X-User-Id`.

## Transition de statut (à sens unique)

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

Un signalement qui a déjà transitionné vers `resolved` ou `dismissed` ne peut plus être modifié.
La contrainte `CHECK` de la DB constitue un filet de sécurité pour les oublis de validation côté application.

## Récupération des paramètres de chemin

Le routeur NENE2 stocke les paramètres de chemin dans l'attribut `nene2.route.parameters`.

```php
// Méthode correcte
$id = (int) Router::param($request, 'id');

// Incorrect (getAttribute('id') ne fonctionne pas directement)
$id = (int) $request->getAttribute('id');
```

## Sécurité de reporter_id

```php
// createReport : actorId est déjà confirmé depuis l'en-tête X-User-Id
$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
```

`reporter_id` ignore le champ `reporter_id` du corps de la requête et utilise le `X-User-Id` authentifié.
Cela empêche l'usurpation d'identité d'autres utilisateurs.

## Exemple de réponse POST /reports

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "This article contains repeated spam links",
  "status": "pending",
  "resolved_by": null,
  "resolved_at": null,
  "resolution_note": null,
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Exemple de réponse PUT /reports/{id}/resolve

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "...",
  "status": "resolved",
  "resolved_by": 3,
  "resolved_at": "2026-05-21T13:00:00+00:00",
  "resolution_note": "Article removed for TOS violation",
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Exemple de réponse GET /reports (modérateur)

```json
{
  "reports": [
    {
      "id": 2,
      "reporter_id": 2,
      "article_id": 5,
      "reason": "harassment",
      "status": "pending",
      ...
    }
  ],
  "count": 1
}
```
