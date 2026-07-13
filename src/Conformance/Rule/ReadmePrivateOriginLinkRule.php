<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * R3 — README should not reference the private `nene-origin` repo.
 *
 * `nene-origin` is the fleet's private port-registry/governance repo
 * (`nene-origin/docs/development/local-ports.md` and similar). A public product
 * README that links or paths into it — a bare `nene-origin/...` path, a
 * `github.com/hideyukiMORI/nene-origin` URL, or a markdown link whose target
 * contains the slug — gives an external reader (no access to the private repo)
 * a dead 404 link. The correct home for that content is the product's own
 * `AGENTS.md`/`CLAUDE.md` port section, which ships alongside the README and is
 * readable by everyone who can read the README itself.
 *
 * Detection is a plain word-bounded scan for the repo slug `nene-origin`
 * (case-insensitive) rather than parsing markdown link syntax, so it also
 * catches the rare prose mention — false positives are minimal because the
 * slug's only realistic appearance in a README is a link/path reference.
 *
 * This is a `warn`, not an `error`: the fleet is already clean (no product
 * README currently references `nene-origin`), so this rule exists to catch a
 * *regression*, not an active violation — same warn-first posture as
 * {@see ReadmeLicenseSectionRule}.
 *
 * A repository with no `README.md` produces no findings — same convention as
 * {@see ReadmeStaticStatusBadgeRule} and {@see ReadmeLicenseSectionRule}.
 */
final class ReadmePrivateOriginLinkRule implements RuleInterface
{
    private const README_FILE = 'README.md';

    /** Matches the repo slug `nene-origin` as a whole token (word-bounded). */
    private const ORIGIN_SLUG_PATTERN = '/\bnene-origin\b/i';

    public function id(): string
    {
        return 'R3';
    }

    public function description(): string
    {
        return 'README should not link/path into the private `nene-origin` repo (broken link for external readers).';
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
            if (preg_match(self::ORIGIN_SLUG_PATTERN, $line) !== 1) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Warn,
                self::README_FILE,
                $index + 1,
                'README references the private `nene-origin` repo (broken link for external readers). '
                . "Link to this repo's own AGENTS.md/CLAUDE.md port section instead.",
            );
        }

        return $findings;
    }
}
