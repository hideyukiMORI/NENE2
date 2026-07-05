<?php

declare(strict_types=1);

namespace Nene2\Audit;

use Nene2\Database\DatabaseQueryExecutorInterface;

/**
 * {@see AuditEventRepositoryInterface} backed by a {@see DatabaseQueryExecutorInterface}
 * and steered by an {@see AuditTableConfig} (ADR 0014).
 *
 * All SQL is parameterised. The only identifiers spliced into a statement are
 * column/table names taken from the {@see AuditTableConfig} and a sort column
 * resolved from {@see AuditQuery}'s closed whitelist — never caller-supplied
 * strings. JSON is encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE`
 * so multibyte snapshots round-trip and an unencodable payload fails loudly
 * rather than silently truncating the trail.
 *
 * Append-only: there is no update or delete path.
 *
 * Concrete implementation detail — **outside** the public API stability guarantee
 * (ADR 0009). Depend on {@see AuditEventRepositoryInterface}.
 *
 * @phpstan-import-type SqlParameter from DatabaseQueryExecutorInterface
 * @phpstan-import-type SqlParameters from DatabaseQueryExecutorInterface
 */
final readonly class PdoAuditEventRepository implements AuditEventRepositoryInterface
{
    public function __construct(
        private DatabaseQueryExecutorInterface $query,
        private AuditTableConfig $config,
    ) {
    }

    public function append(AuditEvent $event): void
    {
        $columns = [];
        /** @var SqlParameters $params */
        $params = [];

        if (!$this->config->idIsAutoIncrement) {
            $columns[] = $this->config->idColumn;
            $params[] = $event->id;
        }

        $columns[] = $this->config->actionColumn;
        $params[] = $event->action;

        $columns[] = $this->config->entityTypeColumn;
        $params[] = $event->entityType;

        $columns[] = $this->config->entityIdColumn;
        $params[] = $event->entityId;

        $columns[] = $this->config->actorColumn;
        $params[] = $event->actorId;

        $columns[] = $this->config->organizationColumn;
        $params[] = $event->organizationId;

        $columns[] = $this->config->occurredAtColumn;
        $params[] = $event->occurredAt;

        if ($this->config->mode === AuditPayloadMode::BeforeAfter) {
            // beforeColumn / afterColumn are guaranteed non-null by AuditTableConfig.
            $columns[] = (string) $this->config->beforeColumn;
            $params[] = self::encode($event->before);

            $columns[] = (string) $this->config->afterColumn;
            $params[] = self::encode($event->after);

            if ($this->config->metadataColumn !== null) {
                $columns[] = $this->config->metadataColumn;
                $params[] = self::encode($event->metadata);
            }
        } else {
            // payloadColumn is guaranteed non-null by AuditTableConfig.
            $columns[] = (string) $this->config->payloadColumn;
            $params[] = self::encode(self::foldPayload($event));
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $this->query->execute(
            'INSERT INTO ' . $this->config->table . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            $params,
        );
    }

    /**
     * @return list<AuditEvent>
     */
    public function query(AuditQuery $query, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($query);

        $orderColumn = $this->config->physicalSortColumn($query->sortColumn);
        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->query->fetchAll(
            'SELECT ' . $this->selectColumns() . ' FROM ' . $this->config->table
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' ORDER BY ' . $orderColumn . ' ' . $query->sortDirection . ', ' . $this->config->idColumn . ' ' . $query->sortDirection
            . ' LIMIT ? OFFSET ?',
            $params,
        );

        return array_map(fn (array $row): AuditEvent => $this->mapRow($row), $rows);
    }

    public function count(AuditQuery $query): int
    {
        [$where, $params] = $this->buildWhere($query);

        $row = $this->query->fetchOne(
            'SELECT COUNT(*) AS cnt FROM ' . $this->config->table . ($where !== '' ? ' WHERE ' . $where : ''),
            $params,
        );

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * @return array{0: string, 1: SqlParameters}
     */
    private function buildWhere(AuditQuery $query): array
    {
        $clauses = [];
        /** @var SqlParameters $params */
        $params = [];

        if ($query->organizationId !== null) {
            $clauses[] = $this->config->organizationColumn . ' = ?';
            $params[] = $query->organizationId;
        }

        if ($query->entityType !== null) {
            $clauses[] = $this->config->entityTypeColumn . ' = ?';
            $params[] = $query->entityType;
        }

        if ($query->entityId !== null) {
            $clauses[] = $this->config->entityIdColumn . ' = ?';
            $params[] = $query->entityId;
        }

        if ($query->action !== null) {
            $clauses[] = $this->config->actionColumn . ' = ?';
            $params[] = $query->action;
        }

        if ($query->actorId !== null) {
            $clauses[] = $this->config->actorColumn . ' = ?';
            $params[] = $query->actorId;
        }

        if ($query->occurredFrom !== null) {
            $clauses[] = $this->config->occurredAtColumn . ' >= ?';
            $params[] = $query->occurredFrom;
        }

        if ($query->occurredTo !== null) {
            $clauses[] = $this->config->occurredAtColumn . ' <= ?';
            $params[] = $query->occurredTo;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function selectColumns(): string
    {
        $columns = [
            $this->config->idColumn,
            $this->config->actionColumn,
            $this->config->entityTypeColumn,
            $this->config->entityIdColumn,
            $this->config->actorColumn,
            $this->config->organizationColumn,
            $this->config->occurredAtColumn,
        ];

        if ($this->config->mode === AuditPayloadMode::BeforeAfter) {
            $columns[] = (string) $this->config->beforeColumn;
            $columns[] = (string) $this->config->afterColumn;

            if ($this->config->metadataColumn !== null) {
                $columns[] = $this->config->metadataColumn;
            }
        } else {
            $columns[] = (string) $this->config->payloadColumn;
        }

        return implode(', ', $columns);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AuditEvent
    {
        $before = null;
        $after = null;
        $metadata = null;

        if ($this->config->mode === AuditPayloadMode::BeforeAfter) {
            $before = self::decode($row[(string) $this->config->beforeColumn] ?? null);
            $after = self::decode($row[(string) $this->config->afterColumn] ?? null);

            if ($this->config->metadataColumn !== null) {
                $metadata = self::decode($row[$this->config->metadataColumn] ?? null);
            }
        } else {
            $folded = self::decode($row[(string) $this->config->payloadColumn] ?? null) ?? [];
            $before = self::sub($folded, 'before');
            $after = self::sub($folded, 'after');
            $metadata = self::sub($folded, 'metadata');
        }

        $rawId = $row[$this->config->idColumn] ?? null;
        $id = $rawId === null
            ? null
            : ($this->config->idIsAutoIncrement ? (int) $rawId : (string) $rawId);

        return new AuditEvent(
            action: (string) $row[$this->config->actionColumn],
            entityType: (string) $row[$this->config->entityTypeColumn],
            entityId: self::scalar($row[$this->config->entityIdColumn] ?? null),
            actorId: self::scalar($row[$this->config->actorColumn] ?? null),
            organizationId: self::scalar($row[$this->config->organizationColumn] ?? null),
            before: $before,
            after: $after,
            metadata: $metadata,
            occurredAt: (string) $row[$this->config->occurredAtColumn],
            id: $id,
        );
    }

    /**
     * Folds before / after / metadata into one object for a single-payload table.
     * Null branches are omitted so the stored JSON stays minimal.
     *
     * @return array<string, mixed>
     */
    private static function foldPayload(AuditEvent $event): array
    {
        $folded = [];

        if ($event->before !== null) {
            $folded['before'] = $event->before;
        }

        if ($event->after !== null) {
            $folded['after'] = $event->after;
        }

        if ($event->metadata !== null) {
            $folded['metadata'] = $event->metadata;
        }

        return $folded;
    }

    /**
     * @param array<string, mixed> $folded
     * @return array<string, mixed>|null
     */
    private static function sub(array $folded, string $key): ?array
    {
        $value = $folded[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    private static function scalar(mixed $value): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private static function encode(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
