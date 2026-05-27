# How-to : API de versionnement de documents

> **Référence FT** : FT239 (`NENE2-FT/doclog`) — API de versionnement de documents

Démontre un système de versionnement de documents en ajout uniquement où la version actuelle est suivie avec un drapeau `is_current`, le retour en arrière crée une nouvelle version (non destructif), et toutes les écritures multi-étapes sont enveloppées dans des transactions via `DatabaseTransactionManagerInterface`.

---

## Routes

| Méthode | Chemin                                      | Description                                              |
|---------|---------------------------------------------|----------------------------------------------------------|
| `POST`  | `/documents`                                | Créer un document avec sa première version               |
| `GET`   | `/documents`                                | Lister les documents (paginé) avec la version actuelle   |
| `GET`   | `/documents/{id}`                           | Obtenir un document avec sa version actuelle             |
| `GET`   | `/documents/{id}/versions`                  | Lister l'historique des versions (paginé)                |
| `POST`  | `/documents/{id}/versions`                  | Ajouter une nouvelle version                             |
| `POST`  | `/documents/{id}/revert/{version}`          | Revenir à un numéro de version spécifique                |

Les sous-routes statiques (`/documents/{id}/versions`) sont enregistrées avant la route paramétrisée `/documents/{id}` pour garantir un dispatch correct.

---

## Schéma : pattern de drapeau `is_current`

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    content     TEXT    NOT NULL,
    version_num INTEGER NOT NULL,
    is_current  INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0, 1)),
    created_at  TEXT    NOT NULL,
    UNIQUE(document_id, version_num)
);
CREATE INDEX IF NOT EXISTS idx_versions_document ON document_versions(document_id);
```

`is_current` est un drapeau booléen (0/1) stocké comme INTEGER, contraint par `CHECK`. Au plus une ligne par document devrait avoir `is_current = 1`. `UNIQUE(document_id, version_num)` empêche les numéros de version en double pour le même document.

**Comparaison avec l'entier `current_version`** : l'approche par drapeau `is_current` évite la nécessité de mettre à jour une colonne sur la table parente `documents` à chaque changement de version. Le drapeau est basculé sur la table `document_versions` directement dans la même transaction qui insère la nouvelle version.

---

## Récupérer la version actuelle avec JOIN

Les requêtes de liste et d'affichage utilisent un `LEFT JOIN` filtré sur `is_current = 1` pour récupérer la version actuelle en une seule requête :

```php
$row = $this->executor->fetchOne(
    'SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current,
            dv.created_at AS version_created_at
     FROM documents d
     LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
     WHERE d.id = ?',
    [$id],
);
```

`LEFT JOIN ... AND dv.is_current = 1` — la condition de jointure filtre uniquement sur la version actuelle. Un document sans versions retourne une ligne JOIN `NULL`, hydrée comme `currentVersion: null`.

---

## Ajouter une version : transaction en trois étapes

Ajouter une version nécessite trois opérations en séquence, enveloppées dans une transaction :

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // Étape 1 : Calculer le prochain numéro de version
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Étape 2 : Désactiver la version actuelle
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Étape 3 : Insérer la nouvelle version comme actuelle
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // Étape 4 : Mettre à jour le updated_at du document
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` enveloppe la closure dans une transaction. Si une étape lève une exception, la transaction est annulée. Le paramètre `$tx` est l'exécuteur scopé à la transaction — pas de connexion séparée nécessaire.

---

## Retour en arrière non destructif : copier comme nouvelle version

Les retours en arrière ne changent pas l'historique existant — ils créent une nouvelle version contenant le contenu de la version cible :

```php
public function revertToVersion(int $documentId, int $versionNum, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $versionNum, $now): Document {
        $targetRow = $tx->fetchOne(
            'SELECT * FROM document_versions WHERE document_id = ? AND version_num = ?',
            [$documentId, $versionNum],
        );

        if ($targetRow === null) {
            throw new VersionNotFoundException($documentId, $versionNum);
        }

        // Calculer le prochain numéro de version pour la copie de retour en arrière
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Désactiver la version actuelle
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Insérer une copie du contenu cible comme nouvelle version actuelle
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

Si un document est à la version 5 et revient à la version 2, la version 6 est créée avec le contenu de la version 2. L'historique est :
```
v1 → v2 → v3 → v4 → v5 → v6 (copie de v2)
```

Cette approche préserve la piste d'audit complète — le retour en arrière lui-même est visible dans l'historique comme une nouvelle entrée. Il est impossible de "perdre" l'historique.

---

## VersionNotFoundException avec contexte structuré

`VersionNotFoundException` porte à la fois l'ID du document et le numéro de version :

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

L'exception est levée à l'intérieur de la closure de transaction. Le gestionnaire d'exceptions la mappe à une réponse `404 Not Found`. Parce que l'exception est levée avant toute opération d'écriture dans le retour en arrière, la transaction est annulée proprement.

---

## Outils NENE2 : PaginationQueryParser et PaginationResponse

Les endpoints de liste utilisent les helpers de pagination de NENE2 :

```php
private function listDocuments(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request);
    $items      = $this->repository->findAll($pagination->limit, $pagination->offset);
    $total      = $this->repository->countAll();

    $response = new PaginationResponse(
        items: array_map($this->serializeDocument(...), $items),
        limit: $pagination->limit,
        offset: $pagination->offset,
        total: $total,
    );

    return $this->json->create($response->toArray());
}
```

`PaginationQueryParser::parse()` lit `?limit=` et `?offset=` depuis les paramètres de requête avec des valeurs par défaut et des bornes sûres. `PaginationResponse::toArray()` produit une enveloppe cohérente : `{ items, total, limit, offset }`.

---

## Outils NENE2 : ValidationException et ValidationError

La validation des entrées utilise les helpers de validation structurés de NENE2 :

```php
$errors = [];
if (!isset($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
    $errors[] = new ValidationError('title', 'title is required.', 'required');
}
if (!isset($body['content']) || !is_string($body['content'])) {
    $errors[] = new ValidationError('content', 'content is required.', 'required');
}
if ($errors !== []) {
    throw new ValidationException($errors);
}
```

`ValidationException` est capturée par le gestionnaire d'erreurs de NENE2 et convertie en une réponse Problem Details `422 Unprocessable Entity` avec un tableau `errors` structuré — identique à appeler `ProblemDetailsResponseFactory::create()` avec l'extension `errors`, mais via le chemin basé sur les exceptions.

---

## Guides associés

- [`content-versioning.md`](content-versioning.md) — pattern current_version basé sur un entier
- [`audit-trail.md`](audit-trail.md) — patterns d'historique en ajout uniquement
- [`transactions.md`](transactions.md) — patterns DatabaseTransactionManagerInterface
- [`use-transactions.md`](use-transactions.md) — envelopper les opérations multi-écriture
