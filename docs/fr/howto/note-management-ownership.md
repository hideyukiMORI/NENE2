# How-to : Gestion de notes avec propriété

> **Référence FT** : FT240 (`NENE2-FT/noteslog`) — API de gestion de notes
> **ATK** : FT240 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Démontre une API de gestion de notes avec opérations scopées par propriétaire, identification par en-tête `X-Auth-User`, prévention IDOR via `WHERE id = ? AND owner_id = ?`, et mises à jour par fusion de champs qui préservent les champs non spécifiés.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/notes` | Créer une note (nécessite l'en-tête `X-Auth-User`) |
| `GET` | `/notes` | Lister les notes appartenant à l'appelant |
| `GET` | `/notes/{id}` | Obtenir une seule note (404 si non trouvée ou pas propriétaire) |
| `PUT` | `/notes/{id}` | Mettre à jour une note (fusion de champs : les champs omis sont conservés) |
| `DELETE` | `/notes/{id}` | Supprimer une note (404 si non trouvée ou pas propriétaire) |

---

## Identification par en-tête `X-Auth-User`

L'API utilise un en-tête minimal de chaîne `X-Auth-User` comme identité de l'appelant :

```php
private function resolveAuthUser(ServerRequestInterface $request): ?string
{
    $userId = trim($request->getHeaderLine('X-Auth-User'));

    return $userId !== '' ? $userId : null;
}
```

`trim()` supprime les espaces en début/fin. Un en-tête vide-après-trim → `null` → `401 Unauthorized`. Toute chaîne non-vide est acceptée comme ID utilisateur valide — il n'y a pas de vérification de token.

C'est intentionnellement faible à des fins de démonstration. En production, remplacer par des claims JWT vérifiés ou des sessions basées sur cookie de session.

---

## Prévention IDOR : `WHERE id = ? AND owner_id = ?`

Chaque opération qui touche une note spécifique inclut `owner_id` dans la requête :

```php
/**
 * Retourne la note uniquement si elle appartient au propriétaire donné.
 * Retourne null pour "non trouvée" et "mauvais propriétaire" — les appelants retournent 404 dans les deux cas
 * pour prévenir la fuite d'information IDOR (ne pas exposer si une ressource existe).
 */
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    $row = $this->db->fetchOne(
        'SELECT * FROM notes WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

La méthode retourne `null` pour "non trouvée" et "mauvais propriétaire". Le contrôleur utilise la même réponse `404 Not Found` dans les deux cas :

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 pas 403 : ne pas révéler si la ressource existe (prévention IDOR)
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

Retourner `403 Forbidden` confirmerait que la ressource existe — l'approche `404` prévient les attaques d'énumération. Un appelant n'apprend rien sur les notes des autres utilisateurs.

---

## Mise à jour par fusion de champs

`PUT /notes/{id}` conserve les valeurs existantes pour les champs omis du corps de requête :

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

Si seul `title` est fourni, `body` conserve sa valeur courante — et vice versa. Cela diffère d'un remplacement complet (sémantique `PUT`) — cela se comporte davantage comme `PATCH`. Pour une sémantique `PUT` stricte, exiger les deux champs et retourner `422` si l'un est absent.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

`body` est par défaut `''` — pas de colonne nullable pour le corps du texte. `owner_id` est une chaîne libre (la valeur `X-Auth-User`) ; pas de clé étrangère vers une table d'utilisateurs.

---

## ATK — Test d'attaque mentalité cracker (FT240)

### ATK-01 — `X-Auth-User` est trivialement falsifiable

**Attaque** : Se faire passer pour un autre utilisateur en envoyant son ID dans l'en-tête.

```bash
curl -s -X GET http://localhost:8200/notes \
  -H 'X-Auth-User: alice'

curl -s -X GET http://localhost:8200/notes \
  -H 'X-Auth-User: bob'
```

**Observé** : Chaque requête retourne les notes appartenant à l'ID utilisateur dans l'en-tête. N'importe quel appelant peut se faire passer pour n'importe quel utilisateur en connaissant ou devinant sa chaîne d'ID.

**Verdict** : **EXPOSED** — l'en-tête ne porte aucune preuve cryptographique d'identité. Utiliser des tokens JWT signés ou des cookies de session pour l'auth en production.

---

### ATK-02 — Injection de saut de ligne dans `X-Auth-User`

**Attaque** : Incorporer des caractères d'injection d'en-tête HTTP (CR/LF) dans la valeur de l'en-tête.

```
X-Auth-User: alice\r\nX-Injected: evil
```

**Observé** : PSR-7 (Nyholm) supprime ou rejette les caractères d'en-tête invalides. La valeur d'en-tête est une chaîne simple — l'injection CRLF au niveau HTTP est gérée par le serveur (Swoole, Apache, Nginx) avant d'atteindre l'application. `trim()` supprime les espaces en début/fin mais n'ajoute pas de défense supplémentaire contre les caractères de contrôle incorporés.

**Verdict** : **BLOCKED** en pratique — les serveurs HTTP rejettent les en-têtes malformés avant qu'ils n'atteignent la couche application.

---

### ATK-03 — IDOR : lire la note d'un autre utilisateur

**Attaque** : Deviner ou énumérer les IDs de notes appartenant à un autre utilisateur.

```bash
curl -s http://localhost:8200/notes/1 -H 'X-Auth-User: bob'
# La note 1 a été créée par alice
```

**Observé** : `findByIdAndOwner(1, 'bob')` ne trouve aucune ligne correspondant à `id = 1 AND owner_id = 'bob'` → retourne `null` → `404 Not Found`. Bob ne peut pas déterminer que la note 1 existe.

**Verdict** : **BLOCKED** — requête scopée par propriétaire + 404 prévient l'IDOR.

---

### ATK-04 — Injection SQL via titre ou corps

**Attaque** : Incorporer des métacaractères SQL dans le corps de requête.

```json
{"title": "'; DROP TABLE notes; --", "body": "\" OR \"1\"=\"1"}
```

**Observé** : Les valeurs sont stockées comme valeurs `?` paramétrées — pas de concaténation de chaîne avec SQL. Les payloads d'injection sont stockés comme texte littéral.

**Verdict** : **BLOCKED** — les requêtes paramétrées préviennent toute injection SQL via les champs du corps.

---

### ATK-05 — Titre vide

**Attaque** : Créer une note avec un titre contenant uniquement des espaces ou vide.

```json
{"title": "   "}
{"title": ""}
```

**Observé** : `trim($body['title'])` réduit les deux à `""`. La vérification `title === ''` se déclenche → `422 Unprocessable Entity`.

**Verdict** : **BLOCKED** — `trim()` + vérification de chaîne vide gère l'entrée contenant uniquement des espaces.

---

### ATK-06 — En-tête `X-Auth-User` manquant

**Attaque** : Envoyer une requête sans l'en-tête `X-Auth-User`.

```bash
curl -s http://localhost:8200/notes
```

**Observé** : `getHeaderLine('X-Auth-User')` retourne `""`. Après `trim()` c'est toujours `""`. `$userId !== ''` échoue → `resolveAuthUser()` retourne `null` → `401 Unauthorized` avec une réponse Problem Details structurée.

**Verdict** : **BLOCKED** — l'en-tête manquant est traité comme non authentifié.

---

### ATK-07 — Usurpation d'identité via valeur arbitraire `X-Auth-User`

**Attaque** : Créer des notes avec une chaîne d'ID utilisateur privilégié.

```bash
# En supposant que 'admin' est un utilisateur spécial
curl -s -X POST http://localhost:8200/notes \
  -H 'X-Auth-User: admin' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Admin note"}'
```

**Observé** : `201 Created` — la note est créée avec `owner_id = 'admin'`. N'importe quelle chaîne est acceptée comme identité de l'appelant.

**Verdict** : **EXPOSED** (même cause racine qu'ATK-01). Sans auth cryptographique, il est impossible de distinguer un vrai admin d'un attaquant qui connaît la chaîne `"admin"`.

---

### ATK-08 — Payload XSS dans le titre ou corps

**Attaque** : Stocker une balise script.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observé** : Le contenu est stocké tel quel et retourné verbatim en JSON. L'API JSON n'encode pas la sortie en HTML.

**Verdict** : **ACCEPTED BY DESIGN** — les APIs JSON retournent du contenu brut. La couche de rendu doit assainir avant d'insérer dans le HTML. Documenter cette attente pour les consommateurs de l'API.

---

### ATK-09 — Mise à jour partielle perd des champs non intentionnels

**Attaque** : Tenter d'effacer `body` en l'omettant de la mise à jour.

```json
{"title": "New title"}
// L'appelant attend que body soit effacé ; en fait il est préservé
```

**Observé** : La logique de fusion de champs préserve `body` si absent de la requête : `$noteBody = isset($body['body']) ? $body['body'] : $note->body`. Le corps est inchangé — cela correspond à l'intention pour une API de mise à jour par fusion mais peut surprendre les appelants s'attendant à un remplacement complet (sémantique `PUT`).

**Verdict** : **ACCEPTED BY DESIGN** — comportement de mise à jour par fusion documenté. Si une sémantique `PUT` stricte est souhaitée, exiger tous les champs.

---

### ATK-10 — ID de note non numérique

**Attaque** : Passer une chaîne ou float comme `{id}`.

```
GET /notes/abc
GET /notes/1.5
```

**Observé** : `(int) 'abc'` = 0, `(int) '1.5'` = 1.
- `abc` → `findByIdAndOwner(0, ...)` → pas de ligne → `404 Not Found`.
- `1.5` → `findByIdAndOwner(1, ...)` → si la note 1 appartient à l'appelant, elle est retournée.

**Verdict** : **PARTIELLEMENT BLOCKED** — les chaînes non numériques correspondent à 404. Les floats sont silencieusement tronqués. Ajouter un garde `ctype_digit()` pour une validation stricte.

---

### ATK-11 — Supprimer une note inexistante ou non possédée

**Attaque** : DELETE d'un ID de note qui n'existe pas ou appartient à un autre utilisateur.

```bash
curl -s -X DELETE http://localhost:8200/notes/99999 -H 'X-Auth-User: alice'
curl -s -X DELETE http://localhost:8200/notes/1    -H 'X-Auth-User: eve'
# (la note 1 appartient à alice)
```

**Observé** : Le repository exécute `DELETE FROM notes WHERE id = ? AND owner_id = ?`. Si aucune ligne ne correspond (inexistante ou mauvais propriétaire), `$deleted = false` → `404 Not Found`. La tentative d'Eve retourne le même 404 qu'une note inexistante.

**Verdict** : **BLOCKED** — DELETE scopé par propriétaire + réponse 404 prévient la suppression cross-utilisateur.

---

### ATK-12 — `X-Auth-User` contenant uniquement des espaces

**Attaque** : Envoyer un en-tête contenant uniquement des espaces ou tabulations.

```
X-Auth-User:    
X-Auth-User: \t
```

**Observé** : `trim('   ')` = `""` → `$userId !== ''` échoue → `401 Unauthorized`.

**Verdict** : **BLOCKED** — `trim()` normalise les en-têtes contenant uniquement des espaces en vide.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | X-Auth-User est trivialement falsifiable | EXPOSED |
| ATK-02 | Injection de saut de ligne dans X-Auth-User | BLOCKED |
| ATK-03 | IDOR : lire la note d'un autre utilisateur | BLOCKED |
| ATK-04 | Injection SQL via titre/corps | BLOCKED |
| ATK-05 | Titre vide | BLOCKED |
| ATK-06 | En-tête X-Auth-User manquant | BLOCKED |
| ATK-07 | Usurpation d'identité via valeur d'en-tête arbitraire | EXPOSED |
| ATK-08 | XSS dans titre/corps | ACCEPTED BY DESIGN |
| ATK-09 | Surprise de fusion de champs lors de mise à jour partielle | ACCEPTED BY DESIGN |
| ATK-10 | ID de note non numérique | PARTIELLEMENT BLOCKED |
| ATK-11 | Suppression de note non possédée/inexistante | BLOCKED |
| ATK-12 | X-Auth-User contenant uniquement des espaces | BLOCKED |

**Vraies vulnérabilités à corriger avant la production** :
1. **ATK-01 / ATK-07** — Remplacer `X-Auth-User` par JWT signé ou vérification de session
2. **ATK-10** — Ajouter un garde `ctype_digit()` pour les paramètres de chemin d'ID

---

## Howtos associés

- [`use-bearer-auth.md`](use-bearer-auth.md) — authentification par token Bearer signé
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — patterns de prévention IDOR
- [`jwt-authentication.md`](jwt-authentication.md) — vérification JWT pour l'identification utilisateur
- [`scheduled-reminders.md`](scheduled-reminders.md) — pattern de validation d'en-tête V::userId()
