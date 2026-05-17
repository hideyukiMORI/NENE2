# Datenbanktest-Strategie

NENE2s Datenbankadapter-Tests sind standardmäßig deterministisch und benötigen keinen entwicklerspezifischen Datenbankserver.

## Standardstrategie

Framework-verwaltete Datenbankadapter-Tests verwenden zunächst eine SQLite-In-Memory-Datenbank.

Gründe:

- Tests laufen innerhalb des vorhandenen PHP-Containers
- Keine lokalen MySQL- oder PostgreSQL-Anmeldedaten erforderlich
- Jeder Test kann sein eigenes Schema erstellen
- Schnell genug für `composer check`
- Einfach zu inspizieren und zu verstehen

Standardbefehl für fokussierte Datenbankadapter-Prüfungen:

```bash
docker compose run --rm app composer test:database
```

`composer check` führt weiterhin die vollständige PHPUnit-Suite einschließlich Datenbankadapter-Tests aus.

## Test-Format

Datenbankadapter-Tests sollten:

- das Schema im Test erstellen
- kleine, deterministische Daten verwenden
- produktionsnahe Anmeldedaten vermeiden
- den Migrationsstatus nicht als Abhängigkeit haben (außer der Test betrifft explizit Migrationen)
- typisierte Konfigurationsobjekte gegenüber rohen Umgebungsvariablen bevorzugen
- SQL-Erwartungen nahe am getesteten Adapter platzieren

## Externe Datenbank

Für Adapter-Verhalten, das SQLite nicht abdecken kann, ist MySQL-Verifizierung über Docker Compose verfügbar.

Service starten und Opt-in-Befehl ausführen:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

Dieser Pfad verifiziert PDO MySQL-Verbindungserstellung, parametrierte Query-Ausführung und Transaktions-Rollback gegen einen echten MySQL-Service.

Externe Datenbanktest bleiben Opt-in, bis dokumentierte Service-Container und sichere Anmeldedaten in CI vorhanden sind. Sie blockieren nicht den Standard-lokalen `composer check`-Pfad.

Docker Compose-Standards sind rein lokale Entwicklungs-Anmeldedaten. Bei Bedarf mit Umgebungsvariablen überschreiben, und echte Datenbankgeheimnisse nicht committen.

## Migrationstest

Migrationstests sollten von Repository-Adapter-Tests getrennt sein.

Beim Einführen von Migrationstests definieren:

- welcher Datenbankservice in CI verwendet wird
- wie das Schema zwischen Läufen zurückgesetzt wird
- ob Seeds erlaubt sind
- welcher Composer-Befehl ausgeführt wird
- Verhalten wenn Migrationen absichtlich irreversibel sind
