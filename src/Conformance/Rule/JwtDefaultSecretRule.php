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

            if (preg_match(self::SECRET_PATTERN, $value) === 1) {
                $hits[] = [$token[2], $value];
            }
        }

        return $hits;
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
