<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * D3 — the conformance linter is not wired into `composer check`.
 *
 * A linter that nobody runs drifts back to zero value. This rule makes the tool
 * self-enforcing: unless `scripts.check` in `composer.json` invokes conformance
 * (either the `@conformance` script reference or a direct `conformance.php`
 * call), the check pipeline is silently a no-op with respect to conformance and
 * the rule fails.
 *
 * There is no baseline path for this one by design — it is a single, unambiguous
 * manifest fact with no false positives, and baselining it would defeat its whole
 * purpose (self-enforcement).
 */
final class CheckSelfRegistrationRule implements RuleInterface
{
    public function id(): string
    {
        return 'D3';
    }

    public function description(): string
    {
        return 'composer.json scripts.check must invoke conformance (@conformance) so the linter is self-enforcing.';
    }

    public function check(string $root): array
    {
        $path = $root . '/composer.json';

        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $scripts = $data['scripts'] ?? null;
        $check = is_array($scripts) ? ($scripts['check'] ?? null) : null;

        // A repo without a `check` aggregate is outside this rule's remit.
        if ($check === null) {
            return [];
        }

        $entries = is_array($check) ? $check : [$check];

        foreach ($entries as $entry) {
            if (is_string($entry) && $this->invokesConformance($entry)) {
                return [];
            }
        }

        return [
            new Finding(
                $this->id(),
                Severity::Error,
                'composer.json',
                0,
                'scripts.check does not run conformance. Add a "conformance" script '
                . '(php vendor/hideyukimori/nene2/tools/conformance.php --root=.) and include '
                . '"@conformance" in scripts.check so the linter is self-enforcing.',
            ),
        ];
    }

    private function invokesConformance(string $entry): bool
    {
        return $entry === '@conformance'
            || str_contains($entry, 'conformance.php');
    }
}
