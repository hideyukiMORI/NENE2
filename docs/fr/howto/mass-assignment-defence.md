# How-to : Défense contre la mass assignment avec DTO explicite

> **Référence FT** : FT256 (`NENE2-FT/masslog`) — Pattern de défense contre la mass assignment avec liste blanche explicite par DTO
> **ATK** : FT256 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Démontre comment prévenir les vulnérabilités de mass assignment en utilisant un DTO readonly explicite qui liste en blanc uniquement les champs que les appelants sont autorisés à définir. Les champs contrôlés par le serveur (`role`, `is_active`, `created_at`, `id`) sont exclus du DTO et codés en dur dans le repository. Inclut une évaluation complète avec une mentalité de cracker.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Créer un utilisateur (role=user) |
| `GET` | `/users` | Lister tous les utilisateurs |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` est un filet de sécurité au niveau DB. L'application écrit toujours `'user'` dans `role` à la création, donc la contrainte n'est jamais déclenchée en fonctionnement normal — elle protège contre les bugs ou l'accès direct à la DB.

---

## Le DTO explicite : liste blanche de champs

```php
/**
 * DTO explicite pour la création d'utilisateur — seuls name et email sont acceptés en entrée.
 *
 * role et is_active sont intentionnellement exclus : ils doivent être définis par la logique
 * métier côté serveur, jamais depuis le corps de la requête. C'est la défense contre la mass assignment.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

Le DTO a exactement deux champs — `name` et `email`. Il n'y a pas de champ `role`, `is_active`, `created_at` ou `id`. Un attaquant ne peut pas injecter ces champs parce que le constructeur ne les accepte tout simplement pas.

**Pourquoi c'est mieux qu'une liste noire** :

| Approche | Modèle de sécurité | Mode d'échec |
|---|---|---|
| Liste blanche explicite (DTO) | Rejeter l'inconnu par défaut | Sûr — les nouveaux champs doivent être explicitement ajoutés |
| Liste noire (`unset($body['role'])`) | Bloquer le connu-mauvais | Dangereux — les nouveaux champs sensibles sont oubliés |
| `array_intersect_key` | Filtrer aux clés connues | Acceptable — équivalent à la liste blanche si les clés sont complètes |

Un DTO explicite échoue de façon sécurisée : ajouter une nouvelle colonne sensible au schéma ne l'expose pas automatiquement — le développeur doit l'ajouter explicitement au DTO.

---

## Contrôleur : extraction explicite des champs

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // Seuls les champs autorisés sont mappés — les champs supplémentaires (role, is_active, etc.) sont silencieusement ignorés
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

Le contrôleur lit `$body['name']` et `$body['email']` explicitement. Toutes les autres clés dans `$body` sont silencieusement ignorées — elles ne sont jamais lues ni transmises nulle part.

L'email est normalisé en minuscules (`strtolower`) avant de créer le DTO, évitant les emails dupliqués qui diffèrent seulement par la casse.

---

## Repository : champs contrôlés par le serveur

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role et is_active sont codés en dur
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // codé en dur, pas depuis $input
        isActive:  true,      // codé en dur, pas depuis $input
        createdAt: $now,
    );
}
```

`'user'` et `1` sont des valeurs littérales dans l'INSERT. Il est impossible pour les données utilisateur d'influencer `role` ou `is_active`. La signature de type `CreateUserInput` du DTO l'applique au niveau du type PHP.

---

## ATK — Test d'attaque mentalité cracker (FT256)

### ATK-01 — Escalade de privilèges : injecter `role: "admin"` dans le corps

**Attaque** : Inclure `role` dans le corps de requête pour créer un utilisateur admin.

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**Observé** : `role` n'est pas un champ dans `CreateUserInput`. Le contrôleur lit uniquement `name` et `email` depuis `$body`. La clé supplémentaire est silencieusement ignorée. L'utilisateur créé a `role = 'user'`.

**Verdict** : **BLOCKED** — la liste blanche de champs DTO explicite prévient l'escalade de privilèges.

---

### ATK-02 — Manipulation de l'état du compte : injecter `is_active: false`

**Attaque** : Créer un utilisateur avec `is_active = false` pour créer un compte désactivé ou tester si le champ est modifiable.

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**Observé** : `is_active` n'est pas dans `CreateUserInput`. L'utilisateur créé a `is_active = true` (codé en dur dans l'INSERT).

**Verdict** : **BLOCKED** — `is_active` n'est jamais lu depuis la requête.

---

### ATK-03 — Manipulation d'horodatage : injecter `created_at`

**Attaque** : Antidater l'horodatage de création de l'utilisateur.

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**Observé** : `created_at` n'est pas dans `CreateUserInput`. Le repository génère `$now` depuis `DateTimeImmutable` au moment de l'écriture.

**Verdict** : **BLOCKED** — les horodatages d'audit sont générés par le serveur, pas fournis par le client.

---

### ATK-04 — Détournement d'ID : injecter `id: 9999`

**Attaque** : Pré-sélectionner une clé primaire pour écraser un enregistrement existant ou revendiquer un ID connu.

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**Observé** : `id` n'est pas dans `CreateUserInput`. L'INSERT utilise `AUTOINCREMENT` — l'`id` est assigné par SQLite, pas depuis une valeur fournie par l'utilisateur.

**Verdict** : **BLOCKED** — l'assignation de clé primaire est toujours côté serveur.

---

### ATK-05 — Injection SQL via name ou email

**Attaque** : Incorporer des métacaractères SQL.

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**Observé** : Les deux champs sont liés comme des placeholders paramétrés `?` dans l'INSERT. Le payload d'injection est stocké comme texte littéral.

**Verdict** : **BLOCKED** — les requêtes paramétrées préviennent l'injection SQL.

---

### ATK-06 — Contournement par casse d'email : soumettre un email en majuscules

**Attaque** : Enregistrer `ADMIN@EXAMPLE.COM` comme un utilisateur différent de `admin@example.com`.

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**Observé** : Le contrôleur applique `strtolower()` avant de passer au DTO. `ADMIN@EXAMPLE.COM` et `admin@example.com` se normalisent en `admin@example.com`. La contrainte `UNIQUE` prévient un second enregistrement.

**Verdict** : **BLOCKED** — normalisation de casse + contrainte UNIQUE préviennent les comptes dupliqués.

---

### ATK-07 — Email dupliqué : enregistrer la même adresse deux fois

**Attaque** : Enregistrer la même adresse email pour déclencher une erreur ou créer des comptes dupliqués.

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**Observé** : La première requête réussit avec `201`. La deuxième déclenche une violation de contrainte `UNIQUE` SQLite. L'implémentation actuelle ne capture pas cette exception — elle se propage comme une erreur non gérée.

**Verdict** : **EXPOSED** — capturer la violation de contrainte unique et retourner une réponse structurée `409 Conflict` ou `422 Unprocessable Entity`. Laisser fuiter les erreurs DB brutes est un problème de sécurité et d'UX.

---

### ATK-08 — Payload XSS dans name ou email

**Attaque** : Stocker une balise script.

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**Observé** : Le contenu est stocké tel quel et retourné verbatim en JSON. L'API n'encode pas la sortie en HTML.

**Verdict** : **ACCEPTED BY DESIGN** — les APIs JSON retournent du contenu brut. La couche de rendu doit assainir avant d'insérer dans le HTML.

---

### ATK-09 — Champs requis manquants

**Attaque** : Omettre `name` ou `email`.

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**Observé** : Chacun retourne `422 Unprocessable Entity` avec un tableau `errors` structuré identifiant le champ manquant par nom.

**Verdict** : **BLOCKED** — vérifications de présence explicites pour chaque champ requis.

---

### ATK-10 — Confusion de type : soumettre name comme entier

**Attaque** : Envoyer `name` comme un nombre JSON.

```json
{"name": 12345, "email": "typed@example.com"}
```

**Observé** : `is_string($body['name'])` retourne `false` pour les valeurs entières. La requête retourne `422` avec `name is required`.

**Verdict** : **BLOCKED** — `is_string()` rejette les types non-string.

---

### ATK-11 — Nom ou email très long

**Attaque** : Soumettre un nom ou email avec plus de 10 000 caractères.

```json
{"name": "aaaa...aaaa (10000 chars)", "email": "x@example.com"}
```

**Observé** : La requête réussit avec `201`. Aucune validation de longueur n'est appliquée à `name` ou `email`. SQLite stocke TEXT sans limite de longueur inhérente.

**Verdict** : **EXPOSED** — ajouter une validation de longueur (ex: `mb_strlen($name) > 255 → 422`). S'appuyer sur le middleware de taille de requête comme limite externe.

---

### ATK-12 — Valeurs de role multiples : injecter comme tableau

**Attaque** : Soumettre `role` comme un tableau plutôt qu'une chaîne.

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**Observé** : `role` n'est pas lu depuis `$body` du tout. Qu'il soit une chaîne, un tableau ou null n'a aucun effet sur l'utilisateur créé.

**Verdict** : **BLOCKED** — le DTO exclut entièrement `role` ; son type est sans importance.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Escalade de role via `role: "admin"` | BLOCKED |
| ATK-02 | Manipulation d'état via `is_active: false` | BLOCKED |
| ATK-03 | Antidatage via `created_at` | BLOCKED |
| ATK-04 | Détournement d'ID via `id: 9999` | BLOCKED |
| ATK-05 | Injection SQL via name/email | BLOCKED |
| ATK-06 | Contournement casse email (`ADMIN@EXAMPLE.COM`) | BLOCKED |
| ATK-07 | Email dupliqué (pas d'erreur gracieuse) | EXPOSED |
| ATK-08 | Payload XSS dans name | ACCEPTED BY DESIGN |
| ATK-09 | Champs requis manquants | BLOCKED |
| ATK-10 | Confusion de type (name comme entier) | BLOCKED |
| ATK-11 | Nom ou email très long (pas de limite de longueur) | EXPOSED |
| ATK-12 | Role comme tableau | BLOCKED |

**Vraies vulnérabilités à corriger avant la production** :
1. **ATK-07** — Capturer la violation de contrainte UNIQUE ; retourner `409 Conflict` avec un message utilisateur
2. **ATK-11** — Ajouter une validation de longueur `mb_strlen` pour `name` et `email`

---

## Howtos associés

- [`mass-assignment.md`](mass-assignment.md) — vue d'ensemble des patterns de défense contre la mass assignment
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — requêtes scopées par propriétaire pour prévenir l'IDOR
- [`rbac.md`](rbac.md) — contrôle d'accès basé sur les rôles avec claims JWT
- [`user-profile-management.md`](user-profile-management.md) — mise à jour de profil avec liste blanche de champs
