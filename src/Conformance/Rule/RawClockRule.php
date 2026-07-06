<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\ProjectFiles;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * D4 — raw current-time reads instead of an injected clock.
 *
 * Direct `time()` / `microtime()` / single-argument `date()` / `gmdate()` and
 * `new DateTime[Immutable]('now')` couple business code to the wall clock and
 * make time-boundary behaviour (`exp`/`nbf`, expiry windows) non-deterministic
 * to test. The framework abstraction is {@see \Nene2\Http\ClockInterface}
 * (production {@see \Nene2\Http\UtcClock}).
 *
 * Detection is token-based, so the rule never trips on the words `time()` or
 * `date()` inside a comment or docblock. To keep false positives low it excludes,
 * by construction:
 *   - files that *implement* `ClockInterface` (they are the sanctioned clock);
 *   - `date()`/`gmdate()` called with an explicit timestamp argument (that is
 *     display formatting of a known instant, not a clock read);
 *   - method calls (`$x->time()`, `Foo::date()`) — only global calls match.
 * `tests/` and `database/` are out of scope because only `src/` is scanned.
 */
final class RawClockRule implements RuleInterface
{
    /** Global functions whose bare call always reads "now". */
    private const NOW_FUNCTIONS = ['time', 'microtime', 'mktime', 'gmmktime'];

    /** Formatting functions that read "now" only when given no explicit timestamp. */
    private const FORMAT_FUNCTIONS = ['date', 'gmdate'];

    private const DATETIME_CLASSES = ['datetime', 'datetimeimmutable'];

    public function id(): string
    {
        return 'D4';
    }

    public function description(): string
    {
        return 'No raw time()/date()/new DateTime(\'now\'); read the current time via Nene2\\Http\\ClockInterface.';
    }

    public function check(string $root): array
    {
        $findings = [];

        foreach (ProjectFiles::phpFilesUnder($root, 'src') as $absolute) {
            $code = @file_get_contents($absolute);

            if ($code === false || $this->isClockImplementation($code)) {
                continue;
            }

            $relative = ProjectFiles::relativePath($root, $absolute);

            foreach ($this->scan($code) as [$line, $what]) {
                $findings[] = new Finding(
                    $this->id(),
                    Severity::Error,
                    $relative,
                    $line,
                    sprintf(
                        'Raw current-time read %s. Inject Nene2\\Http\\ClockInterface '
                        . '(Nene2\\Http\\UtcClock in production) instead of reading the wall clock directly.',
                        $what,
                    ),
                );
            }
        }

        return $findings;
    }

    private function isClockImplementation(string $code): bool
    {
        return preg_match('/implements\s+[^\{]*\bClockInterface\b/', $code) === 1;
    }

    /**
     * @return list<array{int, string}> line number and offending construct label
     */
    private function scan(string $code): array
    {
        $tokens = $this->meaningfulTokens($code);
        $hits = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Global function call: an unqualified (T_STRING) or fully-qualified
            // (\time) name immediately followed by `(`, not a method/static call.
            $name = $this->globalCallName($token);

            if ($name !== null) {
                $prev = $tokens[$i - 1]['text'] ?? '';

                if ($prev === '->' || $prev === '::' || $prev === 'function') {
                    continue;
                }

                if (($tokens[$i + 1]['text'] ?? '') !== '(') {
                    continue;
                }

                if (in_array($name, self::NOW_FUNCTIONS, true)) {
                    $hits[] = [$token['line'], $name . '()'];
                    continue;
                }

                if (in_array($name, self::FORMAT_FUNCTIONS, true)) {
                    // Only a single-arg call reads "now"; 2+ args format a given timestamp.
                    if ($this->argumentCount($tokens, $i + 1) < 2) {
                        $hits[] = [$token['line'], $name . '() with no explicit timestamp'];
                    }
                }

                continue;
            }

            if ($token['id'] === T_NEW) {
                $classToken = $tokens[$i + 1] ?? null;
                $class = $classToken !== null ? $this->classBasename($classToken) : null;

                if ($class === null || !in_array($class, self::DATETIME_CLASSES, true)) {
                    continue;
                }

                if ($this->newDateTimeReadsNow($tokens, $i + 1)) {
                    $hits[] = [$classToken['line'], "new {$class}('now')"];
                }
            }
        }

        return $hits;
    }

    /**
     * Lowercased name of a global function call target, or null if the token is
     * not a callable name. Accepts unqualified (`time`) and fully-qualified
     * (`\time`) names; namespaced names (`App\time`) are not global builtins.
     *
     * @param array{id: int|null, text: string, line: int} $token
     */
    private function globalCallName(array $token): ?string
    {
        if ($token['id'] === T_STRING) {
            return strtolower($token['text']);
        }

        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
            return strtolower(ltrim($token['text'], '\\'));
        }

        return null;
    }

    /**
     * Lowercased last segment of a class name token after `new`, or null.
     *
     * @param array{id: int|null, text: string, line: int} $token
     */
    private function classBasename(array $token): ?string
    {
        if (!in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            return null;
        }

        $parts = explode('\\', $token['text']);

        return strtolower((string) end($parts));
    }

    /**
     * True when `new DateTime[Immutable]` resolves to the current instant:
     * no constructor arguments, or a first string argument containing "now".
     *
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private function newDateTimeReadsNow(array $tokens, int $classIndex): bool
    {
        $paren = $classIndex + 1;

        // `new DateTimeImmutable` with no parentheses defaults to now.
        if (($tokens[$paren]['text'] ?? '') !== '(') {
            return true;
        }

        $first = $tokens[$paren + 1] ?? null;

        // Empty argument list: `new DateTimeImmutable()` → now.
        if ($first !== null && $first['text'] === ')') {
            return true;
        }

        if ($first !== null && $first['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return stripos($first['text'], 'now') !== false;
        }

        return false;
    }

    /**
     * Counts top-level arguments of a call whose opening `(` is at `$openIndex`.
     *
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private function argumentCount(array $tokens, int $openIndex): int
    {
        $depth = 0;
        $count = 0;
        $sawToken = false;
        $total = count($tokens);

        for ($i = $openIndex; $i < $total; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '(' || $text === '[') {
                $depth++;

                if ($depth > 1) {
                    $sawToken = true;
                }

                continue;
            }

            if ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    return $sawToken ? $count + 1 : 0;
                }

                continue;
            }

            if ($depth === 1) {
                if ($text === ',') {
                    $count++;
                } else {
                    $sawToken = true;
                }
            }
        }

        return $sawToken ? $count + 1 : 0;
    }

    /**
     * Normalises `token_get_all` output to meaningful tokens (no whitespace or
     * comments), each carrying an id (null for single-char tokens), text and line.
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private function meaningfulTokens(string $code): array
    {
        $result = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $result[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];

                continue;
            }

            $result[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $result;
    }
}
