# Warum explizites Dependency-Wiring?

NENE2 verwendet explizites, handgeschriebenes Dependency-Wiring anstatt Autowiring oder konventionsbasierter Container-Magie. Diese Seite erklärt warum.

## Was explizites Wiring bedeutet

```php
// RuntimeServiceProvider.php — jede Abhängigkeit ist ausgeschrieben
$container->bind(NoteRepositoryInterface::class, function (ContainerInterface $c) {
    return new PdoNoteRepository($c->get(DatabaseQueryExecutorInterface::class));
});
```

## Die Gründe für explizites Wiring

### 1. Das Wiring ist immer auffindbar

Mit explizitem Wiring gibt `grep -r 'NoteRepository'` in den Service-Provider-Dateien die vollständige Antwort auf „Wie wird diese Klasse konstruiert?".

### 2. Fehler schlagen beim Start fehl, nicht zur Laufzeit

Ein explizites Binding, das auf eine fehlende Klasse verweist, schlägt beim Aufbau des Containers fehl.

### 3. KI-Agenten und statische Analyse können den Graphen verfolgen

Explizites Wiring erzeugt einen Abhängigkeitsgraphen, den grep, PHPStan und LLM-Agenten ohne Ausführen des Containers traversieren können.

### 4. Keine Kopplung durch Annotationen oder Attribute

NENE2-Domänenklassen tragen keine Container-Annotationen.

## Kompromisse

| Explizites Wiring | Autowiring |
|-----------------|------------|
| Immer lesbar | Weniger Boilerplate |
| Schnelles Scheitern beim Start | Praktisch für schnelles Scaffolding |
| Keine Magie | Erfordert Kenntnis der Container-Regeln |
| Ausführlich bei vielen Klassen | Skaliert automatisch |
