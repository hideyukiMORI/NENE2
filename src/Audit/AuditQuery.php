<?php

declare(strict_types=1);

namespace Nene2\Audit;

use InvalidArgumentException;

/**
 * Read-side filter for the audit trail (ADR 0014).
 *
 * Every filter field is optional; `null` does not constrain. The sort column and
 * direction are validated against a fixed whitelist **in the constructor**, so an
 * invalid sort fails fast at the boundary and can never reach SQL string
 * interpolation — the columns and directions the repository splices into
 * `ORDER BY` are guaranteed to come from this closed set, not from user input.
 *
 * `sortColumn` is a **logical** field name (see {@see SORT_COLUMNS}); the
 * repository translates it to the physical column via {@see AuditTableConfig},
 * so the whitelist is storage-independent.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
final readonly class AuditQuery
{
    /** Logical sort fields accepted by {@see $sortColumn}. */
    public const SORT_COLUMNS = ['occurred_at', 'id', 'action', 'entity_type'];

    /** Sort directions accepted by {@see $sortDirection} (normalised to upper case). */
    public const SORT_DIRECTIONS = ['ASC', 'DESC'];

    public string $sortColumn;

    public string $sortDirection;

    public function __construct(
        public string|int|null $organizationId = null,
        public ?string $entityType = null,
        public string|int|null $entityId = null,
        public ?string $action = null,
        public string|int|null $actorId = null,
        public ?string $occurredFrom = null,
        public ?string $occurredTo = null,
        string $sortColumn = 'occurred_at',
        string $sortDirection = 'DESC',
    ) {
        if (!in_array($sortColumn, self::SORT_COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid audit sort column "%s". Allowed: %s.',
                $sortColumn,
                implode(', ', self::SORT_COLUMNS),
            ));
        }

        $direction = strtoupper($sortDirection);

        if (!in_array($direction, self::SORT_DIRECTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid audit sort direction "%s". Allowed: %s.',
                $sortDirection,
                implode(', ', self::SORT_DIRECTIONS),
            ));
        }

        $this->sortColumn = $sortColumn;
        $this->sortDirection = $direction;
    }
}
