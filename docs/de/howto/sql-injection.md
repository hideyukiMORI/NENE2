# SQL-Injection-Abwehr

NEne2-Datenbankm methoden (`execute`, `insert`, `fetchOne`, `fetchAll`) verwenden intern PDO Prepared
Statements. Jeder Wert, der im `$parameters`-Array übergeben wird, wird als PDO-Parameter gebunden —
niemals in den SQL-String interpoliert.

## Standardmäßig sicher: Wert-Parameter

```php
// Alle Werte durchlaufen PDO-Bindung — injection-sicher unabhängig vom Inhalt
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// LIKE-Suche — Wildcard im SQL-Literal, Wert separat gebunden
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

Klassische Payloads (`' OR '1'='1`, `'; DROP TABLE products; --`, `UNION SELECT ...`) werden zu
wörtlichen Suchzeichenketten, weil PDO sie nie in SQL interpoliert.

## Die ORDER BY-Fußangel — Whitelist erforderlich

**PDO kann Spaltennamen oder SQL-Strukturelemente nicht parametrisieren.** `ORDER BY ?` funktioniert
nicht — es bindet einen wörtlichen Zeichenkettenwert, keine Spaltenreferenz.

Wenn ein Entwickler Benutzereingaben direkt in `ORDER BY` einfügt, wird es zu einem Injection-Vektor:

```php
// UNSICHER — niemals so machen
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- führt den DROP aus
```

**Immer gegen eine explizite Whitelist validieren, bevor Spaltennamen interpoliert werden:**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    // Nur ASC oder DESC — normalisieren, nie rohe Benutzereingaben interpolieren
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

Dasselbe Prinzip gilt für jedes SQL-Strukturelement: Tabellennamen, Spaltennamen in `GROUP BY`,
`HAVING`, `INSERT INTO ... (col1, col2)` — keines davon kann als PDO-Parameter gebunden werden.
Vor der Interpolation Whitelist-validieren.

## IN-Klausel mit variabler Länge

PDO unterstützt nicht das direkte Binden einer Liste variabler Länge. Die Platzhalter-Liste explizit aufbauen:

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## Zusammenfassung

| Eingabetyp | Sichere Methode |
|---|---|
| Filterwert (`WHERE col = ?`) | `?`-Platzhalter in `$parameters` |
| LIKE-Wert | `'%' \|\| ? \|\| '%'` — Wert in `$parameters` |
| ORDER BY-Spalte | Whitelist `in_array` + nur nach Bestehen interpolieren |
| ORDER-Richtung | Auf wörtliches `'ASC'` oder `'DESC'` normalisieren |
| IN-Liste | `?`-Platzhalter aus `count()` aufbauen, Array als Params spreaden |
| Tabellen-/Spaltenname | Nur Whitelist — niemals aus Benutzereingaben akzeptieren |
