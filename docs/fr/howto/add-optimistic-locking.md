# Comment ajouter le contrôle de concurrence optimiste (ETag / If-Match)

Le verrouillage optimiste empêche le **problème de mise à jour perdue** : deux clients lisent la même ressource,
la modifient tous les deux, et la deuxième écriture écrase silencieusement la première.

NENE2 inclut `ConditionalWriteHelper` pour le côté écriture (PUT, PATCH, DELETE) et
`ConditionalGetHelper` pour le côté lecture (GET → 304 Not Modified).

---

## 1. Ajouter un compteur de version au schéma

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. Retourner un ETag à chaque réponse GET et d'écriture

Utilisez le numéro de version comme ETag simple et déboguable :

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// Dans le gestionnaire GET :
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// Dans le gestionnaire POST (création) :
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. Vérifier `If-Match` sur PUT / PATCH / DELETE

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed ou 428 Precondition Required
    }

    // ETag correspond — écriture sécurisée
    $updated = $this->repo->updateIfMatch($id, /* nouvelles valeurs */, $doc->version);
    if ($updated === null) {
        // Modification concurrente après notre vérification
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### Codes de statut retournés par `ConditionalWriteHelper::check()`

| En-tête `If-Match` | ETag serveur | Résultat |
|-------------------|-------------|--------|
| absent | quelconque | **428** Precondition Required (l'en-tête est obligatoire) |
| `*` | quelconque | **null** — passe (wildcard, n'importe quelle version) |
| `"v3"` | `"v3"` | **null** — passe (correspondance exacte) |
| `"v2"` | `"v3"` | **412** Precondition Failed (version périmée) |

Pour rendre `If-Match` optionnel, passez `require: false` :

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. Utiliser un UPDATE conditionnel dans le repository

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // incompatibilité de version ou non trouvé
    }
    return new Document($id, $title, $newVer, $now);
}
```

La clause `WHERE version = ?` est le verrou de protection au niveau de la base de données. Si la version de la ligne a déjà
été avancée par un autre écrivain concurrent, `execute()` retourne `0` (aucune ligne mise à jour) et
l'appelant peut retourner une seconde réponse 412.

---

## 5. Tester le scénario de mise à jour perdue

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice lit la version 1 et met à jour → la version devient 2
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob essaie de mettre à jour avec l'ETag périmé v1 → doit échouer
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // La mise à jour d'Alice est préservée
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## Notes

- **Format ETag** : `"v{version}"` (basé sur un entier) est simple et prévisible dans les tests.
  Les ETags basés sur le hash du contenu (`'"' . md5($body) . '"'`) sont plus robustes pour les ressources
  adressables par contenu mais plus difficiles à prédire dans les tests sans précalculer le hash.
- **Wildcard `If-Match: *`** : RFC 9110 définit `*` pour signifier "réussir si la ressource a une
  représentation actuelle" — c'est-à-dire qu'elle existe. Utile pour "mettre à jour si elle existe" sans connaître
  la version. L'appelant doit quand même retourner 404 quand la ressource est absente.
- **428 Precondition Required** (RFC 6585 §3) : le statut correct quand `If-Match` est requis
  mais absent. Utilisez-le plutôt que 400 ou 422 — la requête est bien formée ; la précondition est manquante.
- **Fenêtre TOCTOU** : le pattern `findById()` + UPDATE conditionnel a une brève fenêtre de course sur
  les bases de données multi-écrivains. Sous la sérialisation des écritures de SQLite, c'est sans danger. Sur PostgreSQL
  sous haute concurrence, enveloppez les deux opérations dans une transaction `SERIALIZABLE`.
