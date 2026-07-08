<?php

declare(strict_types=1);

namespace Nene2\Conformance\Rule;

use Nene2\Conformance\Finding;
use Nene2\Conformance\ProjectFiles;
use Nene2\Conformance\RuleInterface;
use Nene2\Conformance\Severity;

/**
 * D1 — hard-coded JWT / signing default secret literals.
 *
 * The bearer-token HMAC secret authenticates every operator and service token,
 * so a predictable literal (`getenv('X') ?: 'acme-dev-secret'`) is a full
 * authentication bypass. The framework default is
 * {@see \Nene2\Auth\GuardedJwtSecretResolver}, which fails closed instead.
 *
 * Detection is token-based (not a raw grep): only real
 * `T_CONSTANT_ENCAPSED_STRING` literals under `src/` are inspected, so the same
 * text inside a comment or docblock does not trip the rule. The literal must
 * match a *development-secret shape* — `dev-secret`, `secret-key`, `changeme`,
 * `<slug>-secret`, etc. Env-variable **names** such as `NENE2_LOCAL_JWT_SECRET`
 * (upper snake case) are intentionally excluded, keeping false positives near
 * zero.
 *
 * Guarded-literal exemption (design 04 "+ GuardedJwtSecretResolver 不使用"):
 * the canonical fail-close pattern injects a product development secret **into**
 * {@see \Nene2\Auth\GuardedJwtSecretResolver}, either via
 * {@see \Nene2\Auth\GuardedJwtSecretResolver::fromConfig()} as its second
 * argument — e.g. `GuardedJwtSecretResolver::fromConfig($config,
 * self::DEFAULT_DEV_SECRET)` with `const DEFAULT_DEV_SECRET = '<slug>-dev-secret'`
 * — or by driving the constructor directly when a product uses a custom secret
 * env key (nene-serve), where the development secret is the `devSecret`
 * parameter: `new GuardedJwtSecretResolver(..., devSecret:
 * self::DEFAULT_DEV_SECRET, ...)` (named, or the fourth positional argument).
 * Such a literal is *not* a naked default secret — the resolver refuses it in
 * production (ADR 0013) — so a literal that is passed (directly, or via the
 * constant it initialises) into the resolver's `devSecret` slot in the same file
 * is exempt. The exemption is narrow on purpose: it only covers what actually
 * flows into the resolver — a truly naked secret (`getenv('X') ?:
 * 'acme-dev-secret'`) is never fed to it, so it stays flagged even in a file
 * that also uses `GuardedJwtSecretResolver` elsewhere.
 */
final class JwtDefaultSecretRule implements RuleInterface
{
    /**
     * Development-secret literal shapes: the concrete audit anti-patterns
     * (`*-dev-secret`, `changeme`, `secret-key`, ...). Kept high-signal on
     * purpose — it does NOT match upper snake-case env names
     * (`NENE2_LOCAL_JWT_SECRET`) nor prose like "the development-secret opt-in",
     * because those are excluded by the token/whitespace/key-position guards below.
     */
    // conformance:ignore D1 this literal is the detector's own pattern, not a secret value
    private const SECRET_PATTERN = '/(dev[-_]secret|secret[-_]key|change[-_]?me|changeme|please[-_]?change|insecure[-_]?secret|placeholder[-_]?secret)/i';

    public function id(): string
    {
        return 'D1';
    }

    public function description(): string
    {
        return 'No hard-coded JWT/default secret literals; use GuardedJwtSecretResolver (fail-closed).';
    }

    public function check(string $root): array
    {
        $findings = [];

        foreach (ProjectFiles::phpFilesUnder($root, 'src') as $absolute) {
            $code = @file_get_contents($absolute);

            if ($code === false) {
                continue;
            }

            $relative = ProjectFiles::relativePath($root, $absolute);

            foreach ($this->scan($code) as [$line, $literal]) {
                $findings[] = new Finding(
                    $this->id(),
                    Severity::Error,
                    $relative,
                    $line,
                    sprintf(
                        'Hard-coded secret literal %s. Resolve secrets via '
                        . 'Nene2\\Auth\\GuardedJwtSecretResolver (fail-closed), never a default literal.',
                        $this->quote($literal),
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array{int, string}> line number and matched literal value
     */
    private function scan(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t)
                || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $hits = [];
        $count = count($tokens);
        [$guardedLiteralIndices, $guardedConstNames] = $this->guardedDevSecrets($tokens, $count);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = $this->literalValue($token[1]);

            // A real secret is a single token: prose (which contains spaces) is not
            // a hard-coded secret, only a sentence that happens to mention one.
            if ($value === '' || preg_match('/\s/', $value) === 1) {
                continue;
            }

            // Exclude upper snake-case env-variable NAMES (NENE2_ALLOW_DEV_SECRET):
            // referencing the name of a secret var is fine; only literal values leak.
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1) {
                continue;
            }

            // Skip array-key / subscript positions (`['secret_key' => ...]`,
            // `$cfg['secret-key']`) — those are field names, not secret values.
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;

            if ($this->isToken($next, '=>') || ($this->isToken($prev, '[') && $this->isToken($next, ']'))) {
                continue;
            }

            if (preg_match(self::SECRET_PATTERN, $value) !== 1) {
                continue;
            }

            // Guarded-literal exemption: the literal is handed to the fail-closed
            // GuardedJwtSecretResolver (ADR 0013), so it is not a naked default.
            // Either the literal itself fills the resolver's `devSecret` slot
            // (`fromConfig()` second argument, or the constructor's `devSecret`
            // parameter), or it initialises the constant passed there in this file.
            if (isset($guardedLiteralIndices[$i])) {
                continue;
            }

            if ($this->isToken($prev, '=')
                && $this->isGuardedConstAssignment($tokens[$i - 2] ?? null, $guardedConstNames)
            ) {
                continue;
            }

            $hits[] = [$token[2], $value];
        }

        return $hits;
    }

    /**
     * Collects the development-secret literals that feed
     * `GuardedJwtSecretResolver`'s `devSecret` slot in this file — either
     * `GuardedJwtSecretResolver::fromConfig(..., <literal|CONST>)` (second
     * argument) or `new GuardedJwtSecretResolver(...)` (fourth positional
     * argument, or the named argument `devSecret:`).
     *
     * That parameter is the product-injected development secret, which the
     * resolver refuses in production — so those literals are not naked defaults
     * and must not trip D1. Returns two lookups: token indices of literals passed
     * directly, and the names of constants passed there (whose initialiser
     * literal is then exempt).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: array<int, true>, 1: array<string, true>}
     */
    private function guardedDevSecrets(array $tokens, int $count): array
    {
        $literalIndices = [];
        $constNames = [];

        for ($i = 0; $i < $count; $i++) {
            $call = $this->guardedResolverCallAt($tokens, $i);

            if ($call === null) {
                continue;
            }

            [$openParen, $positionalIndex] = $call;
            $arg = $this->devSecretArgumentTokens($tokens, $count, $openParen, $positionalIndex);

            if ($arg === null || $arg === []) {
                continue;
            }

            if (count($arg) === 1) {
                [$index, $only] = $arg[0];

                if (is_array($only) && $only[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literalIndices[$index] = true;
                    continue;
                }
            }

            // `self::DEFAULT_DEV_SECRET`, `Foo::DEFAULT_DEV_SECRET`, or a bare
            // `DEFAULT_DEV_SECRET`: the trailing identifier is the constant name.
            $last = $arg[count($arg) - 1][1];

            if (is_array($last) && $last[0] === T_STRING) {
                $constNames[$last[1]] = true;
            }
        }

        return [$literalIndices, $constNames];
    }

    /**
     * Recognises a `GuardedJwtSecretResolver` call carrying a `devSecret` slot at
     * token `$i`: `GuardedJwtSecretResolver::fromConfig(` (devSecret is the second
     * argument) or `new GuardedJwtSecretResolver(` (devSecret is the fourth
     * constructor argument). Returns the open-paren token index and the
     * zero-based positional index of `devSecret`, or null when `$i` is neither.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: int}|null
     */
    private function guardedResolverCallAt(array $tokens, int $i): ?array
    {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW
            && $this->isGuardedResolverName($tokens[$i + 1] ?? null)
            && $this->isToken($tokens[$i + 2] ?? null, '(')
        ) {
            return [$i + 2, 3];
        }

        if (!$this->isGuardedResolverName($tokens[$i])) {
            return null;
        }

        $colon = $tokens[$i + 1] ?? null;
        $method = $tokens[$i + 2] ?? null;

        if (!is_array($colon) || $colon[0] !== T_DOUBLE_COLON) {
            return null;
        }

        if (!is_array($method) || $method[0] !== T_STRING || $method[1] !== 'fromConfig') {
            return null;
        }

        if (!$this->isToken($tokens[$i + 3] ?? null, '(')) {
            return null;
        }

        return [$i + 3, 1];
    }

    /**
     * Top-level tokens of the `devSecret` argument of the call whose `(` is at
     * `$openParenIndex`: the named argument `devSecret:` when present, otherwise
     * the unnamed argument at `$positionalIndex` (PHP only allows positional
     * arguments before named ones). Returns null when the call passes neither.
     * Commas nested inside `()`/`[]`/`{}` do not separate arguments.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: array{0: int, 1: string, 2: int}|string}>|null
     */
    private function devSecretArgumentTokens(
        array $tokens,
        int $count,
        int $openParenIndex,
        int $positionalIndex,
    ): ?array {
        $depth = 0;
        $argIndex = 0;
        $current = [];

        for ($j = $openParenIndex + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_string($token)) {
                if ($token === '(' || $token === '[' || $token === '{') {
                    $depth++;
                    $current[] = [$j, $token];
                    continue;
                }

                if ($token === ')' || $token === ']' || $token === '}') {
                    if ($depth === 0) {
                        return $this->devSecretFromArgument($current, $argIndex, $positionalIndex);
                    }

                    $depth--;
                    $current[] = [$j, $token];
                    continue;
                }

                if ($token === ',' && $depth === 0) {
                    $match = $this->devSecretFromArgument($current, $argIndex, $positionalIndex);

                    if ($match !== null) {
                        return $match;
                    }

                    $argIndex++;
                    $current = [];
                    continue;
                }
            }

            $current[] = [$j, $token];
        }

        return null;
    }

    /**
     * The value tokens of one call argument when it fills the `devSecret` slot:
     * either it is labelled `devSecret:` (label tokens stripped), or it is
     * unlabelled and sits at `$positionalIndex`. Null otherwise.
     *
     * @param list<array{0: int, 1: array{0: int, 1: string, 2: int}|string}> $argument
     * @return list<array{0: int, 1: array{0: int, 1: string, 2: int}|string}>|null
     */
    private function devSecretFromArgument(array $argument, int $argIndex, int $positionalIndex): ?array
    {
        $label = null;

        // A named argument starts with `<identifier> :` (T_DOUBLE_COLON is a
        // distinct token, so `self::CONST` cannot masquerade as a label).
        if (count($argument) >= 2
            && is_array($argument[0][1]) && $argument[0][1][0] === T_STRING
            && $this->isToken($argument[1][1], ':')
        ) {
            $label = $argument[0][1][1];
            $argument = array_slice($argument, 2);
        }

        if ($label !== null) {
            return $label === 'devSecret' ? $argument : null;
        }

        return $argIndex === $positionalIndex ? $argument : null;
    }

    /**
     * Whether the token is a reference to `GuardedJwtSecretResolver`, imported or
     * written as a (fully) qualified name.
     *
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private function isGuardedResolverName(array|string|null $token): bool
    {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $position = strrpos($token[1], '\\');
        $base = $position === false ? $token[1] : substr($token[1], $position + 1);

        return $base === 'GuardedJwtSecretResolver';
    }

    /**
     * Whether the token is a constant identifier that names a guarded dev secret.
     *
     * @param array{0: int, 1: string, 2: int}|string|null $token
     * @param array<string, true> $constNames
     */
    private function isGuardedConstAssignment(array|string|null $token, array $constNames): bool
    {
        return is_array($token) && $token[0] === T_STRING && isset($constNames[$token[1]]);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private function isToken(array|string|null $token, string $symbol): bool
    {
        return is_string($token) && $token === $symbol;
    }

    /**
     * Strips the surrounding quotes from a `T_CONSTANT_ENCAPSED_STRING` lexeme.
     */
    private function literalValue(string $lexeme): string
    {
        if (strlen($lexeme) >= 2) {
            return substr($lexeme, 1, -1);
        }

        return $lexeme;
    }

    private function quote(string $value): string
    {
        $shown = strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value;

        return "'" . $shown . "'";
    }
}
