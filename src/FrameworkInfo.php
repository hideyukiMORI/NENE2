<?php

declare(strict_types=1);

namespace Nene2;

final readonly class FrameworkInfo
{
    public function name(): string
    {
        return 'NENE2';
    }

    public function description(): string
    {
        return 'JSON APIs first, minimal server HTML, frontend ready, AI-readable.';
    }
}
