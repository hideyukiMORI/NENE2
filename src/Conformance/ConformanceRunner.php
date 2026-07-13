<?php

declare(strict_types=1);

namespace Nene2\Conformance;

use Nene2\Conformance\Rule\CheckSelfRegistrationRule;
use Nene2\Conformance\Rule\DependencyBranchPinRule;
use Nene2\Conformance\Rule\JwtDefaultSecretRule;
use Nene2\Conformance\Rule\RawClockRule;
use Nene2\Conformance\Rule\ReadmeLicenseSectionRule;
use Nene2\Conformance\Rule\ReadmePrivateOriginLinkRule;
use Nene2\Conformance\Rule\ReadmeStaticStatusBadgeRule;

/**
 * Orchestrates the conformance rules over a project tree and applies the three
 * suppression mechanisms (inline ignore, allowlist, baseline) to the raw
 * findings.
 *
 * The runner is pure with respect to the filesystem it inspects (it only reads),
 * which keeps it unit-testable against fixture directories.
 */
final class ConformanceRunner
{
    public const BASELINE_FILENAME = 'conformance.baseline.json';
    public const BASELINE_VERSION = 1;

    /** @var list<RuleInterface> */
    private array $rules;

    /**
     * @param list<RuleInterface> $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * The current default rule set: the `error`-tier D1–D4 rules (design doc 04,
     * layer error) plus the R1–R3 README/docs-conformance rules (R1 `error`,
     * R2/R3 `warn` — see ADR 0016's R-series addendum). R-series ids are a
     * separate sequence from D1–D4 because design doc 04 reserves D5–D12 for
     * backend-only drift (getenv, Installer/Pagination reinvention, ...);
     * README/docs drift is a distinct axis, not a continuation of that reserved
     * range.
     */
    public static function withDefaultRules(): self
    {
        return new self([
            new JwtDefaultSecretRule(),
            new DependencyBranchPinRule(),
            new CheckSelfRegistrationRule(),
            new RawClockRule(),
            new ReadmeStaticStatusBadgeRule(),
            new ReadmeLicenseSectionRule(),
            new ReadmePrivateOriginLinkRule(),
        ]);
    }

    /**
     * @return list<RuleInterface>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Runs every rule and applies inline ignores + the baseline.
     */
    public function run(string $root, Baseline $baseline): RunResult
    {
        $active = [];
        $suppressed = 0;

        foreach ($this->rawFindings($root) as $finding) {
            if ($this->inlineIgnored($root, $finding) || $baseline->suppresses($finding)) {
                $suppressed++;
                continue;
            }

            $active[] = $finding;
        }

        return new RunResult($active, $suppressed);
    }

    /**
     * Builds a fresh baseline snapshot (PHPStan-shaped `ignore` entries) from the
     * current findings, preserving any existing `allow` allowlist.
     *
     * @return array{version: int, allow: list<array<string, mixed>>, ignore: list<array{rule: string, file: string, message: string, count: int}>}
     */
    public function buildBaseline(string $root, Baseline $existing): array
    {
        $counts = [];
        $meta = [];

        foreach ($this->rawFindings($root) as $finding) {
            if ($this->inlineIgnored($root, $finding)) {
                continue;
            }

            $key = $finding->identity();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $meta[$key] = [
                'rule' => $finding->ruleId,
                'file' => $finding->file,
                'message' => $finding->message,
            ];
        }

        $ignore = [];

        foreach ($counts as $key => $count) {
            $ignore[] = [
                'rule' => $meta[$key]['rule'],
                'file' => $meta[$key]['file'],
                'message' => $meta[$key]['message'],
                'count' => $count,
            ];
        }

        return [
            'version' => self::BASELINE_VERSION,
            'allow' => $existing->allowEntries(),
            'ignore' => $ignore,
        ];
    }

    /**
     * @return list<Finding>
     */
    private function rawFindings(string $root): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->check($root) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * True when the finding's source line (or the line above it) carries a
     * `// conformance:ignore <ruleId>` directive.
     */
    private function inlineIgnored(string $root, Finding $finding): bool
    {
        if ($finding->line <= 0) {
            return false;
        }

        $lines = $this->fileLines($root . '/' . $finding->file);

        foreach ([$finding->line, $finding->line - 1] as $lineNo) {
            $text = $lines[$lineNo - 1] ?? null;

            if ($text !== null && preg_match('/conformance:ignore\s+' . preg_quote($finding->ruleId, '/') . '\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function fileLines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        return explode("\n", $raw);
    }
}
