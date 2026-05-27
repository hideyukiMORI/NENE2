# How-to : Système de signalement de contenu

> **Référence FT** : FT289 (`NENE2-FT/reportlog`) — Signalement de contenu : raisons en liste d'autorisation (enum ReportReason), UNIQUE(reporter_id, article_id) avec idempotence 200 sur doublon, machine à états pending→resolved/dismissed, liste/resolve/dismiss réservés aux modérateurs, contraintes CHECK au niveau DB, 32 tests / 58 assertions PASS.

Ce guide montre comment construire un système de signalement de contenu où les utilisateurs signalent du contenu et les modérateurs examinent et résolvent les signalements.

## Schéma

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

Les contraintes `CHECK` au niveau DB appliquent les valeurs d'enum même si la validation applicative est contournée.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/reports` | `X-User-Id` | Soumettre un signalement |
| `GET` | `/reports` | Modérateur | Lister tous les signalements |
| `GET` | `/reports/{id}` | Signalant ou Modérateur | Obtenir un signalement |
| `PUT` | `/reports/{id}/resolve` | Modérateur | Résoudre le signalement |
| `PUT` | `/reports/{id}/dismiss` | Modérateur | Rejeter le signalement |

## Enum ReportReason

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` rejette les valeurs inconnues. Le gestionnaire retourne les raisons valides dans la réponse d'erreur :

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## Soumission de signalement idempotente

Si un utilisateur a déjà signalé le même article, retourner le signalement existant avec 200 (pas 201) :

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// Première fois : 201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` est le filet de sécurité au niveau DB. L'application vérifie d'abord pour retourner une réponse conviviale, mais la contrainte UNIQUE est le vrai garde-fou.

## Cycle de vie du statut

```
pending ──→ resolved (action du modérateur)
       └──→ dismissed (action du modérateur)
```

Une fois résolu ou rejeté, un signalement ne peut plus transitionner. Tenter de modifier un signalement non-pending retourne 422 :

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## Vérification du rôle modérateur

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Le rôle est stocké dans la table `users` et vérifié à chaque opération privilégiée. Un `CHECK (role IN ('user', 'moderator'))` au niveau DB empêche l'insertion de rôles invalides.

## Contrôle d'accès : Signalant vs Modérateur

GET `/reports/{id}` est accessible au signalant original et aux modérateurs :

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Les signalants peuvent voir leurs propres signalements pour suivre leur statut. Les modérateurs voient tous les signalements.

## Résolution avec piste d'audit

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by` (ID du modérateur), `resolved_at` (horodatage) et `resolution_note` (optionnel) créent une piste d'audit pour chaque action de modération.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Accepter des chaînes de raison libres | Fautes de frappe, injection, catégories infinies ; utiliser une liste d'autorisation d'enum |
| Pas de `UNIQUE(reporter_id, article_id)` | Le même utilisateur soumet des dizaines de signalements pour le même article ; file d'attente gonflée |
| Retourner 409 sur un signalement en doublon | Idempotence sûre au retry : doublon → 200 avec signalement existant, pas une erreur |
| Autoriser la transition depuis resolved/dismissed | Signalement résolu réouvert ; piste d'audit devient peu fiable |
| Pas de vérification du rôle modérateur sur list/resolve | N'importe quel utilisateur lit tous les signalements ; violation de confidentialité + contournement d'audit |
| Retourner le signalement d'un autre utilisateur | IDOR — vérifier toujours signalant === acteur ou acteur est modérateur |
| Pas de champ `resolution_note` | Les modérateurs ne peuvent pas communiquer pourquoi un signalement a été rejeté vs résolu |
| Pas de champ `resolved_by` | Impossible d'auditer quel modérateur a agi |
| CHECK DB uniquement, pas de validation applicative | La DB lance une exception sur une raison invalide ; l'utilisateur reçoit 500 au lieu de 422 |
