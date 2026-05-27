# Comment prévenir la double réservation (réservation et application de capacité)

Les systèmes de réservation ont deux modes de défaillance distincts qui doivent être traités séparément :

1. **Réservation dupliquée** — le même utilisateur essaie de réserver le même créneau deux fois
2. **Surcharge de capacité** — le nombre de réservations dépasserait la limite du créneau

Les deux résultent en un INSERT rejeté, mais nécessitent des réponses d'erreur différentes.
Ce guide montre comment les distinguer et se protéger contre les conflits concurrents.

---

## 1. Schéma : contrainte UNIQUE + colonne de capacité

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- garde de dernier recours contre les réservations dupliquées
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

La contrainte `UNIQUE(slot_id, user_id)` est le filet de sécurité — elle prévient les réservations dupliquées même si la logique applicative a un bug. Mais elle ne peut pas dire *pourquoi* l'INSERT a échoué.

---

## 2. Distinguer doublon de surcharge avec une vérification explicite

`DatabaseConstraintException` ne porte pas d'information au niveau colonne sur quelle contrainte a été déclenchée. Pour retourner des réponses 409 différentes, vérifier chaque condition avant l'INSERT :

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. Vérifier d'abord la réservation dupliquée
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. Vérifier la capacité restante
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // l'appelant mappe null → 409 slot-full
    }

    // 3. INSERT — la contrainte UNIQUE est la garde finale
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

Utiliser une **exception de domaine** (`AlreadyReservedException`) pour la règle métier orientée utilisateur, pas `DatabaseConstraintException` — qui signale un événement au niveau base de données, pas une condition métier.

---

## 3. Gestionnaire : mapper vers des réponses 409 distinctes

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. Calculer la disponibilité en SQL (éviter N+1)

Compter les réservations dans la même requête que la récupération du créneau :

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

Puis `available = capacity - reserved`. Ne jamais récupérer toutes les réservations pour les compter en PHP.

---

## 5. Concurrence : TOCTOU et quand cela importe

Le pattern de vérification-puis-insertion a une **fenêtre TOCTOU (Time-of-Check / Time-of-Use)** :
deux requêtes concurrentes peuvent toutes deux passer la vérification de capacité puis toutes deux essayer d'insérer.

| Base de données | Comportement |
|----------------|-------------|
| **SQLite** | Sérialisation des écritures par base de données : une seule écriture s'exécute à la fois. Le second INSERT heurte la contrainte UNIQUE et lance `DatabaseConstraintException`. Sûr. |
| **PostgreSQL** sous haute concurrence | Deux requêtes avec `user_id` différents peuvent toutes deux passer la vérification `available > 0` et toutes deux insérer, dépassant brièvement la capacité de 1. La contrainte UNIQUE ne se déclenche pas (utilisateurs différents). |

**Correction pour PostgreSQL** : envelopper la vérification et l'INSERT dans une transaction `SERIALIZABLE`, ou utiliser `SELECT ... FOR UPDATE` sur la ligne du créneau pour la verrouiller avant lecture :

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // Toutes les requêtes dans cette closure partagent le même snapshot sérialisable
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

Pour SQLite, la contrainte UNIQUE seule est une protection suffisante.

---

## 6. Tester les scénarios de style concurrent

Les tests séquentiels ne peuvent pas reproduire une vraie concurrence, mais ils peuvent vérifier l'intention :

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // arrive "simultanément"

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // créneau plein

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // Après annulation, bob peut réserver
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## Notes

- La contrainte UNIQUE est une **garde de dernier recours** — elle attrape les bugs dans la logique applicative.
  Ne jamais s'appuyer dessus comme principal appliqueur de capacité, car elle ne peut pas distinguer utilisateur-dupliqué de sur-capacité.
- **Annuler et re-réserver** : quand un utilisateur annule, supprimer de `reservations`. Le comptage de capacité décrémente automatiquement via la requête `COUNT(r.id)`. Pas besoin d'une mise à jour explicite "libérer le créneau".
- **Annulation idempotente** : `DELETE WHERE slot_id = ? AND user_id = ?` retourne 0 lignes si la réservation n'existe pas — mapper cela à 404, pas 500.
