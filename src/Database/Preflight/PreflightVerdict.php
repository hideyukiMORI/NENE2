<?php

declare(strict_types=1);

namespace Nene2\Database\Preflight;

/**
 * The structured, read-only diagnosis a {@see DatabaseCandidateInspector} returns for a candidate
 * database.
 *
 * Carries reason codes only — never raw database names, hosts, or row data — so the verdict is safe
 * to return over the auth-gated machine endpoint and to log. Part of the public API stability
 * guarantee (ADR 0009).
 */
final readonly class PreflightVerdict
{
    /**
     * @param list<string> $reasonCodes Machine-readable codes explaining the recommendation
     *        (e.g. `['compatible']`, `['foreign_schema']`, `['compatible', 'tenant_unevaluated']`).
     * @param string $appIdentity Application-identity match state. Always `'not_evaluated'` in this
     *        scope (issue #1419 / A); identity-marker matching arrives in #1420 (B).
     * @param string $tenant Tenant match state. Always `'not_applicable'` in this scope; tenant
     *        matching arrives in #1420 (B).
     */
    public function __construct(
        public bool $reachable,
        public ?bool $schemaRecognized,
        public ?MigrationState $migrationState,
        public ?bool $populated,
        public PreflightRecommendation $recommendation,
        public array $reasonCodes,
        public string $appIdentity = 'not_evaluated',
        public string $tenant = 'not_applicable',
    ) {
    }

    /**
     * Build the verdict for a candidate that could not be reached (connection refused, timed out,
     * or otherwise failed to open). Everything beyond reachability is unknown, so the recommendation
     * is always {@see PreflightRecommendation::Refuse}.
     *
     * @param list<string> $reasonCodes
     */
    public static function unreachable(array $reasonCodes = ['unreachable']): self
    {
        return new self(
            reachable: false,
            schemaRecognized: null,
            migrationState: null,
            populated: null,
            recommendation: PreflightRecommendation::Refuse,
            reasonCodes: $reasonCodes,
        );
    }

    /**
     * @return array{
     *     reachable: bool,
     *     schema_recognized: bool|null,
     *     migration_state: string|null,
     *     populated: bool|null,
     *     recommendation: string,
     *     reason_codes: list<string>,
     *     app_identity: string,
     *     tenant: string
     * }
     */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'schema_recognized' => $this->schemaRecognized,
            'migration_state' => $this->migrationState?->value,
            'populated' => $this->populated,
            'recommendation' => $this->recommendation->value,
            'reason_codes' => $this->reasonCodes,
            'app_identity' => $this->appIdentity,
            'tenant' => $this->tenant,
        ];
    }
}
