<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * R2 — README should carry a `## License` section, not just a badge.
 *
 * A `License: MIT` badge is a compact pointer, but it is easy to leave dangling
 * (wrong link, stale SPDX id) and it carries no room for the actual grant text
 * or an exception. A `## License` (or deeper, `### License`) heading gives the
 * license a stable home next to the rest of the README's prose, where a reader
 * — human or AI — expects to find it and where drift is easy to spot on review.
 *
 * This is a `warn`, not an `error`: a badge alone is a real (if thin) signal,
 * not an outright violation, so it is surfaced without failing CI.
 *
 * A repository with no `README.md` produces no findings — same convention as
 * {@see ReadmeStaticStatusBadgeRule} and {@see \Nene2\Conformance\ProjectFiles}'s
 * source-scanning rules.
 */
final class ReadmeLicenseSectionRule implements RuleInterface
{
    private const README_FILE = 'README.md';

    private const LICENSE_HEADING_PATTERN = '/^#{2,}\s*License\b/mi';

    public function id(): string
    {
        return 'R2';
    }

    public function description(): string
    {
        return 'README should have a `## License` section (badge alone is not enough).';
    }

    public function check(string $root): array
    {
        $path = $root . '/' . self::README_FILE;

        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        if (preg_match(self::LICENSE_HEADING_PATTERN, $raw) === 1) {
            return [];
        }

        return [
            new Finding(
                $this->id(),
                Severity::Warn,
                self::README_FILE,
                0,
                'README has no `## License` section (badge alone is not enough).',
            ),
        ];
    }
}
