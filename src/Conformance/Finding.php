<?php

declare(strict_types=1);

namespace Nene2\Conformance;

/**
 * A single conformance drift, reported by a {@see RuleInterface}.
 *
 * The triple `(ruleId, file, message)` is the stable identity used for baseline
 * matching (see {@see Baseline}), deliberately excluding {@see $line} so a
 * baselined finding survives unrelated edits that shift line numbers — the same
 * strategy PHPStan's baseline uses.
 *
 * `file` is always a repo-root-relative POSIX path (e.g. `src/Foo/Bar.php`) so
 * findings and baseline entries are portable across checkouts.
 */
final readonly class Finding
{
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $file,
        public int $line,
        public string $message,
    ) {
    }

    /**
     * Stable identity for baseline/allowlist matching (line excluded on purpose).
     */
    public function identity(): string
    {
        return $this->ruleId . "\0" . $this->file . "\0" . $this->message;
    }

    /**
     * @return array{rule: string, severity: string, file: string, line: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->ruleId,
            'severity' => $this->severity->value,
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
        ];
    }
}
