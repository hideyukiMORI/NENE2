<?php

declare(strict_types=1);

namespace Nene2\Conformance;

/**
 * Severity layer of a conformance finding.
 *
 * Only {@see Severity::Error} is emitted by the current rule set; `Warn` and
 * `Info` exist so the vocabulary is stable as later rules (design doc 04, layers
 * D5–D12) are added without a breaking change to the output format.
 *
 * An `Error` finding causes the runner to exit non-zero (CI red). `Warn` and
 * `Info` are visible but never fail the build.
 */
enum Severity: string
{
    case Error = 'error';
    case Warn = 'warn';
    case Info = 'info';
}
