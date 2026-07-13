<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * R1 — static README status/phase badge instead of a `## Status` section.
 *
 * A shields.io badge of the shape `.../badge/status-...` (e.g.
 * `status-Phase%201-blue`, `status-Early-orange`) hand-encodes a maturity claim
 * into an image URL. Unlike CI/License/PHP-version/Packagist badges — which are
 * either dynamic (recomputed by shields.io from a live source) or effectively
 * invariant (a license rarely changes) — a status/phase badge is a static string
 * a human must remember to edit, and the fleet's own experience is that it goes
 * stale (see `nene-status-badges-unreliable`): the badge keeps saying "Phase 0"
 * long after the code has moved on. A `## Status` section (prose or a table) is
 * cheap to keep honest by comparison, since it sits next to the content that
 * makes it true or false.
 *
 * Detection is a plain text scan for the literal `shields.io/badge/status-`
 * (case-insensitive) rather than a generic badge/token scan, so it is
 * deliberately narrow: it never trips on the CI, License, PHP version or
 * Packagist badges NENE2 itself ships, only on a `status-`/`phase-`-prefixed
 * badge slug.
 *
 * A repository with no `README.md` produces no findings — same convention as
 * {@see \Nene2\Conformance\ProjectFiles}'s source-scanning rules.
 */
final class ReadmeStaticStatusBadgeRule implements RuleInterface
{
    private const README_FILE = 'README.md';

    /** Matches the fixed shields.io URL segment a static status/phase badge uses. */
    private const BADGE_PATTERN = '/shields\.io\/badge\/status-[^)\]\s]*/i';

    public function id(): string
    {
        return 'R1';
    }

    public function description(): string
    {
        return 'No static README status/phase badge (shields.io/badge/status-...); use a `## Status` section instead.';
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

        $findings = [];

        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match_all(self::BADGE_PATTERN, $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $badge) {
                $findings[] = new Finding(
                    $this->id(),
                    Severity::Error,
                    self::README_FILE,
                    $index + 1,
                    sprintf(
                        'Static status/phase badge in README (`%s`). Maturity belongs in a `## Status` '
                        . 'section (table), not a brittle badge that goes stale.',
                        $badge,
                    ),
                );
            }
        }

        return $findings;
    }
}
