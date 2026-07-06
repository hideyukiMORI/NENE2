<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * D2 — Composer dependency pinned to a feature branch.
 *
 * A constraint like `dev-feat/new-thing` (or a lock entry resolved to one) ties a
 * production build to an unmerged, mutable branch — a supply-chain hazard: the
 * ref can be force-pushed or deleted and the build is not reproducible. Releases
 * must depend on tags (`^1.7`) or, at worst, the mainline (`dev-main`).
 *
 * Scope is deliberately narrow to stay false-positive-free at `error` severity:
 * only *feature-branch* shapes are flagged — a `dev-` constraint that either
 * contains a slash (`dev-feat/x`, `dev-fix/y`) or starts with a known
 * feature-branch prefix. Plain `dev-main` / `@dev` / path & symlink dev setups
 * are the design's `warn` territory and are NOT flagged here.
 */
final class DependencyBranchPinRule implements RuleInterface
{
    private const FEATURE_PREFIXES = [
        'feat', 'feature', 'fix', 'bugfix', 'hotfix',
        'chore', 'wip', 'tmp', 'temp', 'refactor', 'test', 'exp',
    ];

    public function id(): string
    {
        return 'D2';
    }

    public function description(): string
    {
        return 'No Composer dependency pinned to a feature branch (dev-feat/...); depend on tags or mainline.';
    }

    public function check(string $root): array
    {
        $findings = [];

        $findings = array_merge($findings, $this->scanComposerJson($root . '/composer.json'));
        $findings = array_merge($findings, $this->scanComposerLock($root . '/composer.lock'));

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function scanComposerJson(string $path): array
    {
        $data = $this->readJson($path);

        if ($data === null) {
            return [];
        }

        $findings = [];

        foreach (['require', 'require-dev'] as $section) {
            $deps = $data[$section] ?? null;

            if (!is_array($deps)) {
                continue;
            }

            foreach ($deps as $package => $constraint) {
                if (!is_string($package) || !is_string($constraint)) {
                    continue;
                }

                $branch = $this->featureBranch($constraint);

                if ($branch !== null) {
                    $findings[] = new Finding(
                        $this->id(),
                        Severity::Error,
                        'composer.json',
                        0,
                        sprintf(
                            'Dependency %s is pinned to feature branch "%s" in %s. '
                            . 'Depend on a released tag (e.g. ^1.7) or the mainline before shipping.',
                            $package,
                            $branch,
                            $section,
                        ),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function scanComposerLock(string $path): array
    {
        $data = $this->readJson($path);

        if ($data === null) {
            return [];
        }

        $findings = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;

                if (!is_string($name) || !is_string($version)) {
                    continue;
                }

                $branch = $this->featureBranch($version);

                if ($branch !== null) {
                    $findings[] = new Finding(
                        $this->id(),
                        Severity::Error,
                        'composer.lock',
                        0,
                        sprintf(
                            'Locked dependency %s is resolved to feature branch "%s" in %s. '
                            . 'Re-lock against a released tag or the mainline before shipping.',
                            $name,
                            $branch,
                            $section,
                        ),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Returns the feature-branch name if `$constraint` pins one, else null.
     *
     * Handles inline aliases (`dev-feat/x as 1.0.x-dev`) by inspecting each term.
     */
    private function featureBranch(string $constraint): ?string
    {
        foreach (preg_split('/\s+as\s+|\s*\|\s*/', trim($constraint)) ?: [] as $term) {
            $term = trim($term);

            if (!str_starts_with($term, 'dev-')) {
                continue;
            }

            $branch = substr($term, 4);

            if (str_contains($branch, '/')) {
                return $branch;
            }

            $prefix = strtolower((string) strtok($branch, '-'));

            if (in_array($prefix, self::FEATURE_PREFIXES, true)) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
