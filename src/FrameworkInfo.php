<?php

declare(strict_types=1);

namespace Nene2;

/**
 * Metadata about the NENE2 framework itself.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class FrameworkInfo
{
    public const string VERSION = '1.5.289';

    public function name(): string
    {
        return 'NENE2';
    }

    public function description(): string
    {
        return 'JSON APIs first, minimal server HTML, frontend ready, AI-readable.';
    }
}
